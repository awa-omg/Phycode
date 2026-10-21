<?php

declare(strict_types=1);

namespace Phycode\Git;

final class Branch
{
    private const TIMEOUT_SECONDS = 2.0;

    public static function current(string $cwd): ?string
    {
        if (!is_dir($cwd)) {
            return null;
        }
        $pipes = [];
        $process = @proc_open(
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $cwd,
            self::environment(),
        );
        if (!is_resource($process)) {
            return null;
        }

        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $status = proc_get_status($process);
        while ($status['running'] && microtime(true) < $deadline) {
            usleep(10000);
            $status = proc_get_status($process);
        }
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        $branch = trim($output);
        if ($status['running'] || $status['exitcode'] !== 0 || $branch === '' || $branch === 'HEAD') {
            return null;
        }

        return $branch;
    }

    private static function environment(): array
    {
        return [
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => (string) (getenv('HOME') ?: '/root'),
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
        ];
    }
}
