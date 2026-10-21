<?php

declare(strict_types=1);

namespace Phycode\Review;

use Phycode\Exec\ProcessResult;
use Phycode\Exec\ProcessRunner;
use Phycode\Security\SecretFiles;
use Phycode\Support\PhycodeException;

final readonly class ChangeSet
{
    public const LIMIT = 61440;

    private const REF = '/^[A-Za-z0-9][A-Za-z0-9._\/@~^{}+-]{0,199}$/D';
    private const TIMEOUT = 30;
    private const MAX_OUTPUT = 16777216;
    private const DIFF = ['--no-color', '--no-ext-diff', '--no-textconv', '--src-prefix=a/', '--dst-prefix=b/', '--unified=3'];
    private const SCOPE = ['--', '.', ':(exclude).phycode'];

    public function __construct(
        public string $base,
        public array $files,
        public string $text,
        public int $bytes,
        public bool $cut,
        public int $withheld = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->bytes === 0;
    }

    public function summary(): string
    {
        $count = count($this->files);

        return ($count === 1 ? '1 file' : "{$count} files") . ", {$this->bytes} bytes"
            . ($this->cut ? ', cut to 60 KB' : '')
            . ($this->withheld > 0 ? ", {$this->withheld} withheld (usually secrets)" : '');
    }

    public static function read(ProcessRunner $processes, string $cwd, ?string $baseRef): self
    {
        if (ProcessRunner::which('git') === null) {
            throw new PhycodeException('git is not installed, so the changes cannot be read');
        }
        if (self::probe($processes, $cwd, ['rev-parse', '--is-inside-work-tree']) === null) {
            throw new PhycodeException("{$cwd} is not inside a git repository");
        }

        if ($baseRef === null) {
            if (self::probe($processes, $cwd, ['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']) === null) {
                throw new PhycodeException('the repository has no commit yet, so there is nothing to compare with; name a base ref');
            }
            $target = 'HEAD';
            $base = 'uncommitted changes against HEAD';
        } else {
            if (preg_match(self::REF, $baseRef) !== 1) {
                throw new PhycodeException("'{$baseRef}' is not a ref: use one word such as main or HEAD~2");
            }
            $sha = self::probe($processes, $cwd, ['rev-parse', '--verify', '--quiet', $baseRef . '^{commit}']);
            if ($sha === null) {
                throw new PhycodeException("'{$baseRef}' does not name a commit");
            }
            $target = trim($sha);
            $base = "changes against {$baseRef} (" . substr($target, 0, 12) . ')';
        }

        $files = self::names(self::git($processes, $cwd, ['diff', '--relative', '--name-only', '-z', $target, ...self::SCOPE]));
        $secret = array_values(array_filter($files, static fn (string $path): bool => SecretFiles::matches($path)));
        $exclusions = array_map(static fn (string $path): string => ":(exclude,literal){$path}", $secret);
        $full = self::git($processes, $cwd, ['diff', '--relative', ...self::DIFF, $target, ...self::SCOPE, ...$exclusions]);
        $unread = false;
        foreach (self::names(self::git($processes, $cwd, ['ls-files', '--others', '--exclude-standard', '-z', ...self::SCOPE])) as $path) {
            $files[] = $path;
            if (SecretFiles::matches($path)) {
                $secret[] = $path;
            } elseif (strlen($full) > self::LIMIT) {
                $unread = true;
            } elseif (is_file("{$cwd}/{$path}") && !is_link("{$cwd}/{$path}")) {
                $full .= self::untracked($processes, $cwd, $path);
            }
        }

        return self::bounded($base, $files, $full, $unread, count($secret));
    }

    private static function bounded(string $base, array $files, string $full, bool $unread, int $withheld): self
    {
        $bytes = strlen($full);
        $left = $withheld === 0 ? '' : self::withheldNote($withheld);
        if ($bytes <= self::LIMIT && !$unread) {
            return new self($base, $files, $left . $full, $bytes, false, $withheld);
        }
        $omitted = max(0, $bytes - self::LIMIT);
        $kept = $bytes > self::LIMIT ? mb_scrub(substr($full, 0, self::LIMIT), 'UTF-8') : $full;
        $note = "[... change set cut at 60 KB: {$omitted} bytes omitted" . ($unread ? '; untracked files after that point were not read' : '') . ']';

        return new self($base, $files, $left . $kept . "\n" . $note . "\n", $bytes, true, $withheld);
    }

    private static function withheldNote(int $count): string
    {
        $files = $count === 1 ? '1 changed file was' : "{$count} changed files were";

        return "[... {$files} withheld from the review: files with these names usually hold secrets ...]\n";
    }

    private static function untracked(ProcessRunner $processes, string $cwd, string $path): string
    {
        $result = $processes->run(['git', 'diff', '--no-index', ...self::DIFF, '--', '/dev/null', $path], $cwd, self::TIMEOUT, self::MAX_OUTPUT);
        if (!in_array($result->exitCode, [0, 1], true) || $result->interrupted !== null) {
            throw new PhycodeException("git diff failed for {$path}");
        }

        return $result->stdout;
    }

    private static function git(ProcessRunner $processes, string $cwd, array $argv): string
    {
        $result = self::run($processes, $cwd, $argv);
        if ($result->exitCode !== 0 || $result->interrupted !== null) {
            $detail = explode("\n", trim($result->stderr))[0];

            throw new PhycodeException("git {$argv[0]} failed" . ($detail === '' ? '' : ": {$detail}"));
        }

        return $result->stdout;
    }

    private static function probe(ProcessRunner $processes, string $cwd, array $argv): ?string
    {
        $result = self::run($processes, $cwd, $argv);

        return $result->exitCode === 0 && $result->interrupted === null ? $result->stdout : null;
    }

    private static function run(ProcessRunner $processes, string $cwd, array $argv): ProcessResult
    {
        return $processes->run(['git', ...$argv], $cwd, self::TIMEOUT, self::MAX_OUTPUT);
    }

    private static function names(string $nulSeparated): array
    {
        return array_values(array_filter(explode("\0", $nulSeparated), static fn (string $name): bool => $name !== ''));
    }
}
