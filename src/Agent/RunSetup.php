<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Llm\ChatClient;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolRegistry;

final readonly class RunSetup
{
    public function __construct(
        public ChatClient $client,
        public string $toolMode,
        public ToolRegistry $registry,
        public ToolContext $context,
        public int $maxTurns,
        public bool $streamText,
        public array $overrides = [],
    ) {
    }
}
