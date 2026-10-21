<?php

declare(strict_types=1);

namespace Phycode\Context;

use Phycode\Security\PathException;
use Phycode\Security\Workspace;
use Phycode\Support\Text;

final class AgentsMd
{
    public static function load(string $cwd, string $root, string $configDir, int $maxBytes, array &$notices = []): ?string
    {
        $parts = [];
        $global = self::instructionFile($configDir);
        if ($global !== null) {
            $parts[] = (string) file_get_contents($global);
        }

        $cwd = realpath($cwd) ?: $cwd;
        $root = realpath($root) ?: $root;
        $workspace = new Workspace($root);
        $chain = [];
        $folder = $cwd;
        while (true) {
            $chain[] = $folder;
            if ($folder === $root || dirname($folder) === $folder) {
                break;
            }
            $folder = dirname($folder);
        }

        foreach (array_reverse($chain) as $folder) {
            $candidate = self::instructionFile($folder);
            if ($candidate === null) {
                continue;
            }
            $real = self::contained($candidate, $workspace, $notices);
            if ($real !== null) {
                $parts[] = (string) file_get_contents($real);
            }
        }

        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return null;
        }

        $dropped = 0;
        while (count($parts) > 1 && strlen(implode("\n\n", $parts)) > $maxBytes) {
            array_shift($parts);
            $dropped++;
        }
        if ($dropped > 0) {
            $notices[] = "project instructions: {$dropped} farther file(s) left out, the levels are over {$maxBytes} bytes";
        }

        return Text::clip(implode("\n\n", $parts), $maxBytes);
    }

    private static function instructionFile(string $folder): ?string
    {
        foreach (['AGENTS.md', 'CLAUDE.md'] as $name) {
            if (is_file($folder . '/' . $name)) {
                return $folder . '/' . $name;
            }
        }

        return null;
    }

    private static function contained(string $path, Workspace $workspace, array &$notices): ?string
    {
        try {
            return $workspace->resolve($path);
        } catch (PathException) {
            $notices[] = "{$path}: skipped: it is a symbolic link that does not lead to a file inside the project";

            return null;
        }
    }
}
