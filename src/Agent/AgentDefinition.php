<?php

declare(strict_types=1);

namespace Phycode\Agent;

final readonly class AgentDefinition
{
    public function __construct(
        public string $name,
        public string $description,
        public string $mode,
        public ?array $tools,
        public array $excludedTools,
        public array $deniedCategories,
        public ?string $profile,
        public ?int $maxTurns,
        public ?float $temperature,
        public string $prompt,
        public string $source,
        public string $path,
        public array $warnings,
        public ?string $sandbox = null,
    ) {
    }

    public function isPrimary(): bool
    {
        return $this->mode !== 'subagent';
    }

    public function isSubagent(): bool
    {
        return $this->mode !== 'primary';
    }
}
