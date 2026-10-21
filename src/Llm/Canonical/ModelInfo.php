<?php

declare(strict_types=1);

namespace Phycode\Llm\Canonical;

final readonly class ModelInfo
{
    public function __construct(
        public string $id,
        public string $profile,
        public string $protocol,
        public ?int $contextWindow = null,
        public ?bool $tools = null,
        public array $thinkingLevels = [],
        public ?bool $loaded = null,
        public ?string $source = null,
        public ?string $kind = null,
        public array $effortLevels = [],
    ) {
    }
}
