<?php

declare(strict_types=1);

namespace Phycode\Schedule;

use Phycode\Exec\ProcessRunner;
use Phycode\Exec\RunningProcess;
use Phycode\Support\Entry;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Text;

final class ScheduleCli
{
    public const JOB_SECONDS = 7200;
    private const FORWARDED = ['XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'XDG_STATE_HOME', 'XDG_RUNTIME_DIR', 'TMPDIR'];
    private const USAGE = <<<'TEXT'
        usage:
          phycode schedule [list]                          the jobs, when each runs next and how the last run ended
          phycode schedule add NAME "CRON" "TASK"          add a job; --cwd DIR sets the project, --profile and --agent pick them, --yolo lets it write and run commands
          phycode schedule remove NAME                     remove a job
          phycode schedule run                             stay in the foreground and run the jobs when they are due (put it under systemd, tmux or nohup)
        CRON is five fields (minute hour day-of-month month day-of-week) or @hourly, @daily, @weekly, @monthly.
        TEXT;

    public static function run(array $args, array $options, string $configDir, string $stateFolder, $out, $err, ?\Closure $now = null): int
    {
        $command = array_shift($args) ?? 'list';
        $store = ScheduleStore::inConfig($configDir);
        try {
            return match ($command) {
                'list' => self::list($store, $stateFolder, $now ?? self::clock(...), $out),
                'add' => self::add($args, $options, $store, $out),
                'remove' => self::remove($args, $store, $out),
                'run' => self::daemon(self::scheduler($store, $stateFolder, $now ?? self::clock(...)), $out, self::stopOnSignal(), static fn () => usleep(500000)),
                default => throw new PhycodeException("unknown schedule command '{$command}'\n" . self::USAGE),
            };
        } catch (PhycodeException $error) {
            fwrite($err, 'phycode: ' . Redaction::text($error->getMessage()) . PHP_EOL);

            return 1;
        }
    }

    public static function daemon(Scheduler $scheduler, $out, \Closure $stop, \Closure $pause): int
    {
        fwrite($out, 'phycode schedule: running, ' . count($scheduler->jobs()) . " jobs; stop with Ctrl-C or SIGTERM\n");
        while (!$stop()) {
            foreach ($scheduler->tick() as $name) {
                fwrite($out, date('c') . " started {$name}\n");
            }
            foreach ($scheduler->notes() as $note) {
                fwrite($out, date('c') . " {$note}\n");
            }
            $pause();
        }
        $scheduler->stop();
        fwrite($out, "phycode schedule: stopped\n");

        return 0;
    }

    public static function scheduler(ScheduleStore $store, string $stateFolder, \Closure $now): Scheduler
    {
        return new Scheduler($store, self::launcher(), $stateFolder, $now);
    }

    public static function command(array $job): array
    {
        $argv = [...Entry::command(), 'run', '--cwd', $job['cwd'], '--output-format', 'json'];
        if ($job['yolo']) {
            $argv[] = '--yolo';
        }
        foreach (['profile', 'agent'] as $option) {
            if ($job[$option] !== null) {
                array_push($argv, "--{$option}", $job[$option]);
            }
        }

        return [...$argv, '--', $job['task']];
    }

    public static function launcher(): \Closure
    {
        return static function (array $job, string $log): RunningProcess {
            $env = [];
            foreach (self::FORWARDED as $name) {
                $value = getenv($name);
                if (is_string($value) && $value !== '') {
                    $env[$name] = $value;
                }
            }

            return (new ProcessRunner())->start(self::command($job), $job['cwd'], $log, self::JOB_SECONDS, $env);
        };
    }

    private static function clock(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }

    private static function stopOnSignal(): \Closure
    {
        $stopped = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
                pcntl_signal($signal, static function () use (&$stopped): void {
                    $stopped = true;
                });
            }
        }

        return static function () use (&$stopped): bool {
            return $stopped;
        };
    }

    private static function list(ScheduleStore $store, string $stateFolder, \Closure $now, $out): int
    {
        $jobs = $store->all();
        if ($jobs === []) {
            fwrite($out, "No jobs. phycode schedule add NAME \"30 8 * * 1-5\" \"run the tests and report\" adds one.\n");

            return 0;
        }
        $state = Scheduler::stateOf($stateFolder);
        $current = $now();
        foreach ($jobs as $name => $job) {
            $next = CronExpression::parse($job['cron'])->next($current);
            fwrite($out, sprintf(
                "%-20s %-16s next %-17s last %s%s\n    %s\n    %s\n",
                $name,
                $job['cron'],
                $next === null ? 'never' : $next->format('Y-m-d H:i'),
                self::last($state[$name] ?? null),
                $job['yolo'] ? '  [yolo]' : '',
                $job['cwd'],
                Text::oneLine($job['task'], 100),
            ));
        }

        return 0;
    }

    private static function last(?array $entry): string
    {
        if ($entry === null) {
            return 'never';
        }
        $started = substr((string) ($entry['started'] ?? ''), 0, 16);
        $exit = $entry['exit'] ?? null;

        return $started . ' ' . ($exit === null ? 'running or interrupted' : ($exit === 0 ? 'ok' : "failed (exit {$exit})"));
    }

    private static function add(array $args, array $options, ScheduleStore $store, $out): int
    {
        if (count($args) !== 3) {
            throw new PhycodeException("give the name, the cron expression in quotes and the task in quotes\n" . self::USAGE);
        }
        [$name, $cron, $task] = $args;
        $job = $store->add($name, $cron, $task, (string) ($options['cwd'] ?? getcwd()), (bool) ($options['yolo'] ?? false), $options['profile'] ?? null, $options['agent'] ?? null);
        $next = CronExpression::parse($job['cron'])->next(self::clock());
        fwrite($out, "added {$name}: {$job['cron']} in {$job['cwd']}, next run " . ($next === null ? 'never' : $next->format('Y-m-d H:i')) . "\n");
        if ($job['yolo']) {
            fwrite($out, "this job runs with --yolo: it writes files and runs commands without asking\n");
        }

        return 0;
    }

    private static function remove(array $args, ScheduleStore $store, $out): int
    {
        if (count($args) !== 1) {
            throw new PhycodeException("give the name of the job\n" . self::USAGE);
        }
        if (!$store->remove($args[0])) {
            throw new PhycodeException("no job named {$args[0]}; phycode schedule list shows them");
        }
        fwrite($out, "removed {$args[0]}\n");

        return 0;
    }
}
