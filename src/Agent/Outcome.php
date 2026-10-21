<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Llm\Canonical\Usage;

final readonly class Outcome
{
    public function __construct(
        public OutcomeStatus $status,
        public string $text,
        public int $turns,
        public int $toolCalls,
        public ?string $reason = null,
        public ?Usage $lastUsage = null,
    ) {
    }
}
