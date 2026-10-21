<?php

declare(strict_types=1);

namespace Phycode\Tools;

final class GitWriteTool extends GitTool
{
    public function name(): string
    {
        return 'git_write';
    }

    public function description(): string
    {
        return 'Change the local repository: add, commit (needs -m), create or safely delete branches, switch branches, create tags, stash, merge, reset (--soft or --mixed), restore --staged. Does not contact remotes. Needs user approval.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Write;
    }

    protected function mode(): string
    {
        return 'write';
    }
}
