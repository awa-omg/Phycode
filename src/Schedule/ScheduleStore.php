<?php

declare(strict_types=1);

namespace Phycode\Schedule;

use Phycode\Support\Files;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class ScheduleStore
{
    public const MAX_JOBS = 50;
    public const MAX_TASK_CHARS = 4000;
    private const NAME = '/^[a-z0-9][a-z0-9_-]{0,31}$/D';
    private const WORD = '/^[A-Za-z0-9_.-]{1,64}$/D';

    public function __construct(private readonly string $file)
    {
    }

    public static function inConfig(string $configDir): self
    {
        return new self($configDir . '/schedules.json');
    }

    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $data = Json::object((string) file_get_contents($this->file));
        if ($data === null || !is_array($data['jobs'] ?? null)) {
            throw new PhycodeException("{$this->file} is not a list of scheduled jobs; fix it or delete it");
        }
        $jobs = [];
        foreach ($data['jobs'] as $name => $job) {
            if (is_string($name) && is_array($job) && is_string($job['cron'] ?? null) && is_string($job['task'] ?? null) && is_string($job['cwd'] ?? null)) {
                $jobs[$name] = [
                    'cron' => $job['cron'],
                    'task' => $job['task'],
                    'cwd' => $job['cwd'],
                    'yolo' => ($job['yolo'] ?? false) === true,
                    'profile' => is_string($job['profile'] ?? null) ? $job['profile'] : null,
                    'agent' => is_string($job['agent'] ?? null) ? $job['agent'] : null,
                ];
            }
        }
        ksort($jobs);

        return $jobs;
    }

    public function add(string $name, string $cron, string $task, string $cwd, bool $yolo, ?string $profile, ?string $agent): array
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new PhycodeException('a job name is lowercase letters, digits, hyphens and underscores, from 1 to 32 characters, such as nightly-tests');
        }
        $expression = CronExpression::parse($cron);
        $task = trim($task);
        if ($task === '' || mb_strlen($task) > self::MAX_TASK_CHARS || str_contains($task, "\0")) {
            throw new PhycodeException('the task is text of 1 to ' . self::MAX_TASK_CHARS . ' characters');
        }
        $folder = realpath($cwd);
        if ($folder === false || !is_dir($folder)) {
            throw new PhycodeException("the project folder does not exist: {$cwd}");
        }
        foreach (['profile' => $profile, 'agent' => $agent] as $label => $value) {
            if ($value !== null && preg_match(self::WORD, $value) !== 1) {
                throw new PhycodeException("the {$label} must be a name");
            }
        }
        $jobs = $this->all();
        if (!isset($jobs[$name]) && count($jobs) >= self::MAX_JOBS) {
            throw new PhycodeException('there are ' . self::MAX_JOBS . ' jobs already; remove one first');
        }
        $jobs[$name] = ['cron' => $expression->source, 'task' => $task, 'cwd' => $folder, 'yolo' => $yolo, 'profile' => $profile, 'agent' => $agent];
        $this->save($jobs);

        return $jobs[$name];
    }

    public function remove(string $name): bool
    {
        $jobs = $this->all();
        if (!isset($jobs[$name])) {
            return false;
        }
        unset($jobs[$name]);
        $this->save($jobs);

        return true;
    }

    private function save(array $jobs): void
    {
        if (!is_dir(dirname($this->file)) && !mkdir(dirname($this->file), 0700, true) && !is_dir(dirname($this->file))) {
            throw new PhycodeException('cannot create ' . dirname($this->file));
        }
        Files::writeAtomic($this->file, Json::encode(['jobs' => $jobs], true) . "\n", 0600);
    }
}
