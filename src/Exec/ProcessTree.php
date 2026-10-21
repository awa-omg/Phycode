<?php

declare(strict_types=1);

namespace Phycode\Exec;

final class ProcessTree
{
    public static function signal(int $pid, int $signal, bool $owned = true): void
    {
        if ($pid <= 1) {
            return;
        }
        if (@posix_kill(-$pid, $signal) || !$owned) {
            return;
        }
        $tree = self::descendants($pid);
        @posix_kill($pid, $signal);
        foreach ($tree as $child) {
            @posix_kill($child, $signal);
        }
    }

    public static function alive(int $pid, bool $owned = true): bool
    {
        return $pid > 1 && (@posix_kill(-$pid, 0) || ($owned && @posix_kill($pid, 0)));
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
