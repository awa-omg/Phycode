<?php

declare(strict_types=1);

namespace Phycode\Btw;

use Phycode\Support\PhycodeException;

final class BtwJobs
{
    public const MAX_RUNNING = 4;

    private array $jobs = [];

    public function __construct(
        private readonly array $command,
        private readonly float $timeout = 300.0,
    ) {
    }

    public function start(string $question, array $request): void
    {
        if (count($this->jobs) >= self::MAX_RUNNING) {
            throw new PhycodeException('four side questions are already running; wait for one to finish');
        }
        $this->jobs[] = BtwJob::start($this->command, $question, $request, $this->timeout);
    }

    public function running(): int
    {
        return count($this->jobs);
    }

    public function poll(): array
    {
        $ended = [];
        $open = [];
        foreach ($this->jobs as $job) {
            if ($job->pump()) {
                $ended[] = $job->result();
            } else {
                $open[] = $job;
            }
        }
        $this->jobs = $open;

        return $ended;
    }

    public function close(): void
    {
        foreach ($this->jobs as $job) {
            $job->terminate();
        }
        $this->jobs = [];
    }
}
