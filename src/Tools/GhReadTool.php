<?php

declare(strict_types=1);

namespace Phycode\Tools;

final class GhReadTool extends GhTool
{
    public function name(): string
    {
        return 'gh_read';
    }

    public function description(): string
    {
        return 'Reads from GitHub with the gh CLI: pr view/list/checks/diff/status, issue view/list, run list/view (run view --log-failed shows why CI failed), '
            . 'repo view, auth status. Ask for fields with --json number,title,state. Nothing is changed.';
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
