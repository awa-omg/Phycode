<?php

declare(strict_types=1);

namespace Phycode\Security;

use Phycode\Tools\ToolCategory;

final readonly class ConfirmRequest
{
    public function __construct(
        public string $tool,
        public ToolCategory $category,
        public string $agent,
        public int $depth,
        public string $summary,
        public ?array $preview = null,
    ) {
    }
}
