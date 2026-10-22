<?php

declare(strict_types=1);

namespace Phycode\Schedule;

use Phycode\Exec\RunningProcess;
use Phycode\Support\Files;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class Scheduler
{
    public const KEPT_LOGS = 20;

    private array $children = [];
    private ?string $lastMinute = null;
    private array $notes = [];

    public function __construct(
        private readonly ScheduleStore $store,
        private readonly \Closure $launch,
        private readonly string $stateFolder,
        private readonly \Closure $now,
    ) {
    }

    public function tick(): array
    {
        $this->reap();
        $now = ($this->now)();
        $minute = $now->format('Y-m-d H:i');
        if ($minute === $this->lastMinute) {
            return [];
        }
        $this->lastMinute = $minute;
        try {
            $jobs = $this->store->all();
        } catch (PhycodeException $error) {
            $this->notes[] = $error->getMessage();

            return [];
        }
        $started = [];
        foreach ($jobs as $name => $job) {
            try {
                if (!CronExpression::parse($job['cron'])->matches($now)) {
                    continue;
                }
                if (isset($this->children[$name])) {
                    $this->notes[] = "{$name} is skipped at {$minute}: the run from {$this->children[$name]['at']} is still going";

                    continue;
                }
                $log = $this->logFolder($name) . '/' . $now->format('Ymd-Hi') . '.log';
                $this->children[$name] = ['process' => ($this->launch)($job, $log), 'log' => $log, 'at' => $minute];
                $this->remember($name, ['started' => $now->format('c'), 'exit' => null, 'log' => $log]);
                $this->prune($name);
                $started[] = $name;
            } catch (PhycodeException $error) {
                $this->notes[] = "{$name} did not start: " . $error->getMessage();
            }
        }

        return $started;
    }

    public function jobs(): array
    {
        return $this->store->all();
    }

    public function notes(): array
    {
        $notes = $this->notes;
        $this->notes = [];

        return $notes;
    }

    public function running(): array
    {
        $this->reap();

        return array_keys($this->children);
    }

    public function stop(): void
    {
        foreach ($this->children as $name => $child) {
            $child['process']->stop();
            $this->finish($name, $child['process']);
        }
        $this->children = [];
    }

    public function state(): array
    {
        return self::stateOf($this->stateFolder);
    }

    public static function stateOf(string $stateFolder): array
    {
        $data = is_file($stateFolder . '/state.json') ? Json::object((string) file_get_contents($stateFolder . '/state.json')) : null;

        return is_array($data) ? $data : [];
    }

    private function reap(): void
    {
        foreach ($this->children as $name => $child) {
            if (!$child['process']->running()) {
                $this->finish($name, $child['process']);
                unset($this->children[$name]);
            }
        }
    }

    private function finish(string $name, RunningProcess $process): void
    {
        $state = $this->state()[$name] ?? [];
        $state['exit'] = $process->exitCode() ?? ($process->signal() === null ? -1 : 128 + $process->signal());
        $this->remember($name, $state);
    }

    private function remember(string $name, array $entry): void
    {
        $state = $this->state();
        $state[$name] = $entry;
        if (!is_dir($this->stateFolder) && !mkdir($this->stateFolder, 0700, true) && !is_dir($this->stateFolder)) {
            throw new PhycodeException('cannot create ' . $this->stateFolder);
        }
        Files::writeAtomic($this->stateFolder . '/state.json', Json::encode($state, true) . "\n", 0600);
    }

    private function logFolder(string $name): string
    {
        $folder = $this->stateFolder . '/' . $name;
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new PhycodeException("cannot create {$folder}");
        }

        return $folder;
    }

    private function prune(string $name): void
    {
        $logs = glob($this->stateFolder . '/' . $name . '/*.log') ?: [];
        sort($logs);
        foreach (array_slice($logs, 0, max(0, count($logs) - self::KEPT_LOGS)) as $old) {
            @unlink($old);
        }
    }
}
