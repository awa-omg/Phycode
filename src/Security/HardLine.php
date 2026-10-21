<?php

declare(strict_types=1);

namespace Phycode\Security;

final class HardLine
{
    private const DISK = '(?:sd|hd|vd|xvd|nvme|mmcblk)';
    private const DISK_PROGRAMS = ['wipefs', 'shred', 'blkdiscard', 'mkswap', 'mke2fs'];
    private const SYSTEM = ['/bin', '/boot', '/dev', '/etc', '/home', '/lib', '/lib32', '/lib64', '/libx32', '/opt', '/proc', '/root', '/run', '/sbin', '/srv', '/sys', '/usr', '/var'];
    private const CHANGERS = ['rm', 'chmod', 'chown', 'chgrp'];

    public static function reason(string $tool, string $summary): ?string
    {
        $parts = ShellCommand::of($tool, $summary);
        if ($parts === null) {
            return null;
        }
        foreach (self::readings($parts[1]) as $reading) {
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
                return 'rm -r of the root, the home folder or a system folder';
            }
            if ($recursive && $named && array_filter($targets, static fn (string $target): bool => !str_starts_with($target, '{')) === []) {
                return 'rm -r of what xargs reads, after a word that names the root or a system folder';
            }
        }
        if (in_array($program, ['chmod', 'chown', 'chgrp'], true) && $recursive && self::wipes($targets)) {
            return "{$program} -R of the root, the home folder or a system folder";
        }
        if ($program === 'find' && in_array('-delete', $flags, true) && self::wipes($targets)) {
            return 'find -delete of the root, the home folder or a system folder';
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
        if (self::isRoot($word)) {
            return true;
        }
        $bare = rtrim($word, '/*');
        if (in_array($bare, ['~', '$HOME', '${HOME}'], true) || in_array($bare, self::SYSTEM, true)) {
            return true;
        }
        $home = getenv('HOME');

        return $home !== false && $home !== '' && $bare === rtrim($home, '/');
    }

    private static function isRoot(string $target): bool
    {
        return preg_match('~^/+\*?$~', $target) === 1;
    }
}
