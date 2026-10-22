<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Exec;

use Phycode\Exec\ProcessRunner;
use Phycode\Support\Cancellation;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    public function testExitCodeAndBothStreamsAreReturned(): void
    {
        $result = (new ProcessRunner())->run(['bash', '-c', 'echo hi; echo err >&2; exit 3'], sys_get_temp_dir(), 5, 4096);

        self::assertSame(3, $result->exitCode);
        self::assertSame("hi\n", $result->stdout);
        self::assertSame("err\n", $result->stderr);
        self::assertFalse($result->succeeded());
        self::assertNull($result->interrupted);
    }

    public function testTimeoutKillsTheCommandAndItsChildren(): void
    {
        $started = microtime(true);
        $result = (new ProcessRunner())->run(['bash', '-c', 'sleep 30 & sleep 30'], sys_get_temp_dir(), 1, 4096);

        self::assertSame('timeout', $result->interrupted);
        self::assertFalse($result->succeeded());
        self::assertLessThan(8.0, microtime(true) - $started, 'the background sleep must not keep the pipes open for long');
    }

    public function testATimeoutAlsoStopsWhatLeftTheSessionAndTheProcessTree(): void
    {
        $escape = '(' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('posix_setsid(); passthru("sleep 95.5");') . ' &); sleep 30';

        $result = (new ProcessRunner())->run(['bash', '-c', $escape], sys_get_temp_dir(), 2, 4096);

        self::assertSame('timeout', $result->interrupted);
        self::assertTrue($this->eventually(static fn (): bool => !Kit::sleeping('95.5')), 'the sleep in its own session is stopped with the command');
    }

    public function testATimeoutAlsoStopsWhatLeftTheSessionAndClearedItsEnvironment(): void
    {
        $escape = '(' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('posix_setsid(); passthru("env -i /bin/sleep 96.25");') . ' &); sleep 30';

        $result = (new ProcessRunner())->run(['bash', '-c', $escape], sys_get_temp_dir(), 2, 4096);

        self::assertSame('timeout', $result->interrupted);
        self::assertTrue($this->eventually(static fn (): bool => !Kit::sleeping('96.25')), 'the sleep without the mark in its environment is stopped too');
    }

    public function testADaemonInItsOwnSessionOutlivesACommandThatEndsByItselfAndTheExitCodeIsKept(): void
    {
        $daemon = '(' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('posix_setsid(); passthru("sleep 93.5");') . ' >/dev/null 2>&1 &); sleep 1; exit 3';
        $started = microtime(true);

        $result = (new ProcessRunner())->run(['bash', '-c', $daemon], sys_get_temp_dir(), 20, 4096);

        try {
            self::assertSame(3, $result->exitCode);
            self::assertNull($result->interrupted);
            self::assertLessThan(8.0, microtime(true) - $started, 'the command does not wait for the daemon');
            self::assertTrue(Kit::sleeping('93.5'), 'the daemon goes on, like a server that the command started');
        } finally {
            $lines = [];
            exec('ps -ax -o pid= -o command=', $lines);
            foreach (preg_grep('/sleep 93\.5/', $lines) ?: [] as $line) {
                posix_kill((int) $line, 9);
            }
        }
    }

    public function testADoubleForkIsStoppedWithoutAGnuTimeoutToo(): void
    {
        $path = getenv('PATH');
        $bin = Kit::folder('no-timeout-bin');
        foreach (['bash', 'sleep', 'ps'] as $program) {
            symlink((string) ProcessRunner::which($program), $bin . '/' . $program);
        }
        putenv('PATH=' . $bin);
        try {
            self::assertNull(ProcessRunner::timeoutProgram());
            $result = (new ProcessRunner())->run(['bash', '-c', '(sleep 96.5 &); echo started'], sys_get_temp_dir(), 20, 4096);
        } finally {
            putenv('PATH=' . $path);
        }

        self::assertSame("started\n", $result->stdout);
        self::assertTrue($this->eventually(static fn (): bool => !Kit::sleeping('96.5')), 'the orphan is in the group of the command, which is stopped when it ends');
    }

    private function eventually(callable $check): bool
    {
        for ($attempt = 0; $attempt < 100 && !$check(); $attempt++) {
            usleep(50000);
        }

        return $check();
    }

    public function testLongOutputKeepsTheHeadAndTheTail(): void
    {
        $result = (new ProcessRunner())->run(['bash', '-c', 'head -c 20000 /dev/zero | tr "\0" "a"; echo END'], sys_get_temp_dir(), 5, 1000);

        self::assertStringContainsString('bytes omitted', $result->stdout);
        self::assertStringEndsWith("END\n", $result->stdout);
        self::assertLessThan(1200, strlen($result->stdout));
    }

    public function testBackgroundChildrenAreStoppedWhenTheCommandEnds(): void
    {
        $result = (new ProcessRunner())->run(['bash', '-c', 'sleep 60 & echo $!'], sys_get_temp_dir(), 20, 4096);

        $pid = (int) trim($result->stdout);
        self::assertGreaterThan(1, $pid);
        for ($attempt = 0; $attempt < 50 && $this->isAlive($pid); $attempt++) {
            usleep(20000);
        }
        self::assertFalse($this->isAlive($pid));
    }

    public function testACancelledCommandIsStoppedWithTheChildrenItStarted(): void
    {
        $cancel = new Cancellation();
        $started = microtime(true);
        Cancellation::setPoll(static function () use ($cancel, $started): void {
            if (microtime(true) - $started >= 0.5) {
                $cancel->cancel('interrupted by the user');
            }
        });

        try {
            $result = (new ProcessRunner())->run(['bash', '-c', 'sleep 77.25 & sleep 77.5'], sys_get_temp_dir(), 60, 4096, $cancel);
        } finally {
            Cancellation::setPoll(null);
        }

        self::assertSame('cancelled', $result->interrupted);
        self::assertLessThan(3.0, microtime(true) - $started);
        self::assertFalse(Kit::sleeping('77.25'));
        self::assertFalse(Kit::sleeping('77.5'));
    }

    public function testACommandRunsInAProcessGroupOfItsOwn(): void
    {
        $result = (new ProcessRunner())->run(['bash', '-c', 'if [ -r /proc/$$/stat ]; then cut -d " " -f5 /proc/$$/stat; else ps -o pgid= -p $$; fi'], sys_get_temp_dir(), 5, 4096);

        self::assertNotSame(posix_getpgrp(), (int) trim($result->stdout));
    }

    public function testACancelledCommandGetsSigtermAndTheSleepItStartedIsGone(): void
    {
        $folder = Kit::folder('group-term');
        $cancel = new Cancellation();
        $cancelledAt = null;
        Cancellation::setPoll(static function () use ($cancel, $folder, &$cancelledAt): void {
            if ($cancelledAt === null && is_file($folder . '/child.pid')) {
                $cancelledAt = microtime(true);
                $cancel->cancel('interrupted by the user');
            }
        });

        try {
            $result = (new ProcessRunner())->run(['bash', '-c', 'trap "echo term > term.txt; exit 0" TERM; sleep 60 & echo $! > child.tmp; mv child.tmp child.pid; wait'], $folder, 30, 4096, $cancel);
        } finally {
            Cancellation::setPoll(null);
        }

        self::assertSame('cancelled', $result->interrupted);
        self::assertFileExists($folder . '/term.txt', 'bash received SIGTERM: a SIGKILL cannot be trapped');
        self::assertSame("term\n", file_get_contents($folder . '/term.txt'));
        self::assertLessThan(1.0, microtime(true) - $cancelledAt, 'SIGTERM ended the command at once');
        self::assertTrue($this->gone((int) file_get_contents($folder . '/child.pid')), 'the sleep 60 that bash started is gone');
    }

    public function testACommandThatIgnoresSigtermIsKilledTwoSecondsAfterTheCancel(): void
    {
        $folder = Kit::folder('group-kill');
        $cancel = new Cancellation();
        $cancelledAt = null;
        Cancellation::setPoll(static function () use ($cancel, $folder, &$cancelledAt): void {
            if ($cancelledAt === null && is_file($folder . '/child.pid')) {
                $cancelledAt = microtime(true);
                $cancel->cancel('interrupted by the user');
            }
        });

        try {
            $result = (new ProcessRunner())->run(['bash', '-c', 'trap "" TERM; sleep 60 & echo $! > child.tmp; mv child.tmp child.pid; wait'], $folder, 30, 4096, $cancel);
        } finally {
            Cancellation::setPoll(null);
        }
        $elapsed = microtime(true) - $cancelledAt;

        self::assertSame('cancelled', $result->interrupted);
        self::assertGreaterThanOrEqual(1.9, $elapsed, 'the sleep ignores SIGTERM, so the group is killed two seconds after it, not at once');
        self::assertLessThan(5.0, $elapsed, 'and it is killed then, not left to the 60 s sleep');
        self::assertTrue($this->gone((int) file_get_contents($folder . '/child.pid')), 'the sleep is gone once the group is killed');
    }

    private function gone(int $pid): bool
    {
        for ($attempt = 0; $attempt < 100 && posix_kill($pid, 0); $attempt++) {
            usleep(20000);
        }

        return !posix_kill($pid, 0);
    }

    private function isAlive(int $pid): bool
    {
        $state = Kit::processState($pid);

        return $state !== null && $state !== 'Z' && $state !== 'X';
    }

    public function testWorkingDirectoryIsRespected(): void
    {
        $folder = Kit::folder('cwd');
        $result = (new ProcessRunner())->run(['pwd'], $folder, 5, 4096);

        self::assertSame(realpath($folder), trim($result->stdout));
    }

    public function testEnvironmentDoesNotLeakTheParentShell(): void
    {
        putenv('PHYCODE_TEST_SECRET=should-not-leak');
        $result = (new ProcessRunner())->run(['bash', '-c', 'echo "[${PHYCODE_TEST_SECRET:-none}]"'], sys_get_temp_dir(), 5, 4096);
        putenv('PHYCODE_TEST_SECRET');

        self::assertSame("[none]\n", $result->stdout);
    }
}
