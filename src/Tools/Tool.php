<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;

interface Tool
{
    public function name(): string;

    public function description(): string;

    public function parameters(): array;

    public function category(): ToolCategory;

    public function summarize(array $args): string;

    public function validate(array $args, ToolContext $context): void;

    public function execute(array $args, ToolContext $context): ToolResult;

    public function preview(array $args, ToolContext $context): ?array;

    public function isAvailable(RunPolicy $policy): bool;

    public function autoApproved(): bool;

    public function forcesApproval(array $args): bool;
}
