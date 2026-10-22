<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Schedule;

use Phycode\Exec\ProcessRunner;
use Phycode\Exec\RunningProcess;
use Phycode\Schedule\ScheduleCli;
use Phycode\Schedule\Scheduler;
use Phycode\Schedule\ScheduleStore;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase
{
    private string $config;
    private string $state;
    private string $project;
    private \DateTimeImmutable $clock;
    private array $launched = [];

    protected function setUp(): void
    {
        $this->config = Kit::folder('schedule-config');
        $this->state = Kit::folder('schedule-state');
        $this->project = realpath(Kit::folder('schedule-project'));
        $this->clock = new \DateTimeImmutable('2026-10-21 03:00:00');
    }

    private function store(): ScheduleStore
    {
        return ScheduleStore::inConfig($this->config);
    }

    private function scheduler(?\Closure $launch = null): Scheduler
    {
        $launch ??= function (array $job, string $log): RunningProcess {
            $this->launched[] = $job['task'];

            return (new ProcessRunner())->start(['sh', '-c', 'echo ran; exit ' . (str_contains($job['task'], 'fail') ? '3' : '0')], $job['cwd'], $log, 30);
        };

        return new Scheduler($this->store(), $launch, $this->state, fn (): \DateTimeImmutable => $this->clock);
    }

    private function wait(Scheduler $scheduler): void
    {
        for ($i = 0; $i < 100 && $scheduler->running() !== []; $i++) {
            usleep(50000);
        }
    }

    public function testAJobIsSavedWithItsFolderAndListedInOrder(): void
    {
        $this->store()->add('b-job', '@daily', 'second', $this->project, false, null, null);
        $job = $this->store()->add('a-job', ' 30  8 * * 1-5 ', 'first task', $this->project . '/../' . basename($this->project), true, 'local', 'plan');

        self::assertSame(['cron' => '30 8 * * 1-5', 'task' => 'first task', 'cwd' => $this->project, 'yolo' => true, 'profile' => 'local', 'agent' => 'plan'], $job);
        self::assertSame(['a-job', 'b-job'], array_keys($this->store()->all()));
        self::assertSame(0600, fileperms($this->config . '/schedules.json') & 0777);
    }

    public function testABadJobIsRefusedAndNothingIsWritten(): void
    {
        foreach ([
            ['Bad Name', '@daily', 'x', $this->project, 'a job name is'],
            ['ok', 'every day', 'x', $this->project, 'it has five fields'],
            ['ok', '@daily', '  ', $this->project, 'the task is text'],
            ['ok', '@daily', 'x', $this->project . '/missing', 'the project folder does not exist'],
        ] as [$name, $cron, $task, $cwd, $message]) {
            try {
                $this->store()->add($name, $cron, $task, $cwd, false, null, null);
                self::fail("{$name} {$cron} must be refused");
            } catch (PhycodeException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        self::assertFileDoesNotExist($this->config . '/schedules.json');
    }

    public function testTheLimitOfJobsStopsANewOneButNotAReplacement(): void
    {
        for ($i = 0; $i < ScheduleStore::MAX_JOBS; $i++) {
            $this->store()->add("job-{$i}", '@daily', 'x', $this->project, false, null, null);
        }
        $this->store()->add('job-3', '@hourly', 'replaced', $this->project, false, null, null);

        self::assertSame('replaced', $this->store()->all()['job-3']['task']);
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('50 jobs already');
        $this->store()->add('one-more', '@daily', 'x', $this->project, false, null, null);
    }

    public function testRemovingSaysWhetherTheJobWasThere(): void
    {
        $this->store()->add('gone', '@daily', 'x', $this->project, false, null, null);

        self::assertTrue($this->store()->remove('gone'));
        self::assertFalse($this->store()->remove('gone'));
        self::assertSame([], $this->store()->all());
    }

    public function testAFileThatIsNotAListOfJobsIsReportedAndNotOverwrittenSilently(): void
    {
        file_put_contents($this->config . '/schedules.json', 'not json');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('is not a list of scheduled jobs');
        $this->store()->all();
    }

    public function testOnlyTheJobsDueInTheMinuteStartAndOnlyOncePerMinute(): void
    {
        $this->store()->add('nightly', '0 3 * * *', 'nightly task', $this->project, false, null, null);
        $this->store()->add('later', '0 4 * * *', 'later task', $this->project, false, null, null);
        $scheduler = $this->scheduler();

        $first = $scheduler->tick();
        $again = $scheduler->tick();
        $this->wait($scheduler);
        $this->clock = $this->clock->modify('+1 hour');
        $second = $scheduler->tick();
        $this->wait($scheduler);

        self::assertSame(['nightly'], $first);
        self::assertSame([], $again, 'the same minute does not start the job twice');
        self::assertSame(['later'], $second);
        self::assertSame(['nightly task', 'later task'], $this->launched);
        self::assertSame("ran\n", file_get_contents($this->state . '/nightly/20261021-0300.log'));
    }

    public function testTheStateRemembersHowEachRunEnded(): void
    {
        $this->store()->add('good', '* * * * *', 'works', $this->project, false, null, null);
        $this->store()->add('bad', '* * * * *', 'fail please', $this->project, false, null, null);
        $scheduler = $this->scheduler();

        $scheduler->tick();
        $this->wait($scheduler);
        $state = $scheduler->state();

        self::assertSame(0, $state['good']['exit']);
        self::assertSame(3, $state['bad']['exit']);
        self::assertSame($this->state . '/bad/20261021-0300.log', $state['bad']['log']);
    }

    public function testARunThatIsStillGoingIsNotStartedAgainAndTheUserIsTold(): void
    {
        $this->store()->add('slow', '* * * * *', 'slow task', $this->project, false, null, null);
        $scheduler = $this->scheduler(fn (array $job, string $log): RunningProcess => (new ProcessRunner())->start(['sleep', '33'], $job['cwd'], $log, 60));

        self::assertSame(['slow'], $scheduler->tick());
        $this->clock = $this->clock->modify('+1 minute');
        self::assertSame([], $scheduler->tick());

        self::assertSame(['slow is skipped at 2026-10-21 03:01: the run from 2026-10-21 03:00 is still going'], $scheduler->notes());
        self::assertSame([], $scheduler->notes());
        $scheduler->stop();
        self::assertFalse(Kit::sleeping('33'));
        self::assertNotNull($scheduler->state()['slow']['exit']);
    }

    public function testAJobThatCannotStartIsReportedAndTheOthersStillRun(): void
    {
        $this->store()->add('broken', '* * * * *', 'broken task', $this->project, false, null, null);
        $this->store()->add('fine', '* * * * *', 'fine task', $this->project, false, null, null);
        $scheduler = $this->scheduler(function (array $job, string $log): RunningProcess {
            if ($job['task'] === 'broken task') {
                throw new PhycodeException('cannot start sh');
            }

            return (new ProcessRunner())->start(['true'], $job['cwd'], $log, 30);
        });

        self::assertSame(['fine'], $scheduler->tick());
        self::assertSame(['broken did not start: cannot start sh'], $scheduler->notes());
        $this->wait($scheduler);
    }

    public function testOnlyTheLastLogsOfAJobAreKept(): void
    {
        $this->store()->add('busy', '* * * * *', 'x', $this->project, false, null, null);
        $scheduler = $this->scheduler();
        for ($i = 0; $i < Scheduler::KEPT_LOGS + 5; $i++) {
            $scheduler->tick();
            $this->wait($scheduler);
            $this->clock = $this->clock->modify('+1 minute');
        }

        self::assertCount(Scheduler::KEPT_LOGS, glob($this->state . '/busy/*.log'));
        self::assertFileDoesNotExist($this->state . '/busy/20261021-0300.log');
    }

    public function testTheCommandOfAJobIsARunOfPhycodeWithTheTaskAfterTheDashes(): void
    {
        $plain = ScheduleCli::command(['cron' => '@daily', 'task' => '--yolo is just text', 'cwd' => '/p', 'yolo' => false, 'profile' => null, 'agent' => null]);
        $full = ScheduleCli::command(['cron' => '@daily', 'task' => 'go', 'cwd' => '/p', 'yolo' => true, 'profile' => 'local', 'agent' => 'plan']);

        self::assertSame(['run', '--cwd', '/p', '--output-format', 'json', '--', '--yolo is just text'], array_slice($plain, 2));
        self::assertSame(['run', '--cwd', '/p', '--output-format', 'json', '--yolo', '--profile', 'local', '--agent', 'plan', '--', 'go'], array_slice($full, 2));
    }

    public function testTheDaemonStartsDueJobsReportsThemAndStopsItsChildren(): void
    {
        $this->store()->add('tick', '* * * * *', 'x', $this->project, false, null, null);
        $scheduler = $this->scheduler(fn (array $job, string $log): RunningProcess => (new ProcessRunner())->start(['sleep', '34'], $job['cwd'], $log, 60));
        $out = fopen('php://memory', 'w+');
        $rounds = 0;

        $status = ScheduleCli::daemon($scheduler, $out, function () use (&$rounds): bool {
            return $rounds++ >= 2;
        }, function (): void {
            $this->clock = $this->clock->modify('+1 minute');
        });

        rewind($out);
        $log = (string) stream_get_contents($out);
        self::assertSame(0, $status);
        self::assertStringContainsString('running, 1 jobs', $log);
        self::assertStringContainsString('started tick', $log);
        self::assertStringContainsString('is skipped at', $log);
        self::assertStringContainsString('phycode schedule: stopped', $log);
        self::assertFalse(Kit::sleeping('34'));
    }
}
