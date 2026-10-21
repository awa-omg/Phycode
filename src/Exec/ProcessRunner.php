<?php

declare(strict_types=1);

namespace Phycode\Exec;

use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;

final class ProcessRunner implements CommandRunner, Backgroundable
{
    private const PIPE_GRACE_SECONDS = 2.0;
    private const TERM_GRACE_SECONDS = 2.0;
    private const TERM = 15;
    private const KILL = 9;

    public function run(array $argv, string $cwd, int $timeout, int $maxBytes, ?Cancellation $cancel = null, array $env = []): ProcessResult
    {
        if ($argv === []) {
            throw new PhycodeException('empty command');
        }
        $timeoutBinary = self::which('timeout');
        $command = $timeoutBinary !== null
            ? [$timeoutBinary, '--signal=KILL', '--kill-after=2', (string) max(1, $timeout), ...$argv]
            : $argv;

        $pipes = [];
        $started = microtime(true);
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, self::environment($env));
        if (!is_resource($process)) {
            throw new PhycodeException('cannot start ' . $argv[0]);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $pid = proc_get_status($process)['pid'];
        $grouped = $timeoutBinary !== null && $pid > 1;

        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $buffers = [1 => self::emptyBuffer(), 2 => self::emptyBuffer()];
        $headMax = (int) ($maxBytes * 0.7);
        $tailMax = $maxBytes - $headMax;
        $hardDeadline = $started + $timeout + 10;
        $interrupted = null;
        $stopping = null;
        $exitCode = null;
        $signal = null;
        $exitedAt = null;

        while (true) {
            if ($open !== []) {
                $read = array_values($open);
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 100000);
                foreach ($read as $stream) {
                    $key = array_search($stream, $open, true);
                    $chunk = fread($stream, 65536);
                    if (is_string($chunk) && $chunk !== '') {
                        self::append($buffers[$key], $chunk, $headMax, $tailMax);
                    }
                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$key]);
                    }
                }
            } else {
                usleep(20000);
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode ??= $status['exitcode'];
                $signal ??= $status['signaled'] ? $status['termsig'] : null;
            }
            $now = microtime(true);
            if ($interrupted === null && $cancel !== null && $cancel->isCancelled()) {
                $interrupted = 'cancelled';
                $stopping = $now;
                self::signal($pid, $grouped, self::TERM);
            } elseif ($interrupted === null && $now > $hardDeadline) {
                $interrupted = 'timeout';
                self::signal($pid, $grouped, self::KILL);
                if ($status['running']) {
                    proc_terminate($process, self::KILL);
                }
            }
            if ($stopping !== null && $now - $stopping >= self::TERM_GRACE_SECONDS) {
                self::signal($pid, $grouped, self::KILL);
                $stopping = null;
            }
            if (!$status['running']) {
                $exitedAt ??= $now;
                $settled = $open === [] && ($stopping === null || !self::alive($pid, $grouped));
                if ($settled || ($stopping === null && $now - $exitedAt > self::PIPE_GRACE_SECONDS)) {
                    break;
                }
            }
        }

        foreach ($open as $stream) {
            fclose($stream);
        }
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, self::KILL);
            $exitCode ??= -1;
        } else {
            $exitCode ??= $status['exitcode'];
            $signal ??= $status['signaled'] ? $status['termsig'] : null;
        }
        if ($grouped) {
            self::signal($pid, true, self::KILL);
        }
        proc_close($process);

        $seconds = microtime(true) - $started;
        $code = $exitCode ?? -1;
        $killedByBudget = $code === 124 || $code === 137 || $code === -1 || $signal === 9 || $signal === 15;
        if ($interrupted === null && $killedByBudget && $seconds >= $timeout) {
            $interrupted = 'timeout';
        }

        return new ProcessResult($code, self::text($buffers[1]), self::text($buffers[2]), $interrupted, $seconds);
    }

    public function start(array $argv, string $cwd, string $logFile, int $lifetime, array $env = []): RunningProcess
    {
        if ($argv === []) {
            throw new PhycodeException('empty command');
        }
        $timeoutBinary = self::which('timeout');
        if ($timeoutBinary === null) {
            throw new PhycodeException('a command in the background needs the timeout program (GNU coreutils)');
        }
        $command = [$timeoutBinary, '--signal=TERM', '--kill-after=5', (string) max(1, $lifetime), ...$argv];
        $pipes = [];
        $process = @proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
            $pipes,
            $cwd,
            self::environment($env),
        );
        if (!is_resource($process)) {
            throw new PhycodeException('cannot start ' . $argv[0]);
        }
        $pid = proc_get_status($process)['pid'];
        if ($pid <= 1) {
            proc_terminate($process, self::KILL);
            proc_close($process);
            throw new PhycodeException('cannot start ' . $argv[0]);
        }

        return new RunningProcess($process, $pid);
    }

    public static function which(string $binary): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }
            $candidate = $directory . '/' . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function signal(int $pid, bool $grouped, int $signal): void
    {
        if ($pid > 1) {
            @posix_kill($grouped ? -$pid : $pid, $signal);
        }
    }

    private static function alive(int $pid, bool $grouped): bool
    {
        return $pid > 1 && @posix_kill($grouped ? -$pid : $pid, 0);
    }

    private static function environment(array $extra): array
    {
        $environment = [
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => (string) (getenv('HOME') ?: '/root'),
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
            'TERM' => 'dumb',
            'NO_COLOR' => '1',
            'PYTHONUNBUFFERED' => '1',
            'PYTHONDONTWRITEBYTECODE' => '1',
        ];
        $temporary = getenv('TMPDIR');
        if (is_string($temporary) && $temporary !== '') {
            $environment['TMPDIR'] = $temporary;
        }

        return array_merge($environment, $extra);
    }

    private static function emptyBuffer(): array
    {
        return ['head' => '', 'tail' => '', 'total' => 0];
    }

    private static function append(array &$buffer, string $chunk, int $headMax, int $tailMax): void
    {
        $buffer['total'] += strlen($chunk);
        $room = $headMax - strlen($buffer['head']);
        if ($room > 0) {
            $buffer['head'] .= substr($chunk, 0, $room);
            $chunk = (string) substr($chunk, $room);
        }
        if ($chunk !== '' && $tailMax > 0) {
            $buffer['tail'] = (string) substr($buffer['tail'] . $chunk, -$tailMax);
        }
    }

    private static function text(array $buffer): string
    {
        $omitted = $buffer['total'] - strlen($buffer['head']) - strlen($buffer['tail']);
        $middle = $omitted > 0 ? "\n[... {$omitted} bytes omitted ...]\n" : '';

        return mb_scrub($buffer['head'] . $middle . $buffer['tail'], 'UTF-8');
    }
}
