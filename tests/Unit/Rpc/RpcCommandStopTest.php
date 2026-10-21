<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Tests\Support\FakeModel;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RpcProcess;
use PHPUnit\Framework\TestCase;

final class RpcCommandStopTest extends TestCase
{
    private const COMMAND = 'sleep 60 & echo $! > child.tmp; mv child.tmp child.pid; wait';

    private string $home;
    private string $cwd;
    private ?FakeModel $model = null;
    private ?RpcProcess $rpc = null;

    protected function setUp(): void
    {
        $this->home = Kit::folder('rpc-stop-home');
        $this->cwd = Kit::folder('rpc-stop-cwd');
    }

    protected function tearDown(): void
    {
        $this->rpc?->close();
        $this->model?->stop();
    }

    public function testQuittingDuringACommandStopsTheProcessesItStarted(): void
    {
        $this->start();
        $this->rpc->send(['type' => 'input', 'text' => 'run the command']);
        $child = $this->commandStarted();

        $this->rpc->send(['type' => 'quit']);
        $events = $this->rpc->until('bye', 20.0);

        self::assertNotNull($events, 'the backend did not end after the quit');
        self::assertSame('cancelled', self::of($events, 'turn_end')[0]['status']);
        self::assertSame(['type' => 'bye', 'reason' => 'quit'], $events[array_key_last($events)]);
        self::assertSame(0, $this->rpc->close());
        $this->rpc = null;
        self::assertTrue($this->gone($child), 'the sleep that the command started is gone');
    }

    public function testTheInputEndingDuringACommandStopsTheProcessesItStarted(): void
    {
        $this->start();
        $this->rpc->send(['type' => 'input', 'text' => 'run the command']);
        $child = $this->commandStarted();

        $ended = microtime(true);
        self::assertSame(0, $this->rpc->close(), 'the backend ends when its input ends');
        self::assertLessThan(15.0, microtime(true) - $ended, 'the backend did not wait for the sleep to finish');
        $this->rpc = null;
        self::assertTrue($this->gone($child), 'the sleep that the command started is gone');
    }

    private function start(): void
    {
        $this->model = new FakeModel([
            ['content' => '<tool_call>' . json_encode(['name' => 'bash', 'arguments' => ['command' => self::COMMAND]], JSON_THROW_ON_ERROR) . '</tool_call>'],
            ['content' => 'the command was stopped'],
        ]);
        RpcProcess::configure($this->home, $this->model->url(), 'text', 'yolo');
        $this->rpc = RpcProcess::start($this->home, $this->cwd);
        self::assertNotNull($this->rpc->until('ready', 20.0), 'the backend did not start');
    }

    private function commandStarted(): int
    {
        self::assertNotNull($this->rpc->until('tool_start', 20.0), 'the command was not started');
        $file = $this->cwd . '/child.pid';
        for ($attempt = 0; $attempt < 500 && !is_file($file); $attempt++) {
            usleep(20000);
        }
        self::assertFileExists($file);

        return (int) file_get_contents($file);
    }

    private function gone(int $pid): bool
    {
        for ($attempt = 0; $attempt < 100 && posix_kill($pid, 0); $attempt++) {
            usleep(20000);
        }

        return !posix_kill($pid, 0);
    }

    private static function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (array $event): bool => ($event['type'] ?? null) === $type));
    }
}
