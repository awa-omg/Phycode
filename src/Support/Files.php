<?php

declare(strict_types=1);

namespace Phycode\Support;

use Phycode\Security\PathException;

final class Files
{
    public static function writeAtomic(string $path, string $content, ?int $mode = null): void
    {
        $temporary = dirname($path) . '/.phycode-' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new PhycodeException('cannot write a temporary file next to ' . $path);
        }
        if ($mode !== null) {
            chmod($temporary, $mode & 0777);
        }
        $written = fwrite($handle, $content);
        fclose($handle);
        if ($written !== strlen($content) || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new PhycodeException('cannot replace ' . $path);
        }
    }

    public static function runtimeFolder(string $root, string $name): string
    {
        $phycode = $root . '/.phycode';
        $folder = $phycode . '/' . $name;
        foreach (['.phycode' => $phycode, ".phycode/{$name}" => $folder] as $relative => $path) {
            if (is_link($path)) {
                $target = readlink($path);
                throw new PathException(
                    "{$relative} is a symbolic link to " . ($target === false ? 'another place' : $target)
                    . '; phycode keeps its own files only in real folders of the project',
                );
            }
        }

        return $folder;
    }

    public static function lineCount(string $content): int
    {
        if ($content === '') {
            return 0;
        }

        return substr_count($content, "\n") + (str_ends_with($content, "\n") ? 0 : 1);
    }
}
