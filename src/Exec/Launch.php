<?php

declare(strict_types=1);

namespace Phycode\Exec;

final class Launch
{
    private const OWN_GROUP = 'exec 3>&2 2>/dev/null; set -m; "$@" 2>&3 3>&- & child=$!; '
        . 'trap \'kill -s TERM -- "-$child" 2>/dev/null\' TERM; trap \'kill -s INT -- "-$child" 2>/dev/null\' INT; trap \'kill -s HUP -- "-$child" 2>/dev/null\' HUP; '
        . 'wait "$child"; status=$?; while kill -0 "$child" 2>/dev/null; do wait "$child"; status=$?; done; '
        . 'kill -s KILL -- "-$child" 2>/dev/null; exit "$status"';

    public static function command(array $argv, int $seconds, string $signal, int $killAfter): array
    {
        $timeout = ProcessRunner::timeoutProgram();
        if ($timeout !== null) {
            return [$timeout, '--signal=' . $signal, '--kill-after=' . $killAfter, (string) max(1, $seconds), ...$argv];
        }

        return ['/bin/sh', '-c', self::OWN_GROUP, 'sh', ...$argv];
    }
}
