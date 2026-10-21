<?php

declare(strict_types=1);

namespace Phycode\Exec;

final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public ?string $interrupted,
        public float $seconds,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0 && $this->interrupted === null;
    }
}
