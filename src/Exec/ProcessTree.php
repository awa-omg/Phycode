<?php

declare(strict_types=1);

namespace Phycode\Exec;

final class ProcessTree
{
    public const MARK = 'PHYCODE_PROCESS_TREE';
    public const HOLDER = 8;
    private const ROUNDS = 8;

    public static function signal(int $pid, int $signal, bool $owned = true, ?string $mark = null, bool $reaper = false): void
    {
        if ($pid > 1 && ($reaper || !@posix_kill(-$pid, $signal)) && $owned) {
            $tree = self::descendants($pid);
            if (!$reaper) {
                @posix_kill($pid, $signal);
            }
            foreach ($tree as $child) {
                @posix_kill(-$child, $signal);
                @posix_kill($child, $signal);
            }
        }
        if ($mark !== null) {
            self::signalMarked($mark, $signal, $reaper ? [$pid] : []);
        }
    }

    public static function alive(int $pid, bool $owned = true): bool
    {
        return $pid > 1 && (@posix_kill(-$pid, 0) || ($owned && @posix_kill($pid, 0)));
    }

    public static function mark(): string
    {
        return bin2hex(random_bytes(12));
    }

    public static function holder(string $mark): array
    {
        return Launch::reaper() === null ? [self::HOLDER => ['file', self::holderPath($mark), 'w']] : [];
    }

    public static function release(string $mark): void
    {
        @unlink(self::holderPath($mark));
    }

    private static function holderPath(string $mark): string
    {
        return rtrim(sys_get_temp_dir(), '/') . '/phycode-tree-' . $mark;
    }

    public static function signalMarked(string $mark, int $signal, array $spared = []): void
    {
        $signalled = [getmypid(), ...$spared];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $fresh = array_diff(self::marked($mark), $signalled);
            if ($fresh === []) {
                return;
            }
            foreach ($fresh as $pid) {
                @posix_kill($pid, $signal);
            }
            array_push($signalled, ...$fresh);
        }
    }

    public static function marked(string $mark): array
    {
        if (preg_match('/^[0-9a-f]{16,}$/', $mark) !== 1) {
            return [];
        }
        $entry = self::MARK . '=' . $mark;
        $holder = 'phycode-tree-' . $mark;
        $found = [];
        if (is_readable('/proc/self/environ')) {
            foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $folder) {
                $environment = @file_get_contents($folder . '/environ');
                if ((is_string($environment) && in_array($entry, explode("\0", $environment), true)) || self::holds($folder, $holder)) {
                    $found[] = (int) basename($folder);
                }
            }

            return $found;
        }
        $lines = [];
        @exec('ps axeww -o pid= -o command= 2>/dev/null', $lines);
        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d+)\s.*(?<=\s)' . $entry . '(?=\s|$)/', $line, $match) === 1) {
                $found[] = (int) $match[1];
            }
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $files = [];
            @exec('lsof -n -P +L1 -F pn 2>/dev/null', $files);
            $pid = 0;
            foreach ($files as $line) {
                if (str_starts_with($line, 'p')) {
                    $pid = (int) substr($line, 1);
                } elseif (str_starts_with($line, 'n') && str_contains($line, $holder)) {
                    $found[] = $pid;
                }
            }
        }

        return array_values(array_unique($found));
    }

    private static function holds(string $folder, string $holder): bool
    {
        foreach (glob($folder . '/fd/*', GLOB_NOSORT) ?: [] as $fd) {
            if (str_contains((string) @readlink($fd), $holder)) {
                return true;
            }
        }

        return false;
    }

    public static function descendants(int $pid): array
    {
        $lines = [];
        @exec('ps -ax -o pid= -o ppid= 2>/dev/null', $lines);
        $children = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $match) === 1) {
                $children[(int) $match[2]][] = (int) $match[1];
            }
        }
        $found = [];
        $queue = [$pid];
        while ($queue !== [] && count($found) < 4096) {
            foreach ($children[array_shift($queue)] ?? [] as $child) {
                if (!in_array($child, $found, true)) {
                    $found[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $found;
    }
}
