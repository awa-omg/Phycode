<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Config\Config;
use Phycode\Exec\SandboxSelector;
use Phycode\Llm\ClientProvider;
use Phycode\Support\Redaction;
use Phycode\Support\Text;
use Phycode\Tools\Delegator;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolRegistry;
use Phycode\Tools\ToolResult;

final class Delegation implements Delegator
{
    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly ClientProvider $clients,
        private readonly ToolRegistry $tools,
        private readonly AgentRunner $runner,
        private readonly PromptBuilder $prompts,
        private readonly Config $config,
        private readonly string $root,
        private readonly ?string $instructions,
        private readonly ?SandboxSelector $sandboxes = null,
    ) {
    }

    public function delegate(string $agent, string $task, ToolContext $parent): ToolResult
    {
        $definition = $this->agents->get($agent);
        if ($definition === null || !$definition->isSubagent()) {
            $names = implode(', ', array_keys($this->agents->subagents()));

            return ToolResult::fail("unknown subagent '{$agent}'. Available subagents: {$names}");
        }
        if (!$parent->policy->canDelegate()) {
            return ToolResult::fail('subagents cannot start other subagents');
        }
        $task = trim($task);
        if (mb_strlen($task) < 20) {
            return ToolResult::fail('the task is too short: state the objective, the scope and the output you need');
        }

        $profile = $parent->profile;
        if ($definition->profile !== null) {
            if ($this->config->hasProfile($definition->profile)) {
                $profile = $definition->profile;
            } else {
                $parent->ui->notice('warn', "subagent {$definition->name} asks for profile '{$definition->profile}', which is not configured; using '{$profile}'");
            }
        }

        $inherits = $profile === $parent->profile;
        $model = $inherits ? $parent->model : null;
        $thinking = $inherits ? $parent->thinking : null;
        if (($model ?? $this->config->profile($profile)->model) === '') {
            return ToolResult::fail("profile '{$profile}' has no model: choose one in /models, or set its model in the config");
        }

        $mode = $this->clients->toolMode($profile, $model, $thinking);
        $policy = RunPolicy::child($parent->policy, $definition);
        if ($mode === 'none' && $this->tools->available($policy) !== []) {
            return ToolResult::fail("profile '{$profile}' cannot call tools, so subagent {$definition->name} cannot run");
        }

        $cancel = $parent->cancel->child(microtime(true) + $this->config->limits->subagentTimeout);
        $sandbox = $this->sandboxes?->runnerFor($definition->sandbox) ?? $parent->sandbox;
        $context = $parent->forAgent($definition, $policy, $cancel, $profile, $sandbox, $model, $thinking);

        $conversation = new Conversation();
        $conversation->setSystem($this->prompts->subagent($definition, $mode, $this->tools, $policy, $this->root, $this->instructions));
        $conversation->addUser($task);

        $setup = new RunSetup(
            client: $this->clients->forProfile($profile, $model, $thinking),
            toolMode: $mode,
            registry: $this->tools,
            context: $context,
            maxTurns: $definition->maxTurns ?? $this->config->limits->subagentMaxTurns,
            streamText: false,
            overrides: $definition->temperature !== null ? ['temperature' => $definition->temperature] : [],
        );

        try {
            $parent->ui->subagentStart($parent->agent->name, $definition->name, $task);
            $parent->transcript->event(
                'subagent_start',
                ['agent' => $definition->name, 'profile' => $profile, 'mode' => $mode, 'task' => $task],
                $parent->agent->name,
                $parent->depth(),
            );
            $outcome = $this->runner->run($conversation, $setup);
        } catch (\Throwable $error) {
            $reason = Redaction::text($error->getMessage());
            $parent->ui->subagentEnd($parent->agent->name, $definition->name, OutcomeStatus::Error->value, $reason);
            $parent->transcript->event(
                'subagent_end',
                ['agent' => $definition->name, 'status' => OutcomeStatus::Error->value, 'reason' => $reason, 'turns' => null, 'tool_calls' => null, 'report' => ''],
                $parent->agent->name,
                $parent->depth(),
            );

            throw $error;
        }
        $reason = $outcome->reason === null ? null : Redaction::text($outcome->reason);
        $report = Text::clip(trim($outcome->text), $this->config->limits->subagentResultBytes);
        if ($outcome->status === OutcomeStatus::Completed && $outcome->toolCalls === 0 && $this->tools->available($policy) !== []) {
            $report = "[note: this subagent used no tools, so its report may not be based on the project]\n" . $report;
        }
        $stats = "{$outcome->turns} turns, {$outcome->toolCalls} tool calls";

        $parent->ui->subagentEnd($parent->agent->name, $definition->name, $outcome->status->value, $reason ?? $stats);
        $parent->transcript->event(
            'subagent_end',
            ['agent' => $definition->name, 'status' => $outcome->status->value, 'reason' => $reason, 'turns' => $outcome->turns, 'tool_calls' => $outcome->toolCalls, 'report' => $report],
            $parent->agent->name,
            $parent->depth(),
        );

        $meta = ['status' => $outcome->status->value, 'reason' => $reason, 'usage' => $outcome->lastUsage];

        return match ($outcome->status) {
            OutcomeStatus::Completed => ToolResult::ok("[subagent {$definition->name} finished: {$stats}]\n{$report}", $meta),
            OutcomeStatus::Budget => ToolResult::ok("[subagent {$definition->name} reached its turn budget; partial report: {$stats}]\n{$report}", $meta),
            default => ToolResult::fail(
                "subagent {$definition->name} stopped ({$outcome->status->value}): " . ($reason ?? 'no reason')
                . ($report !== '' ? "\n{$report}" : ''),
                $meta,
            ),
        };
    }
}
