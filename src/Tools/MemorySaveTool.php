<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Diff\TextDiff;
use Phycode\Memory\MemoryStore;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class MemorySaveTool extends BaseTool
{
    private const ACTIONS = ['save', 'forget'];

    public function __construct(private readonly MemoryStore $store, private readonly bool $enabled, private readonly string $autonomy = 'ask')
    {
    }

    public function name(): string
    {
        return 'memory_save';
    }

    public function description(): string
    {
        return 'Keep a durable fact for the next sessions, or drop one. save (scope, name, description, body) writes a note; saving a name that exists replaces it. '
            . 'forget (scope, name) deletes a note. Save a preference, a decision or a command that works, one fact for each note, never a secret and never the details of the task at hand. '
            . 'scope project is for this project only; scope user is for every project.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS, 'description' => 'save or forget'],
                'scope' => ['type' => 'string', 'enum' => MemoryStore::SCOPES, 'description' => 'project or user'],
                'name' => ['type' => 'string', 'description' => 'Short name of the note: lowercase words joined by hyphens, such as test-command'],
                'description' => ['type' => 'string', 'description' => 'save: one line, at most ' . MemoryStore::DESCRIPTION_CHARS . ' characters, that says what the note holds; it is what the index shows'],
                'body' => ['type' => 'string', 'description' => 'save: the fact itself, at most ' . MemoryStore::BODY_CHARS . ' characters'],
            ],
            'required' => ['action', 'scope', 'name'],
        ];
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Write;
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return $this->enabled && $policy->depth === 0;
    }

    public function autoApproved(): bool
    {
        return $this->autonomy === 'auto';
    }

    public function forcesApproval(array $args): bool
    {
        return $this->autonomy !== 'auto';
    }

    public function summarize(array $args): string
    {
        $action = is_string($args['action'] ?? null) ? $args['action'] : '?';
        $scope = is_string($args['scope'] ?? null) ? $args['scope'] : '?';
        $name = is_string($args['name'] ?? null) ? $args['name'] : '?';

        return 'memory_save ' . Text::oneLine("{$action} {$scope}/{$name}", 120);
    }

    public function validate(array $args, ToolContext $context): void
    {
        $this->plan($args, $context);
    }

    public function preview(array $args, ToolContext $context): ?array
    {
        try {
            [$scope, $name, $content] = $this->plan($args, $context);
        } catch (PhycodeException) {
            return null;
        }
        $file = $this->store->file($scope, $name);
        $before = is_file($file) ? (string) file_get_contents($file) : null;

        return TextDiff::change("memory/{$scope}/" . MemoryStore::slug($name) . '.md', $before, $content);
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        try {
            [$scope, $name, $content] = $this->plan($args, $context);
            if ($content === null) {
                return $this->store->forget($scope, $name)
                    ? ToolResult::ok("forgot {$scope}/{$name}")
                    : ToolResult::fail("no note named {$scope}/{$name}; the list action of the memory tool shows the saved ones");
            }
            $slug = $this->store->save($scope, $name, Args::string($args, 'description'), Args::string($args, 'body'), $context->secrets);

            return ToolResult::ok("saved {$scope}/{$slug}");
        } catch (PhycodeException $error) {
            return ToolResult::fail($error->getMessage());
        }
    }

    private function plan(array $args, ToolContext $context): array
    {
        $action = Args::string($args, 'action');
        $scope = Args::string($args, 'scope');
        $name = Args::string($args, 'name');
        if (!in_array($action, self::ACTIONS, true)) {
            throw new PhycodeException('action must be save or forget');
        }
        if (!in_array($scope, MemoryStore::SCOPES, true)) {
            throw new PhycodeException('scope is project or user, not \'' . $scope . '\'');
        }
        if ($action === 'forget') {
            return [$scope, MemoryStore::slug($name), null];
        }

        return [$scope, $name, $this->store->render($scope, $name, Args::string($args, 'description'), Args::string($args, 'body'), $context->secrets)];
    }
}
