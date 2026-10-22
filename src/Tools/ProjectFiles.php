<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Security\Workspace;

final class ProjectFiles
{
    public const IGNORED = ['.git', '.phycode', 'node_modules', 'vendor', '__pycache__', '.venv'];

    public static function listed(ToolContext $context, string $directory, string $relative): ?array
    {
        $root = $context->workspace->root;
        if ($context->workspace->readOnlyFolder($directory) !== null || !is_dir($root . '/.git')) {
            return null;
        }
        $argv = ['git', '-C', $root, 'ls-files', '--cached', '--others', '--exclude-standard', '-z'];
        if ($relative !== '.') {
            $argv[] = '--';
            $argv[] = $relative;
        }
        $result = $context->processes->run($argv, $root, 20, 4194304, $context->cancel);
        if (!$result->succeeded()) {
            return null;
        }

        return array_values(array_filter(explode("\0", $result->stdout), static fn (string $file): bool => $file !== ''));
    }

    public static function walked(string $directory, Workspace $workspace): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $entry): bool => !in_array($entry->getFilename(), self::IGNORED, true),
        ));
        $files = [];
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $files[] = $workspace->relative($entry->getPathname());
            }
        }

        return $files;
    }
}
