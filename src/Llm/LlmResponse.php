<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Llm\Canonical\Usage;

final readonly class LlmResponse
{
    public function __construct(
        public string $content,
        public string $reasoning,
        public array $toolCalls,
        public string $finishReason,
        public Usage $usage,
        public array $extra = [],
    ) {
    }
}
