<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Exec;

use Phycode\Exec\BackgroundJobs;
use Phycode\Exec\ProcessRunner;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParentDeathTest extends TestCase
{
    private $phycode = null;

    protected function tearDown(): void
    {
        if (is_resource($this->phycode)) {
            proc_terminate($this->phycode, 9);
            proc_close($this->phycode);
        }
        $lines = [];
        exec('ps -ax -o pid= -o command=', $lines);
        foreach (preg_grep('/sleep 9[01]\.\d5$/', $lines) ?: [] as $line) {
            posix_kill((int) $line, 9);
        }
    }

    private function eventually(callable $check, float $seconds): bool
    {
        for ($deadline = microtime(true) + $seconds; !$check() && microtime(true) < $deadline; usleep(100000)) {
        }

        return $check();
    }

    public static function ways(): iterable
    {
        yield 'a background job' => ['$runner->start(["bash", "-c", "sleep 91.25 & sleep 91.25"], sys_get_temp_dir(), sys_get_temp_dir() . "/phycode-parent-death-" . getmypid() . ".log", 600);', '91.25'];
        yield 'a command' => ['$runner->run(["bash", "-c", "sleep 91.75 & sleep 91.75"], sys_get_temp_dir(), 600, 4096);', '91.75'];
    }

    #[DataProvider('ways')]
    public function testWhatPhycodeRunsStopsWhenPhycodeIsKilled(string $call, string $sleep): void
    {
        $code = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . '; $runner = new ' . ProcessRunner::class . '(); '
            . 'fwrite(STDOUT, "go\n"); ' . $call . ' sleep(600);';
        $this->phycode = proc_open([PHP_BINARY, '-r', $code], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertSame("go\n", fgets($pipes[1]));
        self::assertTrue($this->eventually(static fn (): bool => Kit::sleeping($sleep), 10.0), 'the command started');

        proc_terminate($this->phycode, 9);
        proc_close($this->phycode);
        $this->phycode = null;

        self::assertTrue($this->eventually(static fn (): bool => !Kit::sleeping($sleep), 15.0), 'nothing of it is left once phycode is gone');
    }

    public function testACommandRunsToItsEndWhenThePathHoldsNoneOfTheTools(): void
    {
        $path = getenv('PATH');
        putenv('PATH=' . Kit::folder('empty-path'));
        try {
            $result = (new ProcessRunner())->run(['/bin/sh', '-c', 'echo done; exit 3'], sys_get_temp_dir(), 20, 4096);
        } finally {
            putenv($path === false ? 'PATH' : 'PATH=' . $path);
        }

        self::assertSame(3, $result->exitCode);
        self::assertSame("done\n", $result->stdout);
        self::assertNull($result->interrupted);
    }

    public function testAJobThatTheWrapperStopsAtItsLifetimeIsReportedAsTimedOut(): void
    {
        $path = getenv('PATH');
        $bin = Kit::folder('wrapper-bin');
        foreach (['bash', 'sleep', 'ps'] as $program) {
            symlink((string) ProcessRunner::which($program), $bin . '/' . $program);
        }
        putenv('PATH=' . $bin);
        try {
            self::assertNull(ProcessRunner::timeoutProgram());
            $jobs = new BackgroundJobs(Kit::folder('wrapper-jobs'), 8, 1);
            $job = $jobs->start(new ProcessRunner(), ['bash', '-c', 'sleep 90.75'], sys_get_temp_dir(), 'demo');
            sleep(3);

            self::assertFalse($job->running());
        } finally {
            putenv($path === false ? 'PATH' : 'PATH=' . $path);
        }
        self::assertStringContainsString('its time limit passed', $job->state());
    }

    public function testTheLifetimeOfABackgroundCommandEndsItWithoutPhycodeLooking(): void
    {
        $process = (new ProcessRunner())->start(['bash', '-c', 'sleep 90.25 & sleep 90.25'], sys_get_temp_dir(), sys_get_temp_dir() . '/phycode-lifetime-' . getmypid() . '.log', 2);
        self::assertTrue($this->eventually(static fn (): bool => Kit::sleeping('90.25'), 10.0));

        self::assertTrue($this->eventually(static fn (): bool => !Kit::sleeping('90.25'), 15.0), 'the limit is kept outside phycode');
        $process->stop();
    }
}
