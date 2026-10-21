<?php

declare(strict_types=1);

namespace Phycode\Gentle;

use Phycode\Exec\ProcessResult;
use Phycode\Exec\ProcessRunner;
use Phycode\Support\PhycodeException;

final class GentleOperations
{
    public const ACTIONS = ['install', 'sync', 'upgrade', 'uninstall', 'refresh', 'tui'];

    private const TIMEOUT = 900;
    private const MAX_BYTES = 2_000_000;
    private const REFRESH_FLAGS = ['--force', '-f', '--no-gitignore'];

    public static function build(string $action, string $bin, string $cwd, array $words, array $env, ?string $versionNote): GentleOperation
    {
        $notes = $versionNote === null ? [] : ["{$versionNote}. The command still runs; this is only a warning."];

        return match ($action) {
            'install' => new GentleOperation(
                'gentle-ai install',
                [$bin, 'install', ...$words],
                $env,
                $cwd,
                self::TIMEOUT,
                ['agent config of the chosen agents and components, and state.json', 'a backup of the files it replaces'],
                [...$notes, 'gentle-ai may run engram setup and the installers of the selected components; phycode runs none of them itself'],
            ),
            'sync' => new GentleOperation(
                'gentle-ai sync',
                [$bin, 'sync', ...$words],
                $env,
                $cwd,
                self::TIMEOUT,
                ['agent config of the installed agents, and state.json'],
                [...$notes, 'sync may run engram setup too; a partial sync prints its report and exits with code 1'],
            ),
            'upgrade' => new GentleOperation(
                'gentle-ai upgrade',
                [$bin, 'upgrade', ...$words],
                $env,
                $cwd,
                self::TIMEOUT,
                [
                    'may replace the gentle-ai binary with the latest release from GitHub (checked with minisign and sha256)',
                    'may upgrade gga and engram; gga runs its upstream install.sh, which has no signature check',
                ],
                [...$notes, 'GENTLE_AI_NO_SELF_UPDATE does not stop upgrade'],
            ),
            'uninstall' => self::uninstall($bin, $cwd, $words, $env, $notes),
            'refresh' => self::refresh($bin, $cwd, $words, $env, $notes),
            'tui' => self::tui($bin, $cwd, $words, $env, $notes),
            default => throw new PhycodeException("no gentle-ai action named {$action}; the actions are " . implode(', ', self::ACTIONS)),
        };
    }

    public static function execEvent(GentleOperation $operation): array
    {
        return [
            'type' => 'exec',
            'title' => $operation->title,
            'argv' => ['env', ...GentleOperation::assignments($operation->env), ...$operation->argv],
            'confirm' => true,
        ];
    }

    public static function run(GentleOperation $operation, ProcessRunner $processes): ProcessResult
    {
        return $processes->run($operation->argv, $operation->cwd, $operation->timeout, self::MAX_BYTES, null, $operation->env);
    }

    public static function tokens(string $line): array
    {
        return preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function uninstall(string $bin, string $cwd, array $words, array $env, array $notes): GentleOperation
    {
        if (!in_array('--all', $words, true) && !self::hasFlag($words, ['--agent', '--agents'])) {
            throw new PhycodeException('uninstall needs its scope: --agent NAME (or --agents a,b), or --all. Nothing was run.');
        }
        $argv = [$bin, 'uninstall', ...$words];
        if (!in_array('--yes', $words, true) && !in_array('-y', $words, true)) {
            $argv[] = '--yes';
            $notes[] = '--yes is added, so that gentle-ai does not ask a second time: this approval is the one question';
        }

        return new GentleOperation(
            'gentle-ai uninstall',
            $argv,
            $env,
            $cwd,
            self::TIMEOUT,
            ['the managed files of the chosen agents (or of all of them), after a backup', 'state.json'],
            $notes,
        );
    }

    private static function refresh(string $bin, string $cwd, array $words, array $env, array $notes): GentleOperation
    {
        foreach ($words as $word) {
            if (!in_array($word, self::REFRESH_FLAGS, true)) {
                throw new PhycodeException("refresh takes --force and --no-gitignore, not {$word}. Nothing was run.");
            }
        }
        $gitignore = in_array('--no-gitignore', $words, true)
            ? '.gitignore is not changed (--no-gitignore)'
            : "{$cwd}/.gitignore gets .atl/ appended, unless it has it already";

        return new GentleOperation(
            'gentle-ai skill-registry refresh',
            [$bin, 'skill-registry', 'refresh', '--cwd', $cwd, ...$words],
            $env,
            $cwd,
            self::TIMEOUT,
            ["{$cwd}/.atl/skill-registry.md and {$cwd}/.atl/.skill-registry.cache.json", $gitignore],
            [...$notes, 'gentle-ai refuses /, your home folder, and a folder with no .git, no .atl and no skill folder'],
        );
    }

    private static function tui(string $bin, string $cwd, array $words, array $env, array $notes): GentleOperation
    {
        if ($words !== []) {
            throw new PhycodeException('the gentle-ai TUI takes no arguments. Nothing was run.');
        }

        return new GentleOperation(
            'gentle-ai (TUI)',
            [$bin],
            $env,
            $cwd,
            0,
            ['gentle-ai\'s interactive TUI, which can change the same things as install, sync and uninstall, with its own questions'],
            [
                ...$notes,
                'the TUI does its own update check and advisory fetch, which GENTLE_AI_NO_SELF_UPDATE does not stop',
                'a sync left pending by an earlier self-update runs when the TUI opens',
            ],
        );
    }

    private static function hasFlag(array $words, array $flags): bool
    {
        foreach ($words as $word) {
            foreach ($flags as $flag) {
                if ($word === $flag || str_starts_with($word, $flag . '=')) {
                    return true;
                }
            }
        }

        return false;
    }
}
