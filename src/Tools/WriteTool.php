<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Diff\TextDiff;
use Phycode\Security\SecretFiles;
use Phycode\Support\Files;
use Phycode\Support\PhycodeException;

final class WriteTool extends BaseTool
{
    private const MAX_BYTES = 524288;

    public function name(): string
    {
        return 'write';
    }

    public function description(): string
    {
        return 'Create a file, or replace its whole content. Missing folders are created. Needs user approval.';
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
                'content' => ['type' => 'string', 'description' => 'Full content of the file'],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function summarize(array $args): string
    {
        $path = is_string($args['path'] ?? null) ? $args['path'] : '?';
        $content = is_string($args['content'] ?? null) ? $args['content'] : '';

        $lines = Files::lineCount($content);

        return 'write ' . $path . ' (' . $lines . ($lines === 1 ? ' line)' : ' lines)');
    }

    public function forcesApproval(array $args): bool
    {
        return is_string($args['path'] ?? null) && SecretFiles::matches($args['path']);
    }

    public function validate(array $args, ToolContext $context): void
    {
        $context->workspace->resolveWritable(Args::string($args, 'path'));
    }

    public function preview(array $args, ToolContext $context): ?array
    {
        try {
            $file = $context->workspace->resolveWritable(Args::string($args, 'path'));
            $content = Args::string($args, 'content');
        } catch (PhycodeException) {
            return null;
        }
        if (is_dir($file) || strlen($content) > self::MAX_BYTES) {
            return null;
        }
        $previous = null;
        if (is_file($file)) {
            $previous = @file_get_contents($file);
            if ($previous === false) {
                return null;
            }
        }

        return TextDiff::change($context->workspace->relative($file), $previous, $content);
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $path = Args::string($args, 'path');
        $content = Args::string($args, 'content');
        if (strlen($content) > self::MAX_BYTES) {
            return ToolResult::fail('content is larger than 512 KB; split the file or use edit');
        }

        $file = $context->workspace->resolveWritable($path);
        if (is_dir($file)) {
            return ToolResult::fail("{$path} is a folder");
        }

        $existed = is_file($file);
        $previous = null;
        if ($existed) {
            $previous = file_get_contents($file);
            if ($previous === false) {
                return ToolResult::fail("cannot read the current content of {$path}; nothing was written");
            }
        }
        try {
            $context->checkpoints?->record($context->workspace->relative($file), $previous);
        } catch (PhycodeException $error) {
            return ToolResult::fail("cannot keep the content of {$path} for undo; nothing was written: " . $error->getMessage());
        }
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ToolResult::fail("cannot create folder for {$path}");
        }

        $lines = Files::lineCount($content);
        $message = ($existed ? 'overwrote ' : 'created ') . $context->workspace->relative($file)
            . ' (' . $lines . ($lines === 1 ? ' line)' : ' lines)');

        $mode = $existed ? (int) fileperms($file) : (SecretFiles::matches($file) ? 0600 : null);
        $result = SyntaxCheck::commit($file, $content, $previous, $mode, $message, $context);
        if ($result->ok) {
            $context->changes->add($context->workspace->relative($file));

            return $result->withDiff(TextDiff::change($context->workspace->relative($file), $previous, $content));
        }

        return $result;
    }
}
