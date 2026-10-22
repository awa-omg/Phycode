<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Exec\Launch;
use Phycode\Exec\Locale;
use Phycode\Exec\ProcessResult;
use Phycode\Exec\ProcessRunner;
use Phycode\Exec\ProcessTree;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;

final class ArgvRunner
{
    private const PIPE_GRACE_SECONDS = 1.0;
    private const KILL = 9;
    private const CHUNK = 65536;

    public static function run(array $argv, string $cwd, int $seconds, int $maxBytes, ?string $stdin = null, ?Cancellation $cancel = null): ProcessResult
    {
        if ($argv === [] || trim($argv[0]) === '') {
            throw new PhycodeException('the command is empty');
        }
        foreach ($argv as $part) {
            if (str_contains($part, "\0")) {
                throw new PhycodeException('the command contains a NUL byte');
            }
        }
        $program = self::program($argv[0], $cwd);
        $timeout = ProcessRunner::timeoutProgram();
        $command = Launch::command([$program, ...array_slice($argv, 1)], $seconds + 1, 'KILL', 2);
        $mark = ProcessTree::mark();
        $reaped = $timeout === null && Launch::reaper() !== null;

        $pipes = [];
        $started = microtime(true);
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']] + ($reaped ? [Launch::STATUS => ['pipe', 'w']] : []) + ProcessTree::holder($mark), $pipes, $cwd, self::environment($mark));
        ProcessTree::release($mark);
        if (!is_resource($process)) {
            throw new PhycodeException("cannot start {$argv[0]}");
        }
        $pid = (int) proc_get_status($process)['pid'];
        $grouped = $timeout !== null && $pid > 1;

        $input = $stdin ?? '';
        $writing = $stdin !== null && $input !== '';
        if ($writing) {
            stream_set_blocking($pipes[0], false);
        } else {
            fclose($pipes[0]);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $output = [1 => ['', 0], 2 => ['', 0]];
        $offset = 0;
        $deadline = $started + $seconds;
        $exitCode = null;
        $exitedAt = null;
        $interrupted = null;

        while (true) {
            $read = array_values($open);
            $write = $writing ? [$pipes[0]] : [];
            if ($read !== [] || $write !== []) {
                $except = null;
                @stream_select($read, $write, $except, 0, 100000);
            } else {
                usleep(20000);
            }

            if ($write !== []) {
                $sent = @fwrite($pipes[0], substr($input, $offset, self::CHUNK));
                $offset += is_int($sent) ? $sent : 0;
                if ($sent === false || $offset >= strlen($input)) {
                    $writing = false;
                    fclose($pipes[0]);
                }
            }

            foreach ($read as $stream) {
                $key = array_search($stream, $open, true);
                $chunk = fread($stream, self::CHUNK);
                if (is_string($chunk) && $chunk !== '') {
                    self::keep($output[$key], $chunk, $maxBytes);
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($open[$key]);
                }
            }

            $status = proc_get_status($process);
            $now = microtime(true);
            if (!$status['running'] && $exitCode === null) {
                $exitCode = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
                $exitedAt = $now;
            }
            if ($exitCode === null) {
                if ($cancel !== null && $cancel->isCancelled()) {
                    $interrupted = 'cancelled';
                } elseif ($now >= $deadline) {
                    $interrupted = 'timeout';
                }
                if ($interrupted !== null) {
                    ProcessTree::signal($pid, self::KILL, true, $mark, $reaped);
                    break;
                }
            }
            if ($exitedAt !== null && ($open === [] || $now - $exitedAt >= self::PIPE_GRACE_SECONDS)) {
                break;
            }
        }

        foreach ($open as $stream) {
            fclose($stream);
        }
        if ($writing) {
            fclose($pipes[0]);
        }
        $status = proc_get_status($process);
        $exitCode ??= $status['running'] ? -1 : $status['exitcode'];
        if ($grouped) {
            ProcessTree::signal($pid, self::KILL, false);
        }
        $reported = $reaped ? Launch::reported($pipes[Launch::STATUS]) : null;
        if ($reported !== null) {
            $exitCode = $reported;
        }
        proc_close($process);

        return new ProcessResult($exitCode, self::text($output[1]), self::text($output[2]), $interrupted, microtime(true) - $started);
    }

    private static function program(string $program, string $cwd): string
    {
        if (!str_contains($program, '/')) {
            return ProcessRunner::which($program) ?? throw new PhycodeException("cannot start {$program}: it is not on PATH");
        }
        $path = str_starts_with($program, '/') ? $program : $cwd . '/' . $program;
        if (!is_file($path) || !is_executable($path)) {
            throw new PhycodeException("cannot start {$program}: it is not an executable file");
        }

        return $path;
    }

    private static function keep(array &$buffer, string $chunk, int $max): void
    {
        $buffer[1] += strlen($chunk);
        $room = $max - strlen($buffer[0]);
        if ($room > 0) {
            $buffer[0] .= substr($chunk, 0, $room);
        }
    }

    private static function text(array $buffer): string
    {
        $omitted = $buffer[1] - strlen($buffer[0]);
        $middle = $omitted > 0 ? "\n[... {$omitted} bytes omitted ...]" : '';

        return mb_scrub($buffer[0] . $middle, 'UTF-8');
    }

    private static function environment(string $mark): array
    {
        $environment = [
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => (string) (getenv('HOME') ?: '/root'),
            ...Locale::variables(),
            'TERM' => 'dumb',
            ProcessTree::MARK => $mark,
        ];
        $temporary = getenv('TMPDIR');
        if (is_string($temporary) && $temporary !== '') {
            $environment['TMPDIR'] = $temporary;
        }

        return $environment;
    }
}
