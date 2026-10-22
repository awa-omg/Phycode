<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Support\Text;

abstract class BaseTool implements Tool
{
    public function summarize(array $args): string
    {
        $parts = [];
        foreach ($args as $key => $value) {
            if (is_scalar($value)) {
                $parts[] = $key . '=' . Text::oneLine((string) $value, 80);
            }
        }

        return $this->name() . ($parts === [] ? '' : ' ' . implode(' ', $parts));
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return true;
    }

    public function validate(array $args, ToolContext $context): void
    {
    }

    public function preview(array $args, ToolContext $context): ?array
    {
        return null;
    }

    public function autoApproved(): bool
    {
        return false;
    }

    public function forcesApproval(array $args): bool
    {
        return false;
    }

    public function sandboxed(): bool
    {
        return false;
    }

    public function hardLineCommands(array $args): array
    {
        return [];
    }

    public function hardLineFacts(array $args): ?array
    {
        return null;
    }
}
