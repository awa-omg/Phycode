<?php

declare(strict_types=1);

namespace Phycode\Security;

use Phycode\Exec\ContainerRunner;
use Phycode\Support\PhycodeException;
use Phycode\Tools\Tool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use Phycode\Ui\Ui;

final class Permissions
{
    private array $sessionAllowed = [];

    public function __construct(private readonly Ui $ui, private readonly Rules $rules = new Rules())
    {
    }

    public function check(Tool $tool, ToolContext $context, string $summary, array $args = []): ?string
    {
        $name = $tool->name();
        $category = $tool->category();
        $mode = $context->policy->mode;

        $deny = $this->rules->denying($name, $summary);
        if ($deny !== null) {
            return "refused by the rule '{$deny}' in rules.deny";
        }
        if (in_array($name, ShellCommand::TOOLS, true) && ($name !== 'bash' || !($context->sandbox instanceof ContainerRunner))) {
            $hard = HardLine::reason($summary, $context->workspace->root);
            if ($hard !== null) {
                return "refused: {$hard} is never run, in any mode and with any approval";
            }
        }
        if ($context->policy->denies($category)) {
            return "agent '{$context->agent->name}' is read-only and may not run {$name}";
        }
        $forced = $tool->forcesApproval($args);
        if ($mode === 'yolo' && !$forced) {
            return null;
        }
        if ($mode === 'plan' && $category !== ToolCategory::Read) {
            return "plan mode allows only read tools, so {$name} is refused";
        }

        $ask = $forced ? 'forced by the tool' : $this->rules->asking($name, $summary);
        if ($ask === null) {
            if ($this->rules->allowing($name, $summary) !== null || isset($this->sessionAllowed[$name])) {
                return null;
            }
            if ($category === ToolCategory::Read || $category === ToolCategory::Delegate) {
                return null;
            }
            if ($mode === 'accept_edits' && $category === ToolCategory::Write) {
                return null;
            }
            if ($mode === 'ask' && $tool->autoApproved()) {
                return null;
            }
        }
        if ($mode === 'dont_ask') {
            return "dont_ask mode refuses {$name} without asking; it needs approval";
        }
        if (!$this->ui->interactive()) {
            return 'no terminal to ask for confirmation; run in chat mode or pass --yolo';
        }

        $request = new ConfirmRequest($name, $category, $context->agent->name, $context->depth(), $summary, self::preview($tool, $args, $context));
        $decision = $this->ui->confirm($request);
        if ($decision === Decision::AllowSession) {
            if (!$forced) {
                $this->sessionAllowed[$name] = true;
            }

            return null;
        }

        return $decision === Decision::Deny ? 'the user denied this action' : null;
    }

    private static function preview(Tool $tool, array $args, ToolContext $context): ?array
    {
        try {
            return $tool->preview($args, $context);
        } catch (PhycodeException) {
            return null;
        }
    }
}
