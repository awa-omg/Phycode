<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Support\PhycodeException;

final class TodoTool extends BaseTool
{
    public function __construct(private readonly TodoList $list)
    {
    }

    public function name(): string
    {
        return 'todo';
    }

    public function description(): string
    {
        return 'Task list of this session. For work of three steps or more: write the steps, then mark one in_progress at a time and each finished one completed. '
            . 'todos replaces the whole list; without it the list is returned.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'todos' => [
                    'type' => 'array',
                    'description' => 'The whole list, in order',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => ['type' => 'string', 'description' => 'What to do, short'],
                            'status' => ['type' => 'string', 'enum' => TodoList::STATUSES],
                        ],
                        'required' => ['content', 'status'],
                    ],
                ],
            ],
        ];
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Read;
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return $policy->depth === 0;
    }

    public function summarize(array $args): string
    {
        if (!isset($args['todos']) || !is_array($args['todos'])) {
            return 'todo (read)';
        }
        $done = count(array_filter($args['todos'], static fn (mixed $item): bool => is_array($item) && ($item['status'] ?? '') === 'completed'));

        return "todo {$done}/" . count($args['todos']) . ' done';
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        if (isset($args['todos'])) {
            if (!is_array($args['todos'])) {
                return ToolResult::fail('todos must be a list of tasks, each with a content and a status');
            }
            try {
                $this->list->replace($args['todos']);
            } catch (PhycodeException $error) {
                return ToolResult::fail($error->getMessage());
            }
        }
        $counts = $this->list->counts();

        return ToolResult::ok($this->list->text() . ($counts['total'] > 0 ? "\n({$counts['done']} of {$counts['total']} done)" : ''), $counts);
    }
}
