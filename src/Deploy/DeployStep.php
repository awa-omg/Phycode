<?php

declare(strict_types=1);

namespace Phycode\Deploy;

final readonly class DeployStep
{
    public const RUN = 'run';
    public const COPY = 'copy';

    public function __construct(
        public string $label,
        public array $argv,
        public string $type = self::RUN,
        public ?string $host = null,
        public array $env = [],
        public bool $optional = false,
        public bool $bounded = false,
        public ?int $timeout = null,
        public ?string $directory = null,
    ) {
    }

    public function display(): string
    {
        return $this->host === null ? $this->line() : "[{$this->host}] {$this->line()}";
    }

    public function line(): string
    {
        $words = array_map(static fn (string $word): string => preg_match('~^[A-Za-z0-9_@%+=:,./-]+$~', $word) === 1 ? $word : "'" . str_replace("'", "'\\''", $word) . "'", $this->argv);

        return ($this->type === self::COPY ? 'copy ' : '') . implode(' ', $words);
    }
}
