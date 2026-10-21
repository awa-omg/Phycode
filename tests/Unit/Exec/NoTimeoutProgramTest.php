<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Exec;

use Phycode\Exec\ProcessRunner;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class NoTimeoutProgramTest extends TestCase
{
    private string|false $path;
    private string $bin;

    protected function setUp(): void
    {
        $this->path = getenv('PATH');
        $this->bin = Kit::folder('no-timeout-bin');
        foreach (['sh', 'sleep', 'ps'] as $program) {
            $found = ProcessRunner::which($program);
            if ($found === null) {
                self::markTestSkipped("{$program} is not installed");
            }
            symlink($found, $this->bin . '/' . $program);
        }
        putenv('PATH=' . $this->bin);
    }

    protected function tearDown(): void
    {
        putenv($this->path === false ? 'PATH' : 'PATH=' . $this->path);
    }

    private function wait(\Phycode\Exec\RunningProcess $process, float $seconds): bool
    {
        for ($deadline = microtime(true) + $seconds; $process->running() && microtime(true) < $deadline; usleep(50000)) {
        }

        return !$process->running();
    }

    public function testWithoutTheTimeoutProgramABackgroundCommandRunsAndKeepsItsExitCode(): void
    {
        self::assertNull(ProcessRunner::timeoutProgram());
        $log = $this->bin . '/job.log';

        $process = (new ProcessRunner())->start(['sh', '-c', 'echo hello; exit 3'], $this->bin, $log, 60);

        self::assertTrue($this->wait($process, 10.0));
        self::assertSame(3, $process->exitCode());
        self::assertSame("hello\n", file_get_contents($log));
    }

    public function testWithoutTheTimeoutProgramTheLifetimeStillEndsTheCommandAndItsChildren(): void
    {
        $process = (new ProcessRunner())->start(['sh', '-c', 'sleep 94.5 & wait'], $this->bin, $this->bin . '/job.log', 1);
        $started = microtime(true);

        self::assertTrue($this->wait($process, 12.0));
        self::assertLessThan(10.0, microtime(true) - $started);
        for ($attempt = 0; $attempt < 60 && Kit::sleeping('94.5'); $attempt++) {
            usleep(100000);
        }
        self::assertFalse(Kit::sleeping('94.5'));
    }

    public function testWithoutTheTimeoutProgramStoppingTheJobEndsItsChildren(): void
    {
        $process = (new ProcessRunner())->start(['sh', '-c', 'sleep 95.5 & wait'], $this->bin, $this->bin . '/job.log', 600);
        for ($attempt = 0; $attempt < 50 && !Kit::sleeping('95.5'); $attempt++) {
            usleep(100000);
        }
        self::assertTrue(Kit::sleeping('95.5'));

        $process->stop();

        for ($attempt = 0; $attempt < 60 && Kit::sleeping('95.5'); $attempt++) {
            usleep(100000);
        }
        self::assertFalse(Kit::sleeping('95.5'));
        self::assertFalse($process->running());
    }

    public function testWithoutTheTimeoutProgramACommandIsStoppedAtItsLimitTogetherWithItsChildren(): void
    {
        $started = microtime(true);

        $result = (new ProcessRunner())->run(['sh', '-c', 'sleep 96.5 & wait'], $this->bin, 1, 4096);

        self::assertSame('timeout', $result->interrupted);
        self::assertLessThan(5.0, microtime(true) - $started);
        for ($attempt = 0; $attempt < 60 && Kit::sleeping('96.5'); $attempt++) {
            usleep(100000);
        }
        self::assertFalse(Kit::sleeping('96.5'));
    }

    public function testWithoutTheTimeoutProgramACommandThatFinishesKeepsItsOutputAndItsExitCode(): void
    {
        $result = (new ProcessRunner())->run(['sh', '-c', 'echo out; echo err >&2; exit 4'], $this->bin, 5, 4096);

        self::assertSame(4, $result->exitCode);
        self::assertSame("out\n", $result->stdout);
        self::assertSame("err\n", $result->stderr);
        self::assertNull($result->interrupted);
    }
}
