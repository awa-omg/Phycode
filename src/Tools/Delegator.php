<?php

declare(strict_types=1);

namespace Phycode\Tools;

interface Delegator
{
    public function delegate(string $agent, string $task, ToolContext $parent): ToolResult;
}
