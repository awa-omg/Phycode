<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Exec;

use Phycode\Exec\BackgroundJob;
use Phycode\Exec\BackgroundJobs;
use Phycode\Exec\CommandRunner;
use Phycode\Exec\ContainerRunner;
use Phycode\Exec\ProcessRunner;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\FakeRuntime;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class BackgroundJobsTest extends TestCase
{
    private string $root;
    private string $logs;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('jobs-project'));
        $this->logs = Kit::folder('jobs-logs') . '/out';
    }

    public function testAJobReturnsAtOnceAndItsOutputCanBeReadLater(): void
    {
        $jobs = new BackgroundJobs($this->logs);

        $job = $jobs->start(new ProcessRunner(), ['bash', '-c', 'echo first; sleep 0.3; echo second'], $this->root, 'demo');

        self::assertTrue($job->running());
        self::assertSame(1, $job->id);
        self::assertTrue(self::waitUntil(static fn (): bool => !$job->running()));
        self::assertStringContainsString('exited with code 0', $job->state());
        self::assertSame(["first\nsecond\n", 0], $job->read(1000));
        self::assertSame(['', 0], $job->read(1000), 'a second read has only what came after the first');
    }

    public function testOnlyTheOutputWrittenSinceTheLastReadIsReturned(): void
    {
        $jobs = new BackgroundJobs($this->logs);
        $job = $jobs->start(new ProcessRunner(), ['bash', '-c', 'echo one; sleep 1; echo two; sleep 5'], $this->root, 'demo');

        self::assertTrue(self::waitUntil(static fn (): bool => $job->read(1000)[0] === "one\n"));
        self::assertTrue(self::waitUntil(static fn (): bool => $job->read(1000)[0] === "two\n"));
        $job->stop();
    }

    public function testErrorOutputAndExitCodeAreKept(): void
    {
        $jobs = new BackgroundJobs($this->logs);
        $job = $jobs->start(new ProcessRunner(), ['bash', '-c', 'echo out; echo err >&2; exit 3'], $this->root, 'demo');

        self::assertTrue(self::waitUntil(static fn (): bool => !$job->running()));
        self::assertStringContainsString('exited with code 3', $job->state());
        [$text] = $job->read(1000);
        self::assertStringContainsString('out', $text);
        self::assertStringContainsString('err', $text);
    }

    public function testOnlyTheEndOfALongOutputIsReturnedAndTheRestIsCounted(): void
    {
        $jobs = new BackgroundJobs($this->logs);
        $job = $jobs->start(new ProcessRunner(), ['bash', '-c', 'printf "%s" aaaaaaaaaabbbbbbbbbb'], $this->root, 'demo');
        self::assertTrue(self::waitUntil(static fn (): bool => !$job->running()));

        self::assertSame(['bbbbbbbbbb', 10], $job->read(10));
    }

    public function testStoppingAJobEndsTheCommandsItStarted(): void
    {
        $jobs = new BackgroundJobs($this->logs);
        $marker = 'phycode-job-' . bin2hex(random_bytes(4));
        $job = $jobs->start(new ProcessRunner(), ['bash', '-c', "sleep 31 && echo {$marker} & wait"], $this->root, 'demo');
        self::assertTrue(self::waitUntil(static fn (): bool => Kit::sleeping('31')));

        $job->stop('test');

        self::assertFalse($job->running());
        self::assertTrue(self::waitUntil(static fn (): bool => !Kit::sleeping('31')), 'the sleep the job started is gone too');
        self::assertStringContainsString('stopped: test after', $job->state());
    }

    public function testStoppingAJobEndsWhatLeftTheSessionToo(): void
    {
        $jobs = new BackgroundJobs($this->logs);
        $escape = '(' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('posix_setsid(); passthru("sleep 97.5");') . ' &); sleep 30';
        $job = $jobs->start(new ProcessRunner(), ['bash', '-c', $escape], $this->root, 'demo');
        self::assertTrue(self::waitUntil(static fn (): bool => Kit::sleeping('97.5')));

        $job->stop('test');

        self::assertTrue(self::waitUntil(static fn (): bool => !Kit::sleeping('97.5')), 'the sleep in its own session is gone too');
    }

    public function testAJobStopsWhenItsLifetimeIsOver(): void
    {
        $jobs = new BackgroundJobs($this->logs, 8, 1);
        $job = $jobs->start(new ProcessRunner(), ['bash', '-c', 'sleep 30'], $this->root, 'demo');

        self::assertTrue(self::waitUntil(static fn (): bool => !$job->running(), 10));
        self::assertStringContainsString('its time limit passed', $job->state());
    }

    public function testAJobThatWritesTooMuchIsStopped(): void
    {
        $jobs = new BackgroundJobs($this->logs, 8, 3600, 2048);
        $job = $jobs->start(new ProcessRunner(), ['bash', '-c', 'yes xxxxxxxxxxxxxxxx | head -c 100000; sleep 30'], $this->root, 'demo');

        self::assertTrue(self::waitUntil(static fn (): bool => !$job->running(), 10));
        self::assertStringContainsString('stopped: its output is larger than', $job->state());
    }

    public function testThereIsALimitOfRunningJobsAndAFinishedJobDoesNotCount(): void
    {
        $jobs = new BackgroundJobs($this->logs, 2);
        $first = $jobs->start(new ProcessRunner(), ['sleep', '30'], $this->root, 'a');
        $jobs->start(new ProcessRunner(), ['sleep', '30'], $this->root, 'b');

        try {
            $jobs->start(new ProcessRunner(), ['sleep', '30'], $this->root, 'c');
            self::fail('the third job must be refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('2 jobs are running already', $error->getMessage());
        }

        $first->stop();
        self::assertSame(3, $jobs->start(new ProcessRunner(), ['sleep', '30'], $this->root, 'd')->id);
        $jobs->stopAll();
    }

    public function testARunnerThatCannotRunInTheBackgroundIsRefused(): void
    {
        $runner = new class () implements CommandRunner {
            public function run(array $argv, string $cwd, int $timeout, int $maxBytes, ?\Phycode\Support\Cancellation $cancel = null, array $env = []): \Phycode\Exec\ProcessResult
            {
                return new \Phycode\Exec\ProcessResult(0, '', '', null, 0.0);
            }
        };

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('cannot run in the background');
        (new BackgroundJobs($this->logs))->start($runner, ['true'], $this->root, 'demo');
    }

    public function testEndingTheSessionStopsTheJobsAndRemovesTheirOutput(): void
    {
        $jobs = new BackgroundJobs($this->logs);
        $job = $jobs->start(new ProcessRunner(), ['bash', '-c', 'echo secret; sleep 32'], $this->root, 'demo');
        self::assertTrue(self::waitUntil(static fn (): bool => Kit::sleeping('32')));
        self::assertCount(1, glob($this->logs . '/*.log') ?: []);
        self::assertSame(0600, fileperms((glob($this->logs . '/*.log') ?: [''])[0]) & 0777);
        self::assertSame(0700, fileperms($this->logs) & 0777);

        $jobs->stopAll();

        self::assertFalse($job->running());
        self::assertSame([], glob($this->logs . '/*.log') ?: []);
        self::assertTrue(self::waitUntil(static fn (): bool => !Kit::sleeping('32')));
    }

    public function testAContainerJobIsRemovedByNameWhenItStops(): void
    {
        $fake = FakeRuntime::install();
        $fake->behave('case "$1" in run) sleep 33;; esac');
        $runner = new ContainerRunner(new ProcessRunner(), 'podman', 'debian:trixie-slim', 'none', $this->root);
        $jobs = new BackgroundJobs($this->logs);

        $job = $jobs->start($runner, ['bash', '-c', 'make'], $this->root, 'make');
        self::assertTrue(self::waitUntil(static fn (): bool => Kit::sleeping('33')));
        self::assertTrue(is_dir($this->root . '/.git'), 'the protected folders exist while the container runs');
        $job->stop();

        $log = $fake->log();
        self::assertContains('run', $log);
        self::assertContains('--volume=' . $this->root . ':/workspace', $log);
        $removal = array_search('rm', $log, true);
        self::assertNotFalse($removal, 'the container is removed by name');
        self::assertSame('-f', $log[$removal + 1]);
        self::assertStringStartsWith('phycode-', $log[$removal + 2]);
        self::assertFalse(is_dir($this->root . '/.git'), 'the folders made for the container are removed with it');
    }

    private static function waitUntil(callable $condition, int $seconds = 5): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }
            usleep(50000);
        }

        return false;
    }
}
