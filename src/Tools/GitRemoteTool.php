<?php

declare(strict_types=1);

namespace Phycode\Tools;

final class GitRemoteTool extends GitTool
{
    public function name(): string
    {
        return 'git_remote';
    }

    public function description(): string
    {
        return 'Contact remotes: fetch, pull --ff-only, push. Never forced, never deleting. Needs user approval.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Exec;
    }

    protected function mode(): string
    {
        return 'remote';
    }
}
