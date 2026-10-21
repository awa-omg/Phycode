<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\AgentRegistry;
use Phycode\Agent\RunPolicy;
use Phycode\Support\Text;

final class TaskTool extends BaseTool
{
    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly bool $enabled,
    ) {
    }

    public function name(): string
    {
        return 'task';
    }

    public function description(): string
    {
        $lines = ['Delegate a self-contained investigation to a subagent. It starts with a fresh context and returns only its final report. Give a complete task: objective, scope and the output you need.', 'Subagents:'];
        foreach ($this->agents->subagents() as $agent) {
            $lines[] = '- ' . $agent->name . ': ' . Text::oneLine($agent->description, 160);
        }

        return implode("\n", $lines);
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Delegate;
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return $this->enabled && $policy->canDelegate() && $this->agents->subagents() !== [];
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'agent' => ['type' => 'string', 'enum' => array_keys($this->agents->subagents()), 'description' => 'Subagent to run'],
                'prompt' => ['type' => 'string', 'description' => 'Complete task: objective, scope, expected output'],
            ],
            'required' => ['agent', 'prompt'],
        ];
    }

    public function summarize(array $args): string
    {
        $agent = is_string($args['agent'] ?? null) ? $args['agent'] : '?';
        $prompt = is_string($args['prompt'] ?? null) ? $args['prompt'] : '';

        return 'task ' . $agent . ': ' . Text::oneLine($prompt, 100);
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        if ($context->delegator === null) {
            return ToolResult::fail('delegation is not available in this run');
        }

        return $context->delegator->delegate(Args::string($args, 'agent'), Args::string($args, 'prompt'), $context);
    }
}
