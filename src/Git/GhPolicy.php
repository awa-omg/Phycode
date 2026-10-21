<?php

declare(strict_types=1);

namespace Phycode\Git;

final class GhPolicy
{
    private const TOOLS = ['read' => 'gh_read', 'write' => 'gh_write'];

    private const JSON = ['--json' => true];
    private const LISTING = ['--state' => true, '-s' => true, '--author' => true, '-A' => true, '--assignee' => true, '-a' => true, '--label' => true, '-l' => true, '--limit' => true, '-L' => true, '--search' => true, '-S' => true] + self::JSON;

    private const RULES = [
        'read' => [
            'pr view' => ['options' => ['--comments' => false, '-c' => false] + self::JSON, 'positional' => 1],
            'pr list' => ['options' => ['--base' => true, '-B' => true, '--head' => true, '-H' => true, '--draft' => false, '-d' => false] + self::LISTING, 'positional' => 0],
            'pr checks' => ['options' => ['--required' => false] + self::JSON, 'positional' => 1],
            'pr diff' => ['options' => ['--name-only' => false, '--patch' => false], 'positional' => 1],
            'pr status' => ['options' => self::JSON, 'positional' => 0],
            'issue view' => ['options' => ['--comments' => false, '-c' => false] + self::JSON, 'positional' => 1],
            'issue list' => ['options' => self::LISTING, 'positional' => 0],
            'run list' => ['options' => ['--workflow' => true, '--branch' => true, '--status' => true, '--event' => true, '--user' => true, '--commit' => true, '--limit' => true, '-L' => true] + self::JSON, 'positional' => 0],
            'run view' => ['options' => ['--log' => false, '--log-failed' => false, '--job' => true, '-j' => true, '--verbose' => false, '-v' => false] + self::JSON, 'positional' => 1],
            'repo view' => ['options' => self::JSON, 'positional' => 1],
            'auth status' => ['options' => [], 'positional' => 0],
        ],
        'write' => [
            'pr create' => [
                'options' => ['--title' => true, '-t' => true, '--body' => true, '-b' => true, '--base' => true, '-B' => true, '--head' => true, '-H' => true, '--draft' => false, '-d' => false,
                    '--fill' => false, '-f' => false, '--fill-first' => false, '--fill-verbose' => false, '--label' => true, '-l' => true, '--reviewer' => true, '-r' => true,
                    '--assignee' => true, '-a' => true, '--dry-run' => false, '--no-maintainer-edit' => false],
                'positional' => 0,
                'all' => [['--title', '-t'], ['--body', '-b']],
                'unless' => ['--fill', '-f', '--fill-first', '--fill-verbose'],
            ],
            'pr comment' => ['options' => ['--body' => true, '-b' => true], 'positional' => 1, 'all' => [['--body', '-b']]],
            'issue create' => ['options' => ['--title' => true, '-t' => true, '--body' => true, '-b' => true, '--label' => true, '-l' => true, '--assignee' => true, '-a' => true], 'positional' => 0, 'all' => [['--title', '-t'], ['--body', '-b']]],
            'issue comment' => ['options' => ['--body' => true, '-b' => true], 'positional' => 1, 'all' => [['--body', '-b']]],
        ],
    ];

    private const REASONS = [
        'pr merge' => 'merging is for the person; ask them to merge, or to run it themselves',
        'pr close' => 'closing a pull request is for the person',
        'pr review' => 'a review is the person\'s own opinion, so the person writes it',
        'pr edit' => 'editing a pull request after it exists is for the person',
        'issue close' => 'closing an issue is for the person',
        'api' => 'gh api can call any endpoint of GitHub, so it is not offered',
        'repo' => 'only repo view is offered',
        'secret' => 'secrets are not for the model',
        'auth' => 'only auth status is offered',
        'workflow' => 'running a workflow is for the person',
        'extension' => 'extensions run programs',
        'alias' => 'aliases can run programs',
        'config' => 'configuration is for the person',
        'codespace' => 'codespaces are not offered',
        'gpg-key' => 'keys are not for the model',
        'ssh-key' => 'keys are not for the model',
    ];

    public static function refusal(string $mode, array $args): ?string
    {
        $tool = self::TOOLS[$mode] ?? 'gh';
        if ($args === [] || !is_string($args[0]) || str_starts_with($args[0], '-')) {
            return 'the first argument is the group of the command, for example pr or issue';
        }
        $group = $args[0];
        $command = $args[1] ?? '';
        if (!is_string($command) || str_starts_with($command, '-') || $command === '') {
            return self::REASONS[$group] ?? "{$group} needs a command after it, for example {$group} view";
        }
        $key = "{$group} {$command}";
        $rule = self::RULES[$mode][$key] ?? null;
        if ($rule === null) {
            $other = $mode === 'read' ? 'write' : 'read';
            if (isset(self::RULES[$other][$key])) {
                return "{$key} is a " . $other . " command: use " . self::TOOLS[$other];
            }

            return self::REASONS[$key] ?? self::REASONS[$group] ?? "{$key} is not allowed in {$tool}";
        }

        return self::check($key, $rule, array_slice($args, 2));
    }

    private static function check(string $key, array $rule, array $args): ?string
    {
        $seen = [];
        $positional = 0;
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if (!is_string($arg) || str_contains($arg, "\0")) {
                return 'every argument must be text';
            }
            if (!str_starts_with($arg, '-') || $arg === '-') {
                if (++$positional > $rule['positional']) {
                    return "{$key} takes " . ($rule['positional'] === 0 ? 'no argument' : 'one argument') . " besides its options; got {$arg}";
                }
                $problem = self::selector($arg);
                if ($problem !== null) {
                    return $problem;
                }
                continue;
            }
            [$name, $inline] = str_contains($arg, '=') ? explode('=', $arg, 2) : [$arg, null];
            if (!array_key_exists($name, $rule['options'])) {
                return "{$key} does not take the option {$name} here" . (in_array($name, ['--jq', '-q', '--template', '--web', '-w', '--body-file', '-F', '--editor', '-e', '--repo', '-R', '--attach', '--template', '-T', '--watch'], true) ? ': it can send files, run programs or read the environment' : '');
            }
            $takesValue = $rule['options'][$name];
            if ($takesValue) {
                if ($inline === null) {
                    if (!array_key_exists(++$i, $args) || !is_string($args[$i])) {
                        return "the option {$name} needs a value";
                    }
                    $inline = $args[$i];
                }
                if (in_array($name, ['--json'], true) && preg_match('/^[A-Za-z]+(,[A-Za-z]+)*$/D', $inline) !== 1) {
                    return '--json takes field names separated by commas, for example number,title,state';
                }
            } elseif ($inline !== null) {
                return "the option {$name} takes no value";
            }
            $seen[$name] = true;
        }
        $waived = isset($rule['unless']) && array_intersect_key($seen, array_flip($rule['unless'])) !== [];
        foreach ($waived ? [] : ($rule['all'] ?? []) as $group) {
            if (array_intersect_key($seen, array_flip($group)) === []) {
                return "{$key} cannot ask questions here: it needs {$group[0]}" . (isset($rule['unless']) ? ' (or --fill)' : '');
            }
        }

        return null;
    }

    private static function selector(string $value): ?string
    {
        if (preg_match('/^\d+$/D', $value) === 1 || preg_match('~^https://[A-Za-z0-9.-]+/[\w.-]+/[\w.-]+/(pull|issues|actions/runs)/\d+(/job/\d+)?$~D', $value) === 1) {
            return null;
        }
        if (preg_match('~^[A-Za-z0-9][\w.-]*(/[\w.-]+)*$~D', $value) === 1) {
            return null;
        }

        return 'the argument ' . substr(preg_replace('/\p{Cc}+/u', ' ', $value) ?? '', 0, 60) . ' is not a number, a branch, a repository or the address of a pull request';
    }
}
