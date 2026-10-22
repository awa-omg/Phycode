<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Security\SecretFiles;
use Phycode\Support\Glob;

final class GlobTool extends BaseTool
{
    private const MAX_RESULTS = 200;

    public function name(): string
    {
        return 'glob';
    }

    public function description(): string
    {
        return 'Find files by name pattern, newest first. * matches within a folder name, ** matches any folders, ? one character, {a,b} either. '
            . 'A pattern without a slash, such as *.php, matches the file name in any folder; with a slash, such as src/**/*Test.php, it matches the path under the folder given. '
            . 'Inside git repositories it respects .gitignore.';
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
                'pattern' => ['type' => 'string', 'description' => 'The pattern, for example **/*.test.ts or src/**/*.php'],
                'path' => ['type' => 'string', 'description' => 'Folder to search, relative to the project root. Default: .'],
            ],
            'required' => ['pattern'],
        ];
    }

    public function forcesApproval(array $args): bool
    {
        return is_string($args['path'] ?? null) && SecretFiles::guarded($args['path']);
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $pattern = Args::string($args, 'pattern');
        if (!Glob::valid($pattern)) {
            return ToolResult::fail('pattern is empty, longer than ' . Glob::MAX_LENGTH . ' characters, or has too many ** in it');
        }
        $path = Args::string($args, 'path', false);
        $directory = $context->workspace->resolve($path === '' ? '.' : $path);
        if (!is_dir($directory)) {
            return ToolResult::fail("not a folder: {$path}");
        }
        $relative = $context->workspace->relative($directory);
        $files = ProjectFiles::listed($context, $directory, $relative) ?? ProjectFiles::walked($directory, $context->workspace);

        $base = $relative === '.' ? '' : $relative . '/';
        $matches = [];
        foreach ($files as $file) {
            if (($base !== '' && !str_starts_with($file, $base)) || SecretFiles::guarded($file)) {
                continue;
            }
            if (Glob::matches($pattern, substr($file, strlen($base)))) {
                $matches[$file] = (int) @filemtime($context->workspace->root . '/' . $file);
            }
        }
        if ($matches === []) {
            return ToolResult::ok('no file matches', ['count' => 0]);
        }
        uksort($matches, static fn (string $a, string $b): int => [$matches[$b], $a] <=> [$matches[$a], $b]);
        $total = count($matches);
        $shown = array_slice(array_keys($matches), 0, self::MAX_RESULTS);

        return ToolResult::ok(implode("\n", $shown) . ($total > self::MAX_RESULTS ? "\n[... " . ($total - self::MAX_RESULTS) . ' more files; narrow the pattern or the folder]' : ''), ['count' => $total]);
    }
}
