<?php

declare(strict_types=1);

namespace Phycode\Exec;

final class Launch
{
    public const STATUS = 9;

    private const START = 'parent=$1; limit=$2; grace=$3; signal=$4; nap=$5; ps=$6; pwait=$7; shift 7; exec 3>&2 2>/dev/null; set -m; ';
    private const GONE = 'gone() { kill -0 "$parent" 2>/dev/null || return 0; if [ -r "/proc/$parent/stat" ]; then read -r line < "/proc/$parent/stat" || return 1; '
        . 'case "$line" in *") Z "*) return 0;; esac; return 1; fi; [ -n "$ps" ] || return 1; state=$("$ps" -o stat= -p "$parent" 2>/dev/null) || return 1; case "$state" in Z*) return 0;; esac; return 1; }; ';
    private const TRAPS = 'trap \'stopping=1; kill -s TERM -- "-$child" 2>/dev/null\' TERM; trap \'stopping=1; kill -s INT -- "-$child" 2>/dev/null\' INT; trap \'stopping=1; kill -s HUP -- "-$child" 2>/dev/null\' HUP; '
        . 'wait "$child"; status=$?; while kill -0 "$child" 2>/dev/null; do wait "$child"; status=$?; done; '
        . 'kill -s KILL -- "-$child" 2>/dev/null; kill -s KILL -- "-$watcher" "$watcher" 2>/dev/null; ';

    private const OWN_GROUP = self::START . '"$@" 2>&3 3>&- & child=$!; stopping=; self=$$; timedout=; trap \'timedout=1\' USR1; ' . self::GONE
        . '{ exec </dev/null >/dev/null 3>&- 8>&- 9>&-; waited=0; while [ "$waited" -lt "$limit" ] && ! gone; do "$nap" 1 || exit 0; waited=$((waited + 1)); done; '
        . 'if [ "$waited" -ge "$limit" ]; then kill -s USR1 "$self" 2>/dev/null; fi; kill -s "$signal" -- "-$child"; "$nap" "$grace"; kill -s KILL -- "-$child"; } & watcher=$!; '
        . self::TRAPS . 'if [ -n "$timedout" ]; then status=124; fi; exit "$status"';

    private const REAPED = self::START . '"$@" 2>&3 3>&- 9>&- & child=$!; stopping=; reaper=$PPID; ' . self::GONE
        . '{ exec </dev/null >/dev/null 3>&- 8>&- 9>&-; if [ -n "$pwait" ]; then "$pwait" "$parent"; fi; while ! gone; do "$nap" 1 || exit 0; done; kill -s TERM "$reaper"; } & watcher=$!; '
        . self::TRAPS
        . 'if [ -z "$stopping" ]; then printf "%s\n" "$status" >&9; exec 9>&-; kill -s KILL "$reaper" 2>/dev/null; fi; exit "$status"';

    public static function command(array $argv, int $seconds, string $signal, int $killAfter): array
    {
        $seconds = max(1, $seconds);
        $timeout = ProcessRunner::timeoutProgram();
        if ($timeout !== null) {
            return [(string) ProcessRunner::which('setpriv'), '--pdeathsig', 'TERM', $timeout, '--signal=' . $signal, '--kill-after=' . $killAfter, (string) $seconds, ...$argv];
        }
        $shell = ProcessRunner::which('bash') ?? '/bin/sh';
        $reaper = self::reaper();
        $watch = [(string) getmypid(), (string) $seconds, (string) $killAfter, $signal, self::tool('sleep') ?? '/bin/sleep', self::tool('ps') ?? '', self::tool('pwait') ?? ''];

        return $reaper === null
            ? [$shell, '-c', self::OWN_GROUP, 'sh', ...$watch, ...$argv]
            : [$reaper, '-s', $signal, '-k', (string) $killAfter, (string) $seconds, $shell, '-c', self::REAPED, 'sh', ...$watch, ...$argv];
    }

    private static function tool(string $name): ?string
    {
        foreach ([ProcessRunner::which($name), '/bin/' . $name, '/usr/bin/' . $name] as $path) {
            if ($path !== null && is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function reaper(): ?string
    {
        return PHP_OS === 'FreeBSD' ? self::tool('timeout') : null;
    }

    public static function reported($status): ?int
    {
        $text = '';
        stream_set_blocking($status, false);
        for ($deadline = microtime(true) + 2.0; !feof($status) && microtime(true) < $deadline; ) {
            $read = [$status];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 100000) > 0) {
                $text .= (string) fread($status, 64);
            }
        }
        fclose($status);

        return ctype_digit(trim($text)) ? (int) trim($text) : null;
    }
}
