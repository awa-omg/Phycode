<?php

declare(strict_types=1);

namespace Phycode\Btw;

use Phycode\Security\ConfirmRequest;
use Phycode\Security\Decision;
use Phycode\Ui\Ui;

final class SilentUi implements Ui
{
    public function text(string $chunk): void
    {
    }

    public function output(string $text): void
    {
    }

    public function panel(string $title, string $text): void
    {
    }

    public function listPanel(string $title, array $items, string $hint = ''): void
    {
    }

    public function toolStart(string $agent, int $depth, string $tool, string $summary): void
    {
    }

    public function toolEnd(string $agent, int $depth, string $tool, bool $ok, string $summary, ?array $diff = null): void
    {
    }

    public function subagentStart(string $parent, string $agent, string $task): void
    {
    }

    public function subagentEnd(string $parent, string $agent, string $status, string $summary): void
    {
    }

    public function notice(string $level, string $message): void
    {
    }

    public function confirm(ConfirmRequest $request): Decision
    {
        return Decision::Deny;
    }

    public function ask(string $question, array $options, bool $allowFreeText): ?string
    {
        return null;
    }

    public function interactive(): bool
    {
        return false;
    }
}
