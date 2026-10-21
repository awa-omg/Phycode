<?php

declare(strict_types=1);

namespace Phycode\Security;

final class HardLine
{
    private const DISK = '(?:sd|hd|vd|xvd|nvme|mmcblk)';
    private const DISK_PROGRAMS = ['wipefs', 'shred', 'blkdiscard', 'mkswap', 'mke2fs'];
    private const CHANGERS = ['rm', 'chmod', 'chown', 'chgrp'];
    private const MAX_MATCHES = 1024;

    private static ?string $cwd = null;
    private static ?array $facts = null;

    public static function reason(string $summary, ?string $cwd = null): ?string
    {
        $at = strpos($summary, ': ');
        self::$cwd = $cwd === null || $cwd === '' ? null : $cwd;
        self::$facts = null;
        foreach (self::readings($at === false ? $summary : substr($summary, $at + 2)) as $reading) {
            $reason = self::reasonIn($reading);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    private static function readings(string $command): array
    {
        $unquoted = preg_replace('/\$(?=[\'"])/', '', $command) ?? $command;
        $bare = preg_replace('/\$\{[^}]*\}|\$[A-Za-z_][A-Za-z0-9_]*/', '', $unquoted) ?? $unquoted;

        return array_values(array_unique([
            $command, $unquoted, $bare, self::expandBraces($unquoted), self::expandBraces($bare),
        ]));
    }

    private static function expandBraces(string $command): string
    {
        for ($round = 0; $round < 8; $round++) {
            $next = preg_replace_callback(
                '/(\S*)\{([^{}\s,]*,[^{}\s]*)\}(\S*)/',
                static fn (array $match): string => implode(' ', array_map(
                    static fn (string $alternative): string => $match[1] . $alternative . $match[3],
                    explode(',', $match[2]),
                )),
                $command,
                1,
            );
            if ($next === null || $next === $command) {
                break;
            }
            $command = $next;
        }

        return $command;
    }

    private static function reasonIn(string $command): ?string
    {
        if (preg_match('/:\s*\(\s*\)\s*\{\s*:\s*\|\s*:\s*&\s*\}\s*;\s*:/', $command) === 1) {
            return 'a fork bomb';
        }
        if (preg_match('~>\s*/dev/' . self::DISK . '~', $command) === 1) {
            return 'a write onto a disk device';
        }
        $named = false;
        foreach (ShellCommand::segments($command) ?? [$command] as $segment) {
            foreach (ShellCommand::views($segment) as $view) {
                $reason = self::reasonOf(preg_split('/\s+/', trim($view)) ?: [], $named);
                if ($reason !== null) {
                    return $reason;
                }
            }
            if (array_filter(ShellCommand::words($segment) ?? [], static fn (string $word): bool => self::owns($word)) !== []) {
                $named = true;
            }
        }

        return null;
    }

    private static function reasonOf(array $words, bool $named): ?string
    {
        $program = $words[0] ?? '';
        $arguments = array_slice($words, 1);
        if (preg_match('/[$`*?\[(]/', $program) === 1) {
            foreach (self::CHANGERS as $guess) {
                $reason = self::reasonOf([$guess, ...$arguments], $named);
                if ($reason !== null) {
                    return "{$reason} (the program is named at run time)";
                }
            }

            return null;
        }
        $program = basename($program);
        $flags = array_values(array_filter($arguments, static fn (string $word): bool => str_starts_with($word, '-')));
        $targets = array_values(array_filter($arguments, static fn (string $word): bool => !str_starts_with($word, '-')));
        $recursive = array_filter($flags, static fn (string $flag): bool => $flag === '--recursive' || preg_match('/^-[A-Za-z]*[rR]/', $flag) === 1) !== [];

        if ($program === 'rm') {
            if (in_array('--no-preserve-root', $flags, true)) {
                return 'rm --no-preserve-root';
            }
            if ($recursive && self::wipes($targets)) {
                return 'rm -r of the root, a home folder, a system folder or a mount point';
            }
            if ($recursive && $named && array_filter($targets, static fn (string $target): bool => !str_starts_with($target, '{')) === []) {
                return 'rm -r of what xargs reads, after a word that names the root, a home folder, a system folder or a mount point';
            }
        }
        if (in_array($program, ['chmod', 'chown', 'chgrp'], true) && $recursive && self::wipes($targets)) {
            return "{$program} -R of the root, a home folder, a system folder or a mount point";
        }
        if ($program === 'find' && in_array('-delete', $flags, true) && self::wipes($targets)) {
            return 'find -delete of the root, a home folder, a system folder or a mount point';
        }
        if ($program === 'mkfs' || str_starts_with($program, 'mkfs.')) {
            return 'mkfs';
        }
        if ($program === 'dd' && array_filter($arguments, static fn (string $a): bool => preg_match('~^of=/dev/' . self::DISK . '~', $a) === 1) !== []) {
            return 'dd onto a disk device';
        }
        if (in_array($program, self::DISK_PROGRAMS, true) && array_filter($arguments, static fn (string $a): bool => preg_match('~^/dev/' . self::DISK . '~', $a) === 1) !== []) {
            return "{$program} on a disk device";
        }

        return null;
    }

    private static function wipes(array $targets): bool
    {
        foreach ($targets as $target) {
            if (self::owns($target)) {
                return true;
            }
        }

        return false;
    }

    private static function owns(string $word): bool
    {
        $path = self::absolute($word);
        if ($path === null) {
            return false;
        }
        $candidates = [$path];
        if (preg_match('/[*?\[]/', $path) === 1) {
            array_push($candidates, ...array_slice(glob($path, GLOB_NOSORT) ?: [], 0, self::MAX_MATCHES));
        }
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if (self::guarded(self::normal($candidate)) || ($real !== false && self::guarded($real))) {
                return true;
            }
        }

        return false;
    }

    private static function absolute(string $word): ?string
    {
        $bare = rtrim($word, '/*');
        if ($bare === '' && str_starts_with($word, '/')) {
            return '/';
        }
        $home = self::facts()['home'];
        if (preg_match('~^(?:\$HOME|\$\{HOME\}|\~)(?=/|$)~', $bare, $match) === 1) {
            return $home . substr($bare, strlen($match[0]));
        }
        if (preg_match('~^\~([A-Za-z0-9._-]+)(?=/|$)~', $bare, $match) === 1) {
            $user = posix_getpwnam($match[1]);

            return ($user === false ? dirname($home) . '/' . $match[1] : $user['dir']) . substr($bare, strlen($match[0]));
        }
        if (str_starts_with($bare, '/')) {
            return $bare;
        }

        return self::$cwd === null ? null : self::$cwd . '/' . $bare;
    }

    private static function guarded(string $path): bool
    {
        if (substr_count($path, '/') <= 1) {
            return true;
        }
        $facts = self::facts();
        if (in_array($path, $facts['tops'], true) || in_array(dirname($path), $facts['roots'], true)) {
            return true;
        }
        foreach ($facts['held'] as $held) {
            if ($held === $path || str_starts_with($held, $path . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function facts(): array
    {
        if (self::$facts !== null) {
            return self::$facts;
        }
        $home = getenv('HOME');
        if (!is_string($home) || !str_starts_with($home, '/')) {
            $user = posix_getpwuid(posix_geteuid());
            $home = $user === false ? '/' : $user['dir'];
        }
        $home = self::normal($home);
        $defs = (string) (@file_get_contents('/etc/login.defs') ?: @file_get_contents('/usr/etc/login.defs'));
        $low = preg_match('/^\s*UID_MIN\s+(\d+)/m', $defs, $match) === 1 ? (int) $match[1] : 1000;
        $high = preg_match('/^\s*UID_MAX\s+(\d+)/m', $defs, $match) === 1 ? (int) $match[1] : 60000;
        $base = preg_match('/^\s*HOME=(\/\S*)/m', (string) @file_get_contents('/etc/default/useradd'), $match) === 1 ? $match[1] : '/home';
        $held = [$home];
        $roots = [dirname($home), self::normal($base)];
        foreach (@file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $fields = explode(':', $line);
            if (count($fields) < 7 || !str_starts_with($fields[5], '/')) {
                continue;
            }
            $held[] = self::normal($fields[5]);
            if ((int) $fields[2] >= $low && (int) $fields[2] <= $high) {
                $roots[] = dirname(self::normal($fields[5]));
            }
        }
        foreach (@file('/proc/self/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $point = explode(' ', $line)[1] ?? '';
            $held[] = self::normal((string) preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $code): string => chr((int) octdec($code[1])), $point));
        }
        $tops = [];
        foreach (@scandir('/') ?: [] as $entry) {
            $real = realpath('/' . $entry);
            if ($real !== false) {
                $tops[] = $real;
            }
        }
        foreach (explode(':', (string) getenv('PATH')) as $entry) {
            if (str_starts_with($entry, '/')) {
                $tops[] = $folder = self::normal($entry);
                if (substr_count(dirname($folder), '/') === 1) {
                    $roots[] = dirname($folder);
                }
            }
        }

        return self::$facts = [
            'home' => $home,
            'held' => array_values(array_diff(array_unique($held), ['/'])),
            'roots' => array_values(array_unique($roots)),
            'tops' => array_values(array_unique($tops)),
        ];
    }

    private static function normal(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return '/' . implode('/', $parts);
    }
}
