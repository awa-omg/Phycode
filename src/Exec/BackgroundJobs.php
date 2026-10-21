<?php

declare(strict_types=1);

namespace Phycode\Exec;

use Phycode\Support\PhycodeException;

final class BackgroundJobs
{
    private array $jobs = [];
    private int $next = 1;

    public function __construct(
        private readonly string $folder,
        private readonly int $limit = 8,
        private readonly int $lifetime = 3600,
        private readonly int $logLimit = BackgroundJob::MAX_LOG_BYTES,
    ) {
    }

    public function __destruct()
    {
        $this->stopAll();
    }

    public function start(CommandRunner $runner, array $argv, string $cwd, string $label): BackgroundJob
    {
        if (!$runner instanceof Backgroundable) {
            throw new PhycodeException('commands in this sandbox cannot run in the background');
        }
        $running = count(array_filter($this->jobs, static fn (BackgroundJob $job): bool => $job->running()));
        if ($running >= $this->limit) {
            throw new PhycodeException("{$running} jobs are running already, which is the limit; stop one with job(action=stop)");
        }
        if (!is_dir($this->folder) && !@mkdir($this->folder, 0700, true) && !is_dir($this->folder)) {
            throw new PhycodeException('cannot create the folder for the output of jobs');
        }
        $log = $this->folder . '/job-' . $this->next . '-' . bin2hex(random_bytes(4)) . '.log';
        if (@file_put_contents($log, '') === false) {
            throw new PhycodeException('cannot create the output file of the job');
        }
        chmod($log, 0600);

        try {
            $process = $runner->start($argv, $cwd, $log, $this->lifetime);
        } catch (\Throwable $error) {
            @unlink($log);

            throw $error;
        }
        $job = new BackgroundJob($this->next, $label, $log, $process, microtime(true), $this->logLimit);
        $this->jobs[$this->next++] = $job;

        return $job;
    }

    public function get(int $id): ?BackgroundJob
    {
        return $this->jobs[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->jobs);
    }

    public function stopAll(): void
    {
        foreach ($this->jobs as $job) {
            $job->stop();
            $job->forget();
        }
        $this->jobs = [];
    }
}
