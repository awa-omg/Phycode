<?php

declare(strict_types=1);

namespace Phycode\Tests\Integration;

use Phycode\Tests\Support\FakeModel;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RpcProcess;
use PHPUnit\Framework\TestCase;

final class RpcTurnTest extends TestCase
{
    private string $home;
    private string $cwd;
    private ?FakeModel $model = null;
    private ?RpcProcess $rpc = null;

    protected function setUp(): void
    {
        $this->home = Kit::folder('rpc-turn-home');
        $this->cwd = Kit::folder('rpc-turn-cwd');
    }

    protected function tearDown(): void
    {
        $this->rpc?->close();
        $this->model?->stop();
    }

    public function testAnInterruptWhileTheModelIsSilentEndsTheTurnAtOnce(): void
    {
        $this->start([['content' => 'never shown', 'delay' => 60.0]], 'text');
        $this->rpc->send(['type' => 'input', 'text' => 'write a long essay']);
        $this->rpc->until('turn_start', 20.0);
        usleep(500000);

        $sent = microtime(true);
        $this->rpc->send(['type' => 'interrupt']);
        $events = $this->rpc->until('turn_end', 5.0);

        self::assertNotNull($events, 'the turn did not end after the interrupt');
        self::assertLessThan(2.0, microtime(true) - $sent, 'the interrupt waited for the model');
        self::assertSame('cancelled', $this->last($events)['status']);
        $this->quit();
    }

    public function testAnInterruptStopsAnApprovedCommandAndTheProcessesItStarted(): void
    {
        $this->start([
            ['content' => self::toolCall('bash', ['command' => 'sleep 78.5'])],
            ['content' => 'the command was stopped'],
        ], 'text');
        $this->rpc->send(['type' => 'input', 'text' => 'run a long sleep']);
        $asked = $this->rpc->until('confirm', 20.0);
        self::assertNotNull($asked, 'the command was not offered for approval');
        $confirm = $this->last($asked);
        self::assertSame('bash', $confirm['tool']);
        $this->rpc->send(['type' => 'confirm_reply', 'id' => $confirm['id'], 'decision' => 'once']);
        usleep(500000);

        $sent = microtime(true);
        $this->rpc->send(['type' => 'interrupt']);
        $events = $this->rpc->until('turn_end', 5.0);

        self::assertNotNull($events, 'the turn did not end after the interrupt');
        self::assertLessThan(2.0, microtime(true) - $sent, 'the interrupt waited for the command');
        self::assertSame('cancelled', $this->last($events)['status']);
        self::assertFalse(Kit::sleeping('78.5'), 'the sleep is still running');
        $this->quit();
    }

    public function testAWriteOutsideTheProjectFailsWithoutAnApprovalRequest(): void
    {
        $outside = Kit::folder('outside') . '/note.txt';
        $this->start([
            ['content' => self::toolCall('write', ['path' => $outside, 'content' => "x\n"])],
            ['content' => 'the write failed'],
        ], 'text');
        $this->rpc->send(['type' => 'input', 'text' => 'write a note outside the project']);
        $events = $this->rpc->until('turn_end', 20.0);

        self::assertNotNull($events, 'the turn did not end');
        self::assertSame([], array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'confirm')));
        $toolEnds = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'tool_end'));
        self::assertCount(1, $toolEnds);
        self::assertFalse($toolEnds[0]['ok']);
        self::assertStringStartsWith('path is outside the project', $toolEnds[0]['summary']);
        self::assertFileDoesNotExist($outside);
        $this->quit();
    }

    public function testAChangeMadeAgainInALaterTurnIsReportedForThatTurn(): void
    {
        $this->start([
            ['content' => self::toolCall('write', ['path' => 'a.txt', 'content' => "first\n"])],
            ['content' => 'saved'],
            ['content' => self::toolCall('write', ['path' => 'a.txt', 'content' => "second\n"])],
            ['content' => 'saved again'],
        ], 'text', 'yolo');

        $this->rpc->send(['type' => 'input', 'text' => 'create a.txt']);
        $first = $this->rpc->until('turn_end', 20.0);
        $this->rpc->send(['type' => 'input', 'text' => 'change a.txt again']);
        $second = $this->rpc->until('turn_end', 20.0);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(['a.txt'], $this->last($first)['changes']);
        self::assertSame(['a.txt'], $this->last($second)['changes']);
        $this->quit();
    }

    public function testAReplyCutByTheTokenLimitEndsTheTurnWithANotice(): void
    {
        $this->start([['content' => 'The three primary colours are', 'finish' => 'length']], 'text');
        $this->rpc->send(['type' => 'input', 'text' => 'name the three primary colours']);
        $events = $this->rpc->until('turn_end', 20.0);

        self::assertNotNull($events, 'the turn did not end');
        $notices = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'notice'));
        self::assertCount(1, $notices);
        self::assertSame(['warn', 'the reply was cut off by the token limit (max_output_tokens)'], [$notices[0]['level'], $notices[0]['message']]);
        self::assertSame('completed', $this->last($events)['status']);
        $this->quit();
    }

    private function start(array $replies, string $toolMode, string $permissionMode = 'ask'): void
    {
        $this->model = new FakeModel($replies);
        RpcProcess::configure($this->home, $this->model->url(), $toolMode, $permissionMode);
        $this->rpc = RpcProcess::start($this->home, $this->cwd);
        self::assertNotNull($this->rpc->until('ready', 20.0), 'the backend did not start');
    }

    private function quit(): void
    {
        $this->rpc->send(['type' => 'quit']);
        $events = $this->rpc->until('bye', 20.0);
        self::assertNotNull($events, 'the backend did not end');
        self::assertSame(['type' => 'bye', 'reason' => 'quit'], $this->last($events));
        self::assertSame(0, $this->rpc->close());
        $this->rpc = null;
    }

    private function last(array $events): array
    {
        return $events[array_key_last($events)];
    }

    private static function toolCall(string $name, array $arguments): string
    {
        return '<tool_call>' . json_encode(['name' => $name, 'arguments' => $arguments], JSON_THROW_ON_ERROR) . '</tool_call>';
    }
}
