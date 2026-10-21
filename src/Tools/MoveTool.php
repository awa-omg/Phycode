<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Security\SecretFiles;
use Phycode\Support\PhycodeException;

final class MoveTool extends BaseTool
{
    public function name(): string
    {
        return 'move';
    }

    public function description(): string
    {
        return 'Move or rename a file or folder. Never overwrites an existing destination. Needs user approval.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Write;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Current path relative to the project root'],
                'to' => ['type' => 'string', 'description' => 'New path relative to the project root'],
            ],
            'required' => ['from', 'to'],
        ];
    }

    public function forcesApproval(array $args): bool
    {
        foreach (['from', 'to'] as $key) {
            if (is_string($args[$key] ?? null) && SecretFiles::guarded($args[$key])) {
                return true;
            }
        }

        return false;
    }

    public function validate(array $args, ToolContext $context): void
    {
        $this->plan($args, $context);
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $from = Args::string($args, 'from');
        $to = Args::string($args, 'to');

        [$source, $target] = $this->plan($args, $context);

        if (!self::exists($source)) {
            return ToolResult::fail("nothing to move: {$from}");
        }
        if (self::exists($target)) {
            return ToolResult::fail("destination exists, move does not overwrite: {$to}");
        }
        try {
            self::keep($source, $target, $context);
        } catch (PhycodeException $error) {
            return ToolResult::fail("cannot keep the content of {$from} for undo; nothing was moved: " . $error->getMessage());
        }
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ToolResult::fail("cannot create folder for {$to}");
        }
        if (!rename($source, $target)) {
            return ToolResult::fail("cannot move {$from} to {$to}");
        }

        $context->changes->add($context->workspace->relative($source));
        $context->changes->add($context->workspace->relative($target));

        return ToolResult::ok("moved {$from} to {$to}");
    }

    private function plan(array $args, ToolContext $context): array
    {
        $from = Args::string($args, 'from');
        $source = $context->workspace->resolveEntry($from);
        $target = $context->workspace->resolveEntry(Args::string($args, 'to'));
        if (is_link($source)) {
            $context->workspace->resolve($from);
        } elseif (is_dir($source)) {
            $context->workspace->assertTreeUnprotected($source);
        }

        return [$source, $target];
    }

    private static function keep(string $source, string $target, ToolContext $context): void
    {
        $store = $context->checkpoints;
        if ($store === null) {
            return;
        }
        foreach (self::entriesUnder($source) as $entry) {
            $content = is_file($entry) ? file_get_contents($entry) : null;
            if ($content === false) {
                throw new PhycodeException('cannot read ' . $context->workspace->relative($entry));
            }
            $store->record($context->workspace->relative($entry), $content);
            $store->record($context->workspace->relative($target . substr($entry, strlen($source))), null);
        }
    }

    private static function entriesUnder(string $entry): array
    {
        if (!is_dir($entry) || is_link($entry)) {
            return [$entry];
        }
        $names = scandir($entry);
        if ($names === false) {
            throw new PhycodeException("cannot list {$entry}");
        }
        $entries = [];
        foreach (array_diff($names, ['.', '..']) as $name) {
            $entries = [...$entries, ...self::entriesUnder($entry . '/' . $name)];
        }

        return $entries;
    }

    private static function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }
}
