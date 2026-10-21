<?php

declare(strict_types=1);

namespace Phycode\Tools;

final class GhWriteTool extends GhTool
{
    public function name(): string
    {
        return 'gh_write';
    }

    public function description(): string
    {
        return 'Publishes to GitHub with the gh CLI: pr create (with --title and --body, or --fill), pr comment, issue create, issue comment. '
            . 'Push the branch first with git_remote. Never merges or closes. Needs user approval.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Exec;
    }

    protected function mode(): string
    {
        return 'write';
    }
}
