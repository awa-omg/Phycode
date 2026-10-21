<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Security\Workspace;

final class ListTool extends BaseTool
{
    private const MAX_ENTRIES = 400;
    private const IGNORED = ['.git', '.phycode', 'node_modules', 'vendor', '__pycache__', '.venv'];

    public function name(): string
    {
        return 'list';
    }

    public function description(): string
    {
        return 'List files and folders under a folder, up to a depth. Folders end with /. Inside git repositories it respects .gitignore.';
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
                'path' => ['type' => 'string', 'description' => 'Folder relative to the project root. Default: .'],
                'depth' => ['type' => 'integer', 'description' => 'Folder levels to show, 1 to 6. Default: 2'],
            ],
        ];
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $path = Args::string($args, 'path', false);
        $depth = Args::int($args, 'depth', 1, 6, 2);

        $directory = $context->workspace->resolve($path === '' ? '.' : $path);
        if (!is_dir($directory)) {
            return ToolResult::fail("not a folder: {$path}");
        }
        $relative = $context->workspace->relative($directory);

        $inProject = $context->workspace->readOnlyFolder($directory) === null;
        $entries = $inProject && is_dir($context->workspace->root . '/.git') ? $this->gitEntries($context, $relative, $depth) : null;
        $entries ??= $this->walk($directory, $context->workspace, $depth);
        sort($entries);

        $total = count($entries);
        $entries = array_slice($entries, 0, self::MAX_ENTRIES);
        if ($entries === []) {
            return ToolResult::ok('(empty)');
        }
        $footer = $total > self::MAX_ENTRIES
            ? "\n[... " . ($total - self::MAX_ENTRIES) . ' more entries; narrow the folder or lower depth]'
            : '';

        return ToolResult::ok(implode("\n", $entries) . $footer, ['count' => $total]);
    }

    private function gitEntries(ToolContext $context, string $relative, int $depth): ?array
    {
        $argv = ['git', '-C', $context->workspace->root, 'ls-files', '--cached', '--others', '--exclude-standard', '-z'];
        if ($relative !== '.') {
            $argv[] = '--';
            $argv[] = $relative;
        }
        $result = $context->processes->run($argv, $context->workspace->root, 20, 4194304, $context->cancel);
        if (!$result->succeeded()) {
            return null;
        }

        $base = $relative === '.' ? '' : $relative . '/';
        $entries = [];
        foreach (explode("\0", $result->stdout) as $file) {
            if ($file === '' || ($base !== '' && !str_starts_with($file, $base))) {
                continue;
            }
            $parts = explode('/', substr($file, strlen($base)));
            $levels = count($parts);
            for ($level = 1; $level <= min($depth, $levels - 1); $level++) {
                $entries[] = $base . implode('/', array_slice($parts, 0, $level)) . '/';
            }
            if ($levels <= $depth) {
                $entries[] = $file;
            }
        }

        return array_values(array_unique($entries));
    }

    private function walk(string $directory, Workspace $workspace, int $depth): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $entry): bool => !in_array($entry->getFilename(), self::IGNORED, true),
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        $iterator->setMaxDepth($depth - 1);

        $entries = [];
        foreach ($iterator as $entry) {
            $name = $workspace->relative($entry->getPathname());
            $entries[] = $entry->isDir() ? $name . '/' : $name;
        }

        return $entries;
    }
}
