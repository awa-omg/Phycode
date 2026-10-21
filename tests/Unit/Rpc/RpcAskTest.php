<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Rpc\JsonLines;
use Phycode\Rpc\RpcUi;
use Phycode\Security\ConfirmRequest;
use Phycode\Security\Decision;
use Phycode\Support\Cancellation;
use Phycode\Tools\ToolCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RpcAskTest extends TestCase
{
    private $output;
    private $terminal;
    private RpcUi $ui;
    private int $cancellations = 0;

    protected function tearDown(): void
    {
        Cancellation::setPoll(null);
    }

    public static function replies(): iterable
    {
        yield 'an option' => ['yes', ['yes', 'no'], false, 'yes'];
        yield 'an option with spaces around it' => [' no ', ['yes', 'no'], false, 'no'];
        yield 'a text that is not an option' => ['maybe', ['yes', 'no'], false, null];
        yield 'free text when it is allowed' => ['maybe', ['yes', 'no'], true, 'maybe'];
        yield 'nothing but spaces' => ['   ', [], true, null];
        yield 'no answer' => [null, ['yes'], false, null];
        yield 'a number is not an answer' => [3, ['yes'], true, null];
    }

    #[DataProvider('replies')]
    public function testTheReplyIsTheAnswerWhenItIsAnOptionOrAllowedFreeText(mixed $reply, array $options, bool $free, ?string $expected): void
    {
        $this->connect();
        $this->command('ask_reply', ['id' => 'a1', 'answer' => $reply]);
        $this->ui->beginTurn();

        self::assertSame($expected, $this->ui->ask('Continue?', $options, $free));
        self::assertSame(0, $this->cancellations);
    }

    public function testTheQuestionIsSentWithItsOptionsAndWaitsForItsReply(): void
    {
        $this->connect();
        $this->command('ask_reply', ['id' => 'a1', 'answer' => 'no']);
        $this->ui->beginTurn();

        self::assertSame('no', $this->ui->ask('Overwrite the file?', ['yes', 'no'], false));

        self::assertSame([
            ['type' => 'turn_start'],
            ['type' => 'ask', 'id' => 'a1', 'question' => 'Overwrite the file?', 'options' => ['yes', 'no'], 'allowFreeText' => false],
        ], $this->sent());
    }

    public function testEachQuestionGetsItsOwnId(): void
    {
        $this->connect();
        $this->command('ask_reply', ['id' => 'a1', 'answer' => 'yes']);
        $this->command('ask_reply', ['id' => 'a2', 'answer' => 'blue']);
        $this->ui->beginTurn();

        self::assertSame('yes', $this->ui->ask('First?', ['yes'], false));
        self::assertSame('blue', $this->ui->ask('Colour?', [], true));
        self::assertSame(['a1', 'a2'], array_column(array_values(array_filter($this->sent(), static fn (array $event): bool => $event['type'] === 'ask')), 'id'));
    }

    public function testAReplyWithAnUnknownIdIsIgnoredAndTheOneWithTheRightIdIsTaken(): void
    {
        $this->connect();
        $this->command('ask_reply', ['id' => 'a9', 'answer' => 'no']);
        $this->command('ask_reply', ['id' => 'a1', 'answer' => 'yes']);
        $this->ui->beginTurn();

        self::assertSame('yes', $this->ui->ask('Continue?', ['yes', 'no'], false));
        self::assertSame(0, $this->cancellations);
    }

    public function testAnInterruptWhileTheQuestionWaitsGivesNoAnswerAndCancelsTheTurn(): void
    {
        $this->connect();
        $this->command('interrupt');
        $this->ui->beginTurn();

        self::assertNull($this->ui->ask('Continue?', ['yes'], false));
        self::assertSame(1, $this->cancellations);
        self::assertTrue($this->ui->wasCancelled());
        self::assertFalse($this->ui->quitRequested());
    }

    public function testTheInputEndingWhileTheQuestionWaitsGivesNoAnswer(): void
    {
        $this->connect();
        $this->ui->beginTurn();
        fclose($this->terminal);

        self::assertNull($this->ui->ask('Continue?', ['yes'], false));
        self::assertSame(1, $this->cancellations);
    }

    public function testAQuitWhileTheQuestionWaitsGivesNoAnswerAndIsRemembered(): void
    {
        $this->connect();
        $this->command('quit');
        $this->ui->beginTurn();

        self::assertNull($this->ui->ask('Continue?', ['yes'], false));
        self::assertTrue($this->ui->quitRequested());
        self::assertSame(1, $this->cancellations);
    }

    public function testAQuestionAfterTheTurnWasCancelledIsNotAskedAtAll(): void
    {
        $this->connect();
        $this->ui->beginTurn();
        $this->ui->interrupt();

        self::assertNull($this->ui->ask('Continue?', ['yes'], false));
        self::assertSame([['type' => 'turn_start']], $this->sent());
    }

    public function testTheServerSweepsWhileAQuestionWaits(): void
    {
        $this->connect();
        $this->ui->attachServer(function (): void {
            $this->command('ask_reply', ['id' => 'a1', 'answer' => 'later']);
        }, static fn (): bool => false);
        $this->ui->beginTurn();

        self::assertSame('later', $this->ui->ask('Continue?', [], true));
    }

    public function testANoticeRaisedWhileAnApprovalWaitsDoesNotTakeItsReply(): void
    {
        $this->connect();
        $calls = 0;
        $this->ui->attachServer(function () use (&$calls): void {
            if (++$calls > 3) {
                throw new \RuntimeException('the approval never got its reply');
            }
            $this->command('confirm_reply', ['id' => 'c1', 'decision' => 'once']);
            $this->ui->notice('error', 'btw failed: boom');
        }, static fn (): bool => false);
        $this->ui->beginTurn();

        self::assertSame(Decision::AllowOnce, $this->ui->confirm(new ConfirmRequest('bash', ToolCategory::Exec, 'build', 0, 'ls')));
        self::assertContains(['type' => 'notice', 'level' => 'error', 'message' => 'btw failed: boom'], $this->sent());
    }

    private function connect(): void
    {
        [$reader, $this->terminal] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->output = fopen('php://temp', 'r+');
        $this->ui = new RpcUi(new JsonLines($reader, $this->output));
        $this->ui->attach(static fn (): string => 'build', function (): void {
            $this->cancellations++;
        });
        $this->ui->start(['type' => 'ready', 'protocol' => 2]);
    }

    private function command(string $type, array $fields = []): void
    {
        fwrite($this->terminal, json_encode(['type' => $type] + $fields, JSON_THROW_ON_ERROR) . "\n");
    }

    private function sent(): array
    {
        rewind($this->output);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($this->output)), static fn (string $line): bool => $line !== ''));
        $events = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
        self::assertSame('ready', array_shift($events)['type'] ?? null);

        return $events;
    }
}
