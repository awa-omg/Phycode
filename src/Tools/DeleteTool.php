<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Diff\TextDiff;
use Phycode\Security\SecretFiles;
use Phycode\Support\Files;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class DeleteTool extends BaseTool
{
    public function name(): string
    {
        return 'delete';
    }

    public function description(): string
    {
        return 'Delete one file. The file is moved to .phycode/trash so it can be recovered. Needs user approval.';
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
                'path' => ['type' => 'string', 'description' => 'File path relative to the project root'],
            ],
            'required' => ['path'],
        ];
    }

    public function forcesApproval(array $args): bool
    {
        return is_string($args['path'] ?? null) && SecretFiles::guarded($args['path']);
    }

    public function validate(array $args, ToolContext $context): void
    {
        $this->plan($args, $context);
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $path = Args::string($args, 'path');
        $entry = $this->plan($args, $context);
        $link = is_link($entry);
        if (!$link && !is_file($entry)) {
            return ToolResult::fail("not a file: {$path}. Folders are not deleted by this tool.");
        }
        $diff = $link ? null : self::removal($entry, $context);
        $previous = $link ? null : file_get_contents($entry);
        if ($previous === false) {
            return ToolResult::fail("cannot read {$path}; nothing was removed");
        }
        try {
            $context->checkpoints?->record($context->workspace->relative($entry), $previous);
        } catch (PhycodeException $error) {
            return ToolResult::fail("cannot keep the content of {$path} for undo; nothing was removed: " . $error->getMessage());
        }

        $stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $relative = $context->workspace->relative($entry);
        $trashed = $context->workspace->root . '/.phycode/trash/' . $stamp . '/' . $relative;
        if (!is_dir(dirname($trashed)) && !mkdir(dirname($trashed), 0700, true)) {
            return ToolResult::fail('cannot create the trash folder');
        }
        if (!rename($entry, $trashed)) {
            return ToolResult::fail("cannot move {$path} to the trash");
        }

        $context->changes->add($relative);
        $what = $link ? "the symbolic link {$relative}; its target was not changed" : $relative;

        return ToolResult::ok("deleted {$what}; recoverable at .phycode/trash/{$stamp}/{$relative}")->withDiff($diff);
    }

    public function preview(array $args, ToolContext $context): ?array
    {
        try {
            $entry = $context->workspace->resolveEntry(Args::string($args, 'path'));
        } catch (PhycodeException) {
            return null;
        }

        return is_link($entry) ? null : self::removal($entry, $context);
    }

    private static function removal(string $entry, ToolContext $context): ?array
    {
        $text = is_file($entry) ? @file_get_contents($entry) : false;
        if (!is_string($text) || Text::isBinary(substr($text, 0, 8192))) {
            return null;
        }

        return TextDiff::change($context->workspace->relative($entry), $text, null);
    }

    private function plan(array $args, ToolContext $context): string
    {
        $path = Args::string($args, 'path');
        $entry = $context->workspace->resolveEntry($path);
        if (is_link($entry)) {
            $context->workspace->resolve($path);
        }
        $context->workspace->resolve('.phycode/trash');
        Files::runtimeFolder($context->workspace->root, 'trash');

        return $entry;
    }
}
