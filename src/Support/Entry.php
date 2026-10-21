<?php

declare(strict_types=1);

namespace Phycode\Support;

final class Entry
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function phar(): ?string
    {
        $phar = \Phar::running(false);

        return $phar === '' ? null : $phar;
    }

    public static function command(): array
    {
        return [PHP_BINARY, self::phar() ?? self::root() . '/bin/phycode'];
    }

    public static function binFolder(): string
    {
        $phar = self::phar();

        return $phar === null ? self::root() . '/bin' : dirname($phar);
    }

    public static function markdownFiles(string $folder): array
    {
        $names = @scandir($folder);
        if ($names === false) {
            return [];
        }
        $files = [];
        foreach ($names as $name) {
            if (str_ends_with($name, '.md') && is_file($folder . '/' . $name)) {
                $files[] = $folder . '/' . $name;
            }
        }
        sort($files);

        return $files;
    }
}
