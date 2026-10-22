<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Memory\MemoryStore;
use Phycode\Support\PhycodeException;

final class MemoryTool extends BaseTool
{
    private const ACTIONS = ['list', 'read'];

    public function __construct(private readonly MemoryStore $store, private readonly bool $enabled)
    {
    }

    public function name(): string
    {
        return 'memory';
    }

    public function description(): string
    {
        return 'Read the notes that earlier sessions saved. list shows every note as scope/name: description; read (scope, name) returns one note in full. '
            . 'The index in the system prompt already lists the notes, so read the ones that matter for the task.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS, 'description' => 'list or read'],
                'scope' => ['type' => 'string', 'enum' => MemoryStore::SCOPES, 'description' => 'project is this project only; user is every project. read needs it; list shows both when it is omitted'],
                'name' => ['type' => 'string', 'description' => 'read: the name of the note, as the list shows it'],
            ],
            'required' => ['action'],
        ];
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Read;
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return $this->enabled && $policy->depth === 0;
    }

    public function summarize(array $args): string
    {
        $action = is_string($args['action'] ?? null) ? $args['action'] : '?';
        $name = is_string($args['name'] ?? null) ? ' ' . ($args['scope'] ?? '?') . '/' . $args['name'] : '';

        return "memory {$action}{$name}";
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        try {
            $action = Args::string($args, 'action');
            $scope = Args::string($args, 'scope', false);
            if ($scope !== '' && !in_array($scope, MemoryStore::SCOPES, true)) {
                throw new PhycodeException('scope is project or user, not \'' . $scope . '\'');
            }

            return match ($action) {
                'list' => $this->list($scope === '' ? MemoryStore::SCOPES : [$scope]),
                'read' => $this->read($scope, Args::string($args, 'name')),
                default => throw new PhycodeException('action must be list or read'),
            };
        } catch (PhycodeException $error) {
            return ToolResult::fail($error->getMessage());
        }
    }

    private function list(array $scopes): ToolResult
    {
        $lines = [];
        foreach ($scopes as $scope) {
            foreach ($this->store->all($scope) as $note) {
                $lines[] = "{$scope}/{$note['name']}: {$note['description']}";
            }
        }

        return ToolResult::ok($lines === [] ? 'no notes are saved' : implode("\n", $lines), ['notes' => count($lines)]);
    }

    private function read(string $scope, string $name): ToolResult
    {
        if ($scope === '') {
            throw new PhycodeException('read needs scope: project or user');
        }
        $note = $this->store->read($scope, $name);
        if ($note === null) {
            throw new PhycodeException("no note named {$scope}/{$name}; the list action shows the saved ones");
        }

        return ToolResult::ok("{$scope}/{$note['name']}: {$note['description']}\n\n{$note['body']}");
    }
}
