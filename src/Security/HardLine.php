<?php

declare(strict_types=1);

namespace Phycode\Security;

final class HardLine
{
    private const DISK = '(?:sd|hd|vd|xvd|nvme|mmcblk)';
    private const DISK_PROGRAMS = ['wipefs', 'shred', 'blkdiscard', 'mkswap', 'mke2fs'];
    private const CHANGERS = ['rm', 'chmod', 'chown', 'chgrp'];
    private const MAX_MATCHES = 4096;
    private const MOUNT_SECONDS = 30.0;

    private const DELETERS = ['rm', 'shred', 'unlink'];
    private const DECLARERS = ['export', 'declare', 'typeset', 'local', 'readonly'];

    private static ?string $start = null;
    private static ?string $cwd = null;
    private static ?array $facts = null;
    private static array $vars = [];
    private static array $links = [];
    private static ?array $mounted = null;
    private static float $mountedAt = 0.0;

    public static function reason(string $summary, ?string $cwd = null): ?string
    {
        $at = strpos($summary, ': ');
        self::$start = $cwd === null || $cwd === '' ? null : $cwd;
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
        self::$cwd = self::$start;
        self::$vars = [];
        self::$links = [];
        foreach (ShellCommand::segments($command) ?? [$command] as $segment) {
            $segment = self::substitute($segment);
            foreach (ShellCommand::views($segment) as $view) {
                if ($view === ShellCommand::TOO_DEEP) {
                    return 'a command nested more than four shells deep, which the hard line cannot read';
                }
                $reason = self::reasonOf(preg_split('/\s+/', trim($view)) ?: [], $named);
                if ($reason !== null) {
                    return $reason;
                }
            }
            $words = ShellCommand::words($segment) ?? [];
            if (array_filter($words, static fn (string $word): bool => self::owns($word)) !== []) {
                $named = true;
            }
            self::remember($words);
        }

        return null;
    }

    private static function prefixes(string $segment): array
    {
        $found = [];
        foreach (ShellCommand::words($segment) ?? [] as $word) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', $word, $match) === 1) {
                $found[$match[1]] = $match[2];
            }
        }

        return $found;
    }

    private static function substitute(string $segment): string
    {
        foreach ([...self::$vars, ...self::prefixes($segment)] as $name => $value) {
            $segment = (string) preg_replace('/\$\{' . $name . '\}|\$' . $name . '(?![A-Za-z0-9_])/', str_replace(['\\', '$'], ['\\\\', '\\$'], $value), $segment);
        }

        return $segment;
    }

    private static function remember(array $words): void
    {
        $program = basename($words[0] ?? '');
        if (in_array($program, ['cd', 'pushd'], true)) {
            $target = array_values(array_filter(array_slice($words, 1), static fn (string $word): bool => !str_starts_with($word, '-')))[0] ?? '~';
            self::$cwd = self::absolute($target) ?? self::$cwd;

            return;
        }
        if ($program === 'ln' && array_filter($words, static fn (string $word): bool => preg_match('/^-[A-Za-z]*s/', $word) === 1 || $word === '--symbolic') !== []) {
            $paths = array_values(array_filter(array_slice($words, 1), static fn (string $word): bool => !str_starts_with($word, '-')));
            if (count($paths) >= 2) {
                $target = self::absolute($paths[0]);
                $link = self::absolute($paths[count($paths) - 1]);
                if ($target !== null && $link !== null) {
                    self::$links[self::normal($link)] = $target;
                }
            }

            return;
        }
        $assignments = in_array($words[0] ?? '', self::DECLARERS, true) ? array_slice($words, 1) : $words;
        $found = [];
        foreach ($assignments as $word) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', $word, $match) === 1) {
                $found[$match[1]] = $match[2];
            } elseif (!str_starts_with($word, '-')) {
                return;
            }
        }
        self::$vars = [...self::$vars, ...$found];
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
        if ($program === 'find' && self::findDeletes($arguments) && self::wipes(self::findStarts($arguments))) {
            return 'find -delete or -exec rm of the root, a home folder, a system folder or a mount point';
        }
        if ($program === 'rsync' && array_filter($flags, static fn (string $flag): bool => str_starts_with($flag, '--del')) !== [] && $targets !== [] && self::wipes([self::local(end($targets))])) {
            return 'rsync --delete into the root, a home folder, a system folder or a mount point';
        }
        if ($program === 'rsync' && in_array('--remove-source-files', $flags, true) && self::wipes(array_map(self::local(...), array_slice($targets, 0, -1)))) {
            return 'rsync --remove-source-files of the root, a home folder, a system folder or a mount point';
        }
        if ($program === 'shred' && self::wipes($targets)) {
            return 'shred of the files of the root, a home folder, a system folder or a mount point';
        }
        if ($program === 'git' && ($reason = self::gitClean($arguments)) !== null) {
            return $reason;
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

    private static function findDeletes(array $arguments): bool
    {
        foreach ($arguments as $at => $word) {
            if ($word === '-delete' || (in_array($word, ['-exec', '-execdir', '-ok', '-okdir'], true) && in_array(basename($arguments[$at + 1] ?? ''), self::DELETERS, true))) {
                return true;
            }
        }

        return false;
    }

    private static function findStarts(array $arguments): array
    {
        $starts = [];
        foreach ($arguments as $word) {
            if (str_starts_with($word, '-') || in_array($word, ['(', '\\(', '!'], true)) {
                if ($starts === [] && in_array($word, ['-H', '-L', '-P'], true)) {
                    continue;
                }
                break;
            }
            $starts[] = $word;
        }

        return $starts === [] ? ['.'] : $starts;
    }

    private static function local(string $target): string
    {
        return preg_match('~^[^/]*:~', $target) === 1 ? '' : $target;
    }

    private static function gitClean(array $arguments): ?string
    {
        $at = array_search('clean', $arguments, true);
        if ($at === false) {
            return null;
        }
        $options = array_slice($arguments, $at + 1);
        $forced = array_filter($options, static fn (string $word): bool => $word === '--force' || preg_match('/^-[A-Za-z]*f/', $word) === 1) !== [];
        if (!$forced) {
            return null;
        }
        $before = array_slice($arguments, 0, $at);
        $folder = ($c = array_search('-C', $before, true)) !== false ? ($before[$c + 1] ?? '.') : '.';
        $paths = array_values(array_filter($options, static fn (string $word): bool => !str_starts_with($word, '-')));
        $paths = $paths === [] ? [$folder] : array_map(static fn (string $path): string => str_starts_with($path, '/') ? $path : rtrim($folder, '/') . '/' . $path, $paths);

        return self::wipes($paths) ? 'git clean -f of the root, a home folder, a system folder or a mount point' : null;
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
        return self::linked(self::resolve($word));
    }

    private static function linked(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $normal = self::normal($path);
        foreach (self::$links as $link => $target) {
            if ($normal === $link || str_starts_with($normal, $link . '/')) {
                return $target . substr($normal, strlen($link));
            }
        }

        return $path;
    }

    private static function contents(string $word): string
    {
        do {
            $before = $word;
            if (strlen($word) > 1) {
                $word = rtrim($word, '/') ?: '/';
            }
            $word = (string) preg_replace('~(^|/)\.?\*+$~', '$1', $word);
        } while ($word !== $before);

        return $word;
    }

    private static function resolve(string $word): ?string
    {
        $bare = self::contents($word);
        if ($bare === '/') {
            return '/';
        }
        $home = self::facts()['home'];
        if (preg_match('~^(?:\$HOME|\$\{HOME(?:[:?#%/^,+=-][^}]*)?\}|\~)(?=/|$)~', $bare, $match) === 1) {
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
        $low = preg_match('/^\s*UID_MIN\s+(\d+)/m', $defs, $match) === 1 ? (int) $match[1] : (PHP_OS_FAMILY === 'Darwin' ? 500 : 1000);
        $high = preg_match('/^\s*UID_MAX\s+(\d+)/m', $defs, $match) === 1 ? (int) $match[1] : 60000;
        $base = preg_match('/^\s*HOME=(\/\S*)/m', (string) @file_get_contents('/etc/default/useradd'), $match) === 1 ? $match[1] : '/home';
        $held = [$home];
        $homes = [$home];
        $roots = [dirname($home), self::normal($base)];
        foreach (@file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $fields = explode(':', $line);
            if (count($fields) < 7 || !str_starts_with($fields[5], '/')) {
                continue;
            }
            $held[] = self::normal($fields[5]);
            if ((int) $fields[2] >= $low && (int) $fields[2] <= $high) {
                $roots[] = dirname(self::normal($fields[5]));
                $homes[] = self::normal($fields[5]);
            }
        }
        array_push($held, ...self::mountPoints());
        if (PHP_OS_FAMILY === 'Darwin') {
            $held[] = $home . '/Library';
        }
        $tops = [];
        foreach (@scandir('/') ?: [] as $entry) {
            $real = realpath('/' . $entry);
            if ($real !== false) {
                $tops[] = $real;
            }
        }
        foreach (explode(':', (string) getenv('PATH')) as $entry) {
            if (str_starts_with($entry, '/') && array_filter($homes, static fn (string $dir): bool => self::normal($entry) === $dir || str_starts_with(self::normal($entry), $dir . '/')) === []) {
                $tops[] = $folder = self::normal($entry);
                if (substr_count(dirname($folder), '/') === 1) {
                    $roots[] = dirname($folder);
                }
            }
        }

        return self::$facts = [
            'home' => $home,
            'held' => array_values(array_diff(self::withReal($held), ['/'])),
            'roots' => self::withReal($roots),
            'tops' => array_values(array_unique($tops)),
        ];
    }

    private static function withReal(array $paths): array
    {
        $real = array_filter(array_map(static fn (string $path): string|false => realpath($path), $paths));

        return array_values(array_unique([...$paths, ...$real]));
    }

    private static function mountPoints(): array
    {
        if (is_readable('/proc/self/mounts')) {
            $points = [];
            foreach (@file('/proc/self/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $point = explode(' ', $line)[1] ?? '';
                $points[] = self::normal((string) preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $code): string => chr((int) octdec($code[1])), $point));
            }

            return $points;
        }
        if (self::$mounted === null || microtime(true) - self::$mountedAt > self::MOUNT_SECONDS) {
            $lines = [];
            @exec('/sbin/mount 2>/dev/null', $lines);
            self::$mounted = self::mountsFrom($lines);
            self::$mountedAt = microtime(true);
        }

        return self::$mounted;
    }

    public static function mountsFrom(array $lines): array
    {
        $points = [];
        foreach ($lines as $line) {
            if (preg_match('/^.+? on (\/.*) \([^()]*\)$/', $line, $match) === 1) {
                $points[] = self::normal($match[1]);
            }
        }

        return $points;
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
