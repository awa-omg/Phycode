<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Browser;

use Phycode\Browser\Cdp;
use Phycode\Browser\WebSocket;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use PHPUnit\Framework\TestCase;

final class CdpTest extends TestCase
{
    private $peer;
    private Cdp $cdp;
    private array $events = [];

    protected function setUp(): void
    {
        [$client, $this->peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->cdp = new Cdp(WebSocket::over($client));
        $this->cdp->listen(function (string $method, array $params, ?string $session): void {
            $this->events[] = [$method, $params, $session];
        });
    }

    protected function tearDown(): void
    {
        fclose($this->peer);
    }

    private function say(array ...$messages): void
    {
        foreach ($messages as $message) {
            fwrite($this->peer, Frames::server(0x1, json_encode($message, JSON_THROW_ON_ERROR)));
        }
    }

    private function sent(): array
    {
        return json_decode(Frames::client($this->peer)['payload'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testACallSendsACommandAndReturnsItsResult(): void
    {
        $this->say(['id' => 1, 'result' => ['targetId' => 'T1']]);

        $result = $this->cdp->call('Target.createTarget', ['url' => 'about:blank']);

        self::assertSame(['targetId' => 'T1'], $result);
        self::assertSame(['id' => 1, 'method' => 'Target.createTarget', 'params' => ['url' => 'about:blank']], $this->sent());
    }

    public function testNoParametersAreSentWhenThereAreNoneAndTheSessionIsNamedWhenThereIsOne(): void
    {
        $this->say(['id' => 1, 'result' => []], ['id' => 2, 'result' => []]);

        $this->cdp->call('Page.enable', [], 'S1');
        $this->cdp->call('Browser.getVersion');

        self::assertSame(['id' => 1, 'method' => 'Page.enable', 'sessionId' => 'S1'], $this->sent());
        self::assertSame(['id' => 2, 'method' => 'Browser.getVersion'], $this->sent());
    }

    public function testEventsThatComeWhileWaitingAreDeliveredInOrder(): void
    {
        $this->say(
            ['method' => 'Target.attachedToTarget', 'params' => ['sessionId' => 'S1']],
            ['method' => 'Page.loadEventFired', 'params' => ['timestamp' => 1], 'sessionId' => 'S1'],
            ['id' => 1, 'result' => ['ok' => true]],
        );

        $result = $this->cdp->call('Page.enable');

        self::assertSame(['ok' => true], $result);
        self::assertSame([['Target.attachedToTarget', ['sessionId' => 'S1'], null], ['Page.loadEventFired', ['timestamp' => 1], 'S1']], $this->events);
    }

    public function testAnAnswerToAnotherCommandIsNotTakenForTheAwaitedOne(): void
    {
        $this->say(['id' => 7, 'result' => ['stale' => true]], ['id' => 1, 'result' => ['right' => true]]);

        self::assertSame(['right' => true], $this->cdp->call('Page.enable'));
    }

    public function testAnErrorAnswerBecomesAnExceptionWithTheBrowsersWords(): void
    {
        $this->say(['id' => 1, 'error' => ['code' => -32000, 'message' => 'No target with given id found']]);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('the browser refused Target.closeTarget: No target with given id found');

        $this->cdp->call('Target.closeTarget', ['targetId' => 'gone']);
    }

    public function testNoAnswerInTimeIsAnError(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('the browser did not answer Page.navigate within 0.2s');

        $this->cdp->call('Page.navigate', [], null, 0.2);
    }

    public function testASendCanAskToBeToldTheAnswerLater(): void
    {
        $answers = [];
        $reply = static function (array $result, ?string $error) use (&$answers): void {
            $answers[] = [$result, $error];
        };
        $this->cdp->send('Fetch.enable', [], 'S1', $reply);
        $this->cdp->send('Fetch.failRequest', ['requestId' => 'R'], 'S1', $reply);
        $this->cdp->send('Runtime.runIfWaitingForDebugger', [], 'S1');
        $this->say(['id' => 1, 'result' => []], ['id' => 2, 'error' => ['message' => 'Invalid InterceptionId']], ['id' => 3, 'result' => []]);

        $this->cdp->pump(0.3);

        self::assertSame([[[], null], [[], 'Invalid InterceptionId']], $answers);
    }

    public function testWaitForStopsWhenTheConditionHoldsAndGivesUpAtTheDeadline(): void
    {
        $this->say(['method' => 'Page.lifecycleEvent', 'params' => ['name' => 'load']]);
        $seen = false;
        $this->cdp->listen(static function (string $method) use (&$seen): void {
            $seen = $seen || $method === 'Page.lifecycleEvent';
        });

        self::assertTrue($this->cdp->waitFor(static function () use (&$seen): bool {
            return $seen;
        }, 1.0));
        $started = microtime(true);
        self::assertFalse($this->cdp->waitFor(static fn (): bool => false, 0.15));
        self::assertGreaterThanOrEqual(0.14, microtime(true) - $started);
    }

    public function testACancelledRunStopsTheWait(): void
    {
        $cancel = new Cancellation();
        $this->cdp->cancellation = $cancel;
        $cancel->cancel();

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('cancelled');

        $this->cdp->call('Page.navigate');
    }

    public function testSomethingThatIsNotAJsonObjectIsAProtocolError(): void
    {
        fwrite($this->peer, Frames::server(0x1, '[1,2]'));

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('not a JSON object');

        $this->cdp->pump(0.5);
    }

    public function testMessagesWithoutAMethodOrAnIdAreIgnored(): void
    {
        $this->say(['hello' => 'world'], ['method' => 'Page.loadEventFired', 'params' => []]);

        $this->cdp->pump(0.2);

        self::assertSame([['Page.loadEventFired', [], null]], $this->events);
    }

    public function testClosingClosesTheConnection(): void
    {
        $this->cdp->close();

        self::assertFalse($this->cdp->isOpen());
    }
}
