<?php

declare(strict_types=1);

namespace Phycode\Gentle;

use Phycode\Security\ConfirmRequest;
use Phycode\Security\Decision;
use Phycode\Tools\ToolCategory;
use Phycode\Ui\Ui;

final class Approval
{
    public function __construct(private readonly Ui $ui, private readonly \Closure $panel)
    {
    }

    public function __invoke(string $title, string $details, string $question): bool
    {
        ($this->panel)($title, $details);
        if (!$this->ui->interactive()) {
            return false;
        }

        return $this->ui->confirm(new ConfirmRequest('phycode', ToolCategory::Exec, 'phycode', 0, $question)) !== Decision::Deny;
    }
}
