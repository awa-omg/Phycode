<?php

declare(strict_types=1);

namespace Phycode\Git;

final class GitPolicy
{
    private const DENIED = [
        '--config-env', '--exec-path', '--git-dir', '--work-tree', '--namespace', '--super-prefix',
        '--output', '--no-index', '--contents', '--upload-pack', '--receive-pack', '--exec',
        '--amend', '--no-verify', '--force', '-f', '--force-with-lease', '--force-if-includes',
        '--hard', '--merge', '--keep', '--mirror', '--prune', '--prune-tags', '--autostash',
        '--continue', '--abort', '--quit', '--allow-unrelated-histories', '-D', '-M', '-X', '--strategy',
    ];

    private const READ_RULES = [
        'status' => ['options' => [
            '-s' => false, '--short' => false, '-b' => false, '--branch' => false, '--porcelain' => false, '--long' => false,
            '-v' => false, '--verbose' => false, '-u' => false, '--untracked-files' => false, '--ignored' => false,
            '--ignore-submodules' => false, '--column' => false, '--no-column' => false, '--ahead-behind' => false,
            '--no-ahead-behind' => false, '--renames' => false, '--no-renames' => false, '-z' => false, '--show-stash' => false,
        ], 'names' => true],
        'diff' => ['options' => [
            '--stat' => false, '--numstat' => false, '--shortstat' => false, '--summary' => false, '--name-only' => false,
            '--name-status' => false, '--cached' => false, '--staged' => false, '-p' => false, '--patch' => false, '-u' => false,
            '-U' => true, '--unified' => true, '--word-diff' => false, '-w' => false, '-b' => false,
            '--ignore-all-space' => false, '--ignore-space-change' => false, '--ignore-blank-lines' => false,
            '--color' => false, '--no-color' => false, '--no-ext-diff' => false, '--no-textconv' => false,
            '--diff-filter' => true, '-R' => false, '--relative' => false, '--no-renames' => false, '--find-renames' => false,
            '--src-prefix' => true, '--dst-prefix' => true, '--no-prefix' => false, '--binary' => false, '--full-index' => false,
            '--abbrev' => true, '-z' => false,
        ], 'names' => true],
        'log' => ['options' => self::HISTORY, 'names' => true, 'counts' => true],
        'show' => ['options' => self::HISTORY, 'names' => true, 'counts' => true],
        'blame' => ['options' => [
            '-L' => true, '-l' => false, '-t' => false, '-s' => false, '-e' => false, '-f' => false, '-n' => false,
            '-p' => false, '-c' => false, '-w' => false, '-M' => false, '-C' => false, '--porcelain' => false,
            '--line-porcelain' => false, '--incremental' => false, '--show-name' => false, '--show-number' => false,
            '--show-email' => false, '--show-stats' => false, '--root' => false, '--date' => true, '--abbrev' => true,
            '--minimal' => false, '--ignore-rev' => true, '--color-lines' => false, '--color-by-age' => false,
        ], 'names' => true],
        'rev-parse' => ['options' => [
            '--show-toplevel' => false, '--show-prefix' => false, '--show-cdup' => false, '--abbrev-ref' => false,
            '--short' => false, '--verify' => false, '-q' => false, '--quiet' => false, '--is-inside-work-tree' => false,
            '--is-bare-repository' => false, '--symbolic-full-name' => false, '--symbolic' => false,
            '--show-object-format' => false,
        ], 'names' => true],
        'describe' => ['options' => [
            '--tags' => false, '--all' => false, '--long' => false, '--always' => false, '--dirty' => false,
            '--abbrev' => true, '--match' => true, '--exclude' => true, '--first-parent' => false, '--contains' => false,
            '--exact-match' => false, '--candidates' => true,
        ], 'names' => true],
        'ls-files' => ['options' => [
            '-c' => false, '--cached' => false, '-d' => false, '--deleted' => false, '-m' => false, '--modified' => false,
            '-o' => false, '--others' => false, '-i' => false, '--ignored' => false, '-s' => false, '--stage' => false,
            '-u' => false, '--unmerged' => false, '-k' => false, '--killed' => false, '--directory' => false,
            '--no-empty-directory' => false, '--exclude-standard' => false, '--full-name' => false, '-z' => false,
            '--error-unmatch' => false, '--eol' => false, '--sparse' => false, '--abbrev' => true, '-x' => true, '--exclude' => true,
        ], 'names' => true],
        'shortlog' => ['options' => [
            '-s' => false, '--summary' => false, '-n' => false, '--numbered' => false, '-e' => false, '--email' => false,
            '--all' => false, '--since' => true, '--after' => true, '--until' => true, '--before' => true,
            '--no-merges' => false, '--merges' => false, '--format' => true, '--group' => true,
        ], 'names' => true],
    ];

    private const HISTORY = [
        '--oneline' => false, '--graph' => false, '--decorate' => false, '--no-decorate' => false,
        '--abbrev-commit' => false, '--no-abbrev-commit' => false, '--abbrev' => true,
        '-n' => true, '--max-count' => true, '--skip' => true, '--since' => true, '--after' => true, '--until' => true,
        '--before' => true, '--author' => true, '--committer' => true, '--grep' => true, '-i' => false,
        '--regexp-ignore-case' => false, '--invert-grep' => false, '--all-match' => false, '-F' => false,
        '--fixed-strings' => false, '-E' => false, '--extended-regexp' => false, '-S' => true, '-G' => true,
        '--pickaxe-regex' => false, '--stat' => false, '--numstat' => false, '--shortstat' => false, '--name-only' => false,
        '--name-status' => false, '-p' => false, '--patch' => false, '-u' => false, '-U' => true, '--unified' => true,
        '--word-diff' => false, '-w' => false, '--color' => false, '--no-color' => false, '--no-ext-diff' => false,
        '--no-textconv' => false, '--diff-filter' => true, '--follow' => false, '--format' => true, '--pretty' => true,
        '--date' => true, '--all' => false, '--branches' => false, '--tags' => false, '--remotes' => false,
        '--no-merges' => false, '--merges' => false, '--first-parent' => false, '--reverse' => false, '--topo-order' => false,
        '--date-order' => false, '--ancestry-path' => false, '--parents' => false, '--children' => false,
        '--simplify-by-decoration' => false, '--source' => false, '--left-right' => false, '--no-walk' => false,
    ];

    private const TOOLS = ['read' => 'git_read', 'write' => 'git_write', 'remote' => 'git_remote'];

    private const REASONS = [
        'checkout' => 'git checkout can discard uncommitted changes when it is given a path; use git switch to change branches',
        'clean' => 'git clean deletes untracked files',
        'rebase' => 'git rebase rewrites history',
        'config' => 'git config changes repository settings',
    ];

    private const BRANCH_LISTING = [
        '-a' => false, '--all' => false, '-r' => false, '--remotes' => false, '-v' => false, '-vv' => false,
        '--verbose' => false, '--list' => false, '--show-current' => false, '--color' => false, '--no-color' => false,
        '--merged' => true, '--no-merged' => true, '--contains' => true, '--points-at' => true,
        '--sort' => true, '--format' => true,
    ];

    private const TAG_LISTING = [
        '-l' => false, '--list' => false, '--sort' => true, '--points-at' => true, '--contains' => true,
        '--merged' => true, '--no-merged' => true, '--format' => true,
    ];

    private const RULES = [
        'read' => [
            'branch' => ['options' => self::BRANCH_LISTING, 'names' => false],
            'tag' => ['options' => self::TAG_LISTING, 'names' => false],
            'remote' => ['options' => ['-v' => false, '--verbose' => false], 'names' => false],
        ],
        'write' => [
            'add' => ['options' => [
                '-A' => false, '--all' => false, '-u' => false, '--update' => false, '-N' => false,
                '--intent-to-add' => false, '-v' => false, '--verbose' => false, '-n' => false, '--dry-run' => false,
                '--renormalize' => false, '--ignore-removal' => false, '--no-ignore-removal' => false,
            ], 'names' => true],
            'commit' => ['options' => [
                '-m' => true, '--message' => true, '-a' => false, '--all' => false, '-s' => false, '--signoff' => false,
                '-q' => false, '--quiet' => false, '--allow-empty' => false, '--author' => true, '--date' => true,
                '--dry-run' => false,
            ], 'names' => true],
            'branch' => ['options' => [
                ...self::BRANCH_LISTING, '-d' => false, '--delete' => false, '-m' => false, '--move' => false,
                '-t' => false, '--track' => false, '--no-track' => false, '-u' => true, '--set-upstream-to' => true,
                '--unset-upstream' => false, '-q' => false, '--quiet' => false,
            ], 'names' => true],
            'switch' => ['options' => [
                '-c' => true, '--create' => true, '--detach' => false, '-q' => false, '--quiet' => false,
                '-t' => false, '--track' => false, '--no-track' => false,
            ], 'names' => true],
            'tag' => ['options' => [
                ...self::TAG_LISTING, '-a' => false, '--annotate' => false, '-m' => true, '--message' => true,
            ], 'names' => true],
            'reset' => ['options' => ['--soft' => false, '--mixed' => false, '-q' => false, '--quiet' => false], 'names' => true],
            'restore' => ['options' => ['-S' => false, '--staged' => false, '-q' => false, '--quiet' => false], 'names' => true],
            'merge' => ['options' => [
                '--no-ff' => false, '--ff-only' => false, '--squash' => false, '--no-commit' => false, '--commit' => false,
                '-m' => true, '--message' => true, '--no-edit' => false, '-q' => false, '--quiet' => false,
            ], 'names' => true],
        ],
        'remote' => [
            'fetch' => ['options' => [
                '--all' => false, '--tags' => false, '--no-tags' => false, '--depth' => true, '--unshallow' => false,
                '-q' => false, '--quiet' => false, '-v' => false, '--verbose' => false, '--dry-run' => false,
            ], 'names' => true, 'refspecs' => true],
            'pull' => ['options' => [
                '--ff-only' => false, '--tags' => false, '--no-tags' => false, '--depth' => true,
                '-q' => false, '--quiet' => false, '-v' => false, '--verbose' => false,
            ], 'names' => true, 'refspecs' => true],
            'push' => ['options' => [
                '-u' => false, '--set-upstream' => false, '--tags' => false, '--follow-tags' => false, '--all' => false,
                '--dry-run' => false, '-q' => false, '--quiet' => false, '-v' => false, '--verbose' => false,
            ], 'names' => true, 'refspecs' => true],
        ],
    ];

    private const STASH = [
        'push' => ['options' => [
            '-m' => true, '--message' => true, '-u' => false, '--include-untracked' => false,
            '-k' => false, '--keep-index' => false, '-q' => false, '--quiet' => false,
        ], 'names' => true],
        'pop' => ['options' => ['--index' => false, '-q' => false, '--quiet' => false], 'names' => true],
        'apply' => ['options' => ['--index' => false, '-q' => false, '--quiet' => false], 'names' => true],
        'list' => ['options' => [], 'names' => false],
        'show' => ['options' => [
            '-p' => false, '--patch' => false, '--stat' => false, '--name-only' => false, '--name-status' => false,
        ], 'names' => true],
    ];

    private const REQUIRED = [
        'commit' => [['-m', '--message']],
        'restore' => [['-S', '--staged']],
        'pull' => [['--ff-only']],
    ];

    public static function refusal(string $mode, array $args): ?string
    {
        $subcommand = $args[0] ?? '';
        if ($subcommand === '' || str_starts_with($subcommand, '-')) {
            return 'the first argument must be the git subcommand, for example ["status"]';
        }
        if ($mode === 'read' && isset(self::READ_RULES[$subcommand])) {
            $rule = self::READ_RULES[$subcommand];

            return self::check($subcommand, $args, 1, $rule['options'], $rule['names'], false, $rule['counts'] ?? false)['refusal'];
        }
        if ($mode === 'write' && $subcommand === 'stash') {
            return self::stash($args);
        }

        $rule = self::RULES[$mode][$subcommand] ?? null;
        if ($rule === null) {
            return self::notAllowed($mode, $subcommand);
        }
        $checked = self::check($subcommand, $args, 1, $rule['options'], $rule['names'], $rule['refspecs'] ?? false);
        if ($checked['refusal'] !== null) {
            return $checked['refusal'];
        }
        foreach (self::REQUIRED[$subcommand] ?? [] as $group) {
            if (array_intersect($checked['seen'], $group) === []) {
                return "git {$subcommand} needs " . implode(' or ', $group);
            }
        }
        if ($subcommand === 'tag'
            && array_intersect($checked['seen'], ['-a', '--annotate']) !== []
            && array_intersect($checked['seen'], ['-m', '--message']) === []
        ) {
            return 'an annotated tag needs -m "message", otherwise git would open an editor';
        }

        return null;
    }

    private static function stash(array $args): ?string
    {
        $sub = $args[1] ?? '';
        $rule = self::STASH[$sub] ?? null;
        if ($rule === null) {
            return $sub === ''
                ? 'git stash needs a subcommand: push, pop, apply, list or show'
                : "git stash {$sub} is not allowed; only push, pop, apply, list and show are (drop and clear delete stashed work)";
        }

        return self::check("stash {$sub}", $args, 2, $rule['options'], $rule['names'], false)['refusal'];
    }

    private static function check(string $label, array $args, int $start, ?array $allowed, bool $names, bool $refspecs, bool $counts = false): array
    {
        $seen = [];
        $count = count($args);
        $literal = false;
        for ($i = $start; $i < $count; $i++) {
            $arg = $args[$i];
            if ($literal || $arg === '-' || !str_starts_with($arg, '-')) {
                if (!$names) {
                    return ['refusal' => "git {$label} takes no names here, got '{$arg}'", 'seen' => $seen];
                }
                if ($refspecs && (str_starts_with($arg, '+') || str_starts_with($arg, ':'))) {
                    return ['refusal' => "refspec '{$arg}' is refused: a leading + forces the update and a leading : deletes", 'seen' => $seen];
                }
                continue;
            }
            if ($arg === '--') {
                $literal = true;
                continue;
            }
            if ($counts && preg_match('/^-\d+$/', $arg) === 1) {
                continue;
            }
            if (str_starts_with($arg, '--')) {
                $name = explode('=', $arg, 2)[0];
                $seen[] = $name;
                $refusal = self::optionRefusal($label, $name, $allowed);
                if ($refusal !== null) {
                    return ['refusal' => $refusal, 'seen' => $seen];
                }
                if (!str_contains($arg, '=') && self::takesValue($allowed, $name) && ++$i >= $count) {
                    return ['refusal' => "{$name} needs a value", 'seen' => $seen];
                }
                continue;
            }

            $letters = substr($arg, 1);
            $last = strlen($letters) - 1;
            for ($j = 0; $j <= $last; $j++) {
                $name = '-' . $letters[$j];
                $seen[] = $name;
                $refusal = self::optionRefusal($label, $name, $allowed);
                if ($refusal !== null) {
                    return ['refusal' => $refusal, 'seen' => $seen];
                }
                if (self::takesValue($allowed, $name)) {
                    if ($j === $last && ++$i >= $count) {
                        return ['refusal' => "{$name} needs a value", 'seen' => $seen];
                    }
                    break;
                }
            }
        }

        return ['refusal' => null, 'seen' => $seen];
    }

    private static function optionRefusal(string $label, string $name, ?array $allowed): ?string
    {
        if (in_array($name, self::DENIED, true)) {
            return "option {$name} is refused in every git tool";
        }
        if ($allowed !== null && !array_key_exists($name, $allowed)) {
            return "git {$label} does not allow {$name}";
        }

        return null;
    }

    private static function takesValue(?array $allowed, string $name): bool
    {
        return $allowed !== null && ($allowed[$name] ?? false) === true;
    }

    private static function notAllowed(string $mode, string $subcommand): string
    {
        if (isset(self::REASONS[$subcommand])) {
            return self::REASONS[$subcommand] . '; it is not allowed';
        }
        $owner = self::ownerOf($subcommand);
        if ($owner !== null) {
            return "git {$subcommand} is not available in " . self::TOOLS[$mode] . '; use ' . self::TOOLS[$owner] . ' for it';
        }

        return "git {$subcommand} is not allowed by the git policy";
    }

    private static function ownerOf(string $subcommand): ?string
    {
        if (isset(self::READ_RULES[$subcommand])) {
            return 'read';
        }
        if ($subcommand === 'stash') {
            return 'write';
        }
        foreach (self::RULES as $mode => $commands) {
            if (isset($commands[$subcommand])) {
                return $mode;
            }
        }

        return null;
    }
}
