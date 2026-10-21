<?php

declare(strict_types=1);

namespace Phycode\Diff;

use Phycode\Exec\ProcessResult;
use Phycode\Exec\ProcessRunner;
use Phycode\Support\PhycodeException;

final class GitDiff
{
    private const TIMEOUT_SECONDS = 30.0;
    private const MAX_OUTPUT_BYTES = 16777216;
    private const MAX_ERROR_BYTES = 8192;
    private const DIFF_OPTIONS = ['--no-color', '--no-ext-diff', '--no-textconv', '--src-prefix=a/', '--dst-prefix=b/', '--unified=3'];

    public static function files(string $root, float $timeout = self::TIMEOUT_SECONDS, int $maxBytes = self::MAX_OUTPUT_BYTES): array
    {
        if (ProcessRunner::which('git') === null) {
            throw new PhycodeException('git is not installed, so the changes cannot be listed');
        }
        $probe = self::run(['git', 'rev-parse', '--show-prefix', '--git-path', 'index'], $root, self::environment(), $timeout, $maxBytes);
        if ($probe->exitCode !== 0) {
            throw new PhycodeException("{$root} is not inside a git repository");
        }
        [$prefix, $index] = explode("\n", rtrim($probe->stdout, "\n")) + ['', ''];
        $index = str_starts_with($index, '/') ? $index : $root . '/' . $index;

        $scratch = self::scratchFolder();
        $copy = $scratch . '/index';
        try {
            if (is_file($index) && !@copy($index, $copy)) {
                throw new PhycodeException('cannot copy the git index for reading');
            }
            $environment = self::environment() + ['GIT_INDEX_FILE' => $copy];
            $status = self::entries(self::expect(self::run(
                ['git', 'status', '--porcelain=v1', '-z', '--untracked-files=all', '--no-renames'],
                $root,
                $environment,
                $timeout,
                $maxBytes,
            ), ['status']));
            $files = UnifiedDiff::parse(self::expect(self::run(
                ['git', 'diff', '--relative', ...self::DIFF_OPTIONS],
                $root,
                $environment,
                $timeout,
                $maxBytes,
            ), ['diff']));
            foreach ($status as $entry) {
                $path = $entry['code'] === '??' ? self::inFolder($entry['path'], $prefix) : null;
                if ($path !== null) {
                    $files[] = self::untracked($root, $path, $timeout, $maxBytes);
                }
            }
        } finally {
            @unlink($copy);
            @rmdir($scratch);
        }
        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $files;
    }

    private static function untracked(string $root, string $path, float $timeout, int $maxBytes): array
    {
        if (is_link($root . '/' . $path)) {
            return FileDiff::of($path);
        }
        $result = self::run(['git', 'diff', '--no-index', ...self::DIFF_OPTIONS, '--', '/dev/null', $path], $root, self::environment(), $timeout, $maxBytes);
        $parsed = UnifiedDiff::parse(self::expect($result, ['diff'], [0, 1]));

        return isset($parsed[0]) ? [...$parsed[0], 'path' => $path] : FileDiff::of($path);
    }

    private static function entries(string $output): array
    {
        $entries = [];
        $fields = explode("\0", $output);
        for ($index = 0, $count = count($fields); $index < $count; $index++) {
            if (strlen($fields[$index]) < 4) {
                continue;
            }
            $code = substr($fields[$index], 0, 2);
            $entries[] = ['code' => $code, 'path' => substr($fields[$index], 3)];
            if (str_contains('RC', $code[0]) || str_contains('RC', $code[1])) {
                $index++;
            }
        }

        return $entries;
    }

    private static function inFolder(string $path, string $prefix): ?string
    {
        if ($prefix !== '') {
            if (!str_starts_with($path, $prefix)) {
                return null;
            }
            $path = substr($path, strlen($prefix));
        }

        return $path === '' || str_ends_with($path, '/') ? null : $path;
    }

    private static function expect(ProcessResult $result, array $argv, array $accepted = [0]): string
    {
        if (!in_array($result->exitCode, $accepted, true)) {
            $detail = explode("\n", trim($result->stderr), 2)[0];

            throw new PhycodeException("git {$argv[0]} failed (exit {$result->exitCode})" . ($detail !== '' ? ": {$detail}" : ''));
        }

        return $result->stdout;
    }

    private static function run(array $argv, string $cwd, array $environment, float $timeout, int $maxBytes): ProcessResult
    {
        $started = microtime(true);
        $pipes = [];
        $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
        if (!is_resource($process)) {
            throw new PhycodeException('cannot start git');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $streams = [1 => $pipes[1], 2 => $pipes[2]];
        $stdout = '';
        $stderr = '';
        $problem = null;
        while ($streams !== []) {
            $read = array_values($streams);
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 100000);
            foreach ($read as $stream) {
                $key = array_search($stream, $streams, true);
                $chunk = fread($stream, 65536);
                if (is_string($chunk) && $chunk !== '') {
                    if ($key === 1) {
                        $stdout .= $chunk;
                    } elseif (strlen($stderr) < self::MAX_ERROR_BYTES) {
                        $stderr .= $chunk;
                    }
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($streams[$key]);
                }
            }
            if (strlen($stdout) > $maxBytes) {
                $problem = 'output';
                break;
            }
            if (microtime(true) - $started > $timeout) {
                $problem = 'timeout';
                break;
            }
        }

        $exitCode = -1;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if ($problem === null && microtime(true) - $started > $timeout) {
                $problem = 'timeout';
            }
            if ($problem !== null) {
                proc_terminate($process, 9);
            }
            usleep(5000);
        }
        foreach ($streams as $stream) {
            fclose($stream);
        }
        proc_close($process);

        $subcommand = $argv[1] ?? $argv[0];
        if ($problem === 'timeout') {
            throw new PhycodeException("git {$subcommand} took longer than " . (int) ceil($timeout) . ' seconds');
        }
        if ($problem === 'output') {
            throw new PhycodeException("git {$subcommand} printed more than {$maxBytes} bytes; the changes are too large to show");
        }

        return new ProcessResult($exitCode, $stdout, $stderr, null, microtime(true) - $started);
    }

    private static function scratchFolder(): string
    {
        $folder = sys_get_temp_dir() . '/phycode-git-' . bin2hex(random_bytes(6));
        if (!@mkdir($folder, 0700)) {
            throw new PhycodeException('cannot create a temporary folder for git');
        }

        return $folder;
    }

    private static function environment(): array
    {
        return [
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => (string) (getenv('HOME') ?: '/root'),
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_PAGER' => 'cat',
            'PAGER' => 'cat',
        ];
    }
}
