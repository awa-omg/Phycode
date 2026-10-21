<?php

declare(strict_types=1);

namespace Phycode\Tools;

final class GitReadTool extends GitTool
{
    public function name(): string
    {
        return 'git_read';
    }

    public function description(): string
    {
        return 'Read git state: status, diff, log, show, blame, and listings of branches, tags and remotes. Never changes the repository.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Read;
    }

    protected function mode(): string
    {
        return 'read';
    }
}
