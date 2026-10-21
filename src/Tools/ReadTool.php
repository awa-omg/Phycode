<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Llm\Image;
use Phycode\Security\SecretFiles;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class ReadTool extends BaseTool
{
    private const MAX_FILE_BYTES = 5242880;

    public function name(): string
    {
        return 'read';
    }

    public function description(): string
    {
        return 'Read a text file. Returns numbered lines. Use offset and limit for long files. An image file (PNG, JPEG, GIF, WebP) is shown to you.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Read;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'File path relative to the project root'],
                'offset' => ['type' => 'integer', 'description' => 'First line to show, starting at 1'],
                'limit' => ['type' => 'integer', 'description' => 'Number of lines to show, at most 2000'],
            ],
            'required' => ['path'],
        ];
    }

    public function forcesApproval(array $args): bool
    {
        return is_string($args['path'] ?? null) && SecretFiles::guarded($args['path']);
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $path = Args::string($args, 'path');
        $offset = Args::int($args, 'offset', 1, 10000000, 1);
        $limit = Args::int($args, 'limit', 1, 2000, $context->limits->readMaxLines);

        $file = $context->workspace->resolve($path);
        $real = $context->workspace->relative($file);
        if (SecretFiles::guarded($real) && !SecretFiles::guarded($path)) {
            return ToolResult::fail("refused: {$path} leads to {$real}, which usually holds secrets or phycode's own files; read {$real} by its own name, which asks first");
        }
        if (!is_file($file)) {
            return ToolResult::fail("not a file: {$path}. Use list or grep to find files.");
        }
        $head = (string) file_get_contents($file, false, null, 0, 16);
        if (Image::sniff($head) !== null) {
            try {
                $image = Image::fromFile($file, $path);
            } catch (PhycodeException $error) {
                return ToolResult::fail($error->getMessage());
            }

            return ToolResult::ok(sprintf('image %s: %s %dx%d, %d KB. It is shown to you next.', $path, Image::TYPES[$image->mime], $image->width, $image->height, intdiv($image->bytes, 1024)), ['images' => [$image->toArray()]]);
        }
        if ((int) filesize($file) > self::MAX_FILE_BYTES) {
            return ToolResult::fail("file is larger than 5 MB: {$path}. Use grep to find the part you need.");
        }
        if (Text::isBinary((string) file_get_contents($file, false, null, 0, 8192))) {
            return ToolResult::fail("binary file: {$path}");
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $total = count($lines);
        if ($total === 0) {
            return ToolResult::ok('[empty file]', ['lines' => 0]);
        }
        if ($offset > $total) {
            return ToolResult::fail("offset {$offset} is past the end of {$path} ({$total} lines)");
        }

        $slice = array_slice($lines, $offset - 1, $limit);
        $output = [];
        foreach ($slice as $index => $line) {
            $output[] = sprintf('%6d  %s', $offset + $index, rtrim($line, "\r"));
        }
        $last = $offset + count($slice) - 1;
        $footer = $last < $total ? "\n[lines {$offset}-{$last} of {$total}; call read with offset=" . ($last + 1) . ' to continue]' : '';

        return ToolResult::ok(implode("\n", $output) . $footer, ['lines' => $total]);
    }
}
