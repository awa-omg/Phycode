<?php

declare(strict_types=1);

namespace Phycode\Git;

use Phycode\Support\Files;
use Phycode\Support\PhycodeException;
use Phycode\Tools\ArgvRunner;

final class Worktrees
{
    public const BRANCH_PREFIX = 'phycode/';
    private const NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/D';
    private const TIMEOUT_SECONDS = 60;
    private const OUTPUT_BYTES = 262144;

    public function __construct(private readonly string $root)
    {
    }

    public static function validName(string $name): bool
    {
        return preg_match(self::NAME, $name) === 1 && !str_ends_with($name, '.lock') && !str_contains($name, '..');
    }

    public function add(string $name): array
    {
        $this->checkName($name);
        $prefix = $this->git($this->root, ['rev-parse', '--show-prefix']);
        $base = $this->git($this->root, ['rev-parse', '--verify', '-q', 'HEAD^{commit}'], 'this repository has no commit yet, so there is nothing to branch a worktree from');
        $path = $this->prepare() . '/' . $name;
        if (file_exists($path)) {
            throw new PhycodeException("the worktree {$name} exists already: phycode worktree remove {$name}, or pick another name");
        }
        $this->git($this->root, ['worktree', 'add', '-b', self::BRANCH_PREFIX . $name, $path, $base]);

        return [
            'name' => $name,
            'path' => $path,
            'cwd' => $prefix === '' ? $path : $path . '/' . rtrim($prefix, '/'),
            'branch' => self::BRANCH_PREFIX . $name,
            'base' => $base,
        ];
    }

    public function changed(array $worktree): bool
    {
        if (!is_dir($worktree['path'])) {
            return false;
        }
        $status = $this->git($worktree['path'], ['status', '--porcelain']);
        $head = $this->git($worktree['path'], ['rev-parse', 'HEAD']);

        return $status !== '' || $head !== $worktree['base'];
    }

    public function remove(string $name): void
    {
        $this->checkName($name);
        $path = $this->folder() . '/' . $name;
        if (!is_dir($path)) {
            throw new PhycodeException("no worktree named {$name}; phycode worktree list shows them");
        }
        $this->git($this->root, ['worktree', 'remove', '--force', $path]);
        try {
            $this->git($this->root, ['branch', '-D', self::BRANCH_PREFIX . $name]);
        } catch (PhycodeException) {
        }
        $this->git($this->root, ['worktree', 'prune']);
    }

    public function all(): array
    {
        $folder = $this->folder();
        if (!is_dir($folder)) {
            return [];
        }
        $main = $this->git($this->root, ['rev-parse', 'HEAD']);
        $worktrees = [];
        foreach (scandir($folder) ?: [] as $name) {
            $path = $folder . '/' . $name;
            if ($name === '.' || $name === '..' || !is_dir($path) || !self::validName($name)) {
                continue;
            }
            try {
                $worktrees[] = [
                    'name' => $name,
                    'branch' => $this->git($path, ['rev-parse', '--abbrev-ref', 'HEAD']),
                    'dirty' => $this->git($path, ['status', '--porcelain']) !== '',
                    'ahead' => (int) $this->git($path, ['rev-list', '--count', 'HEAD', '--not', $main]),
                ];
            } catch (PhycodeException) {
                continue;
            }
        }

        return $worktrees;
    }

    private function checkName(string $name): void
    {
        if (!self::validName($name)) {
            throw new PhycodeException('a worktree name is letters, digits, dots, hyphens and underscores, from 1 to 40 characters, such as fix-parser');
        }
    }

    private function folder(): string
    {
        return Files::runtimeFolder($this->root, 'worktrees');
    }

    private function prepare(): string
    {
        $folder = $this->folder();
        $ignore = dirname($folder) . '/.gitignore';
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new PhycodeException("cannot create the worktree folder {$folder}");
        }
        if (!is_file($ignore) && !is_link($ignore)) {
            file_put_contents($ignore, "*\n");
        }

        return $folder;
    }

    private function git(string $cwd, array $arguments, ?string $failure = null): string
    {
        $result = ArgvRunner::run(['git', '-c', 'core.hooksPath=/dev/null', ...$arguments], $cwd, self::TIMEOUT_SECONDS, self::OUTPUT_BYTES);
        if (!$result->succeeded()) {
            $why = trim(strtok(trim($result->stderr) . "\n", "\n") ?: '');

            throw new PhycodeException($failure ?? ('git ' . $arguments[0] . ' failed' . ($why === '' ? '' : ": {$why}")));
        }

        return trim($result->stdout);
    }
}
