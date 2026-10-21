<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

final class RpcProcess
{
    private $stdin;
    private $stdout;
    private $process;
    private string $pending = '';

    private function __construct($process, array $pipes)
    {
        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
    }

    public static function start(string $home, string $cwd): self
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, $root . '/bin/phycode', 'rpc', '--cwd', $cwd],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $home . '/stderr.txt', 'w']],
            $pipes,
            $root,
            [
                'PATH' => (string) getenv('PATH'),
                'HOME' => $home,
                'XDG_CONFIG_HOME' => $home . '/config',
                'XDG_CACHE_HOME' => $home . '/cache',
            ],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start the backend');
        }

        return new self($process, $pipes);
    }

    public static function configure(string $home, string $baseUrl, string $toolMode, string $permissionMode = 'ask'): void
    {
        $profile = ['base_url' => $baseUrl, 'model' => 'scripted', 'tool_mode' => $toolMode];
        $config = ['default_profile' => 'test', 'profiles' => ['test' => $profile], 'permissions' => ['mode' => $permissionMode]];
        Kit::write($home . '/config/phycode/config.json', json_encode($config, JSON_THROW_ON_ERROR));
    }

    public function send(array $command): void
    {
        fwrite($this->stdin, json_encode($command, JSON_THROW_ON_ERROR) . "\n");
    }

    public function next(float $seconds): ?array
    {
        $deadline = microtime(true) + $seconds;
        while (true) {
            $end = strpos($this->pending, "\n");
            if ($end !== false) {
                $line = substr($this->pending, 0, $end);
                $this->pending = (string) substr($this->pending, $end + 1);
                if (trim($line) !== '') {
                    return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                }

                continue;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0.0) {
                return null;
            }
            $read = [$this->stdout];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, max(1, (int) ($left * 1000000))) === false) {
                return null;
            }
            if ($read === []) {
                continue;
            }
            $chunk = fread($this->stdout, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($this->stdout)) {
                    return null;
                }

                continue;
            }
            $this->pending .= $chunk;
        }
    }

    public function until(string $type, float $seconds = 20.0): ?array
    {
        $events = [];
        $deadline = microtime(true) + $seconds;
        while (($event = $this->next(max(0.0, $deadline - microtime(true)))) !== null) {
            $events[] = $event;
            if (($event['type'] ?? null) === $type) {
                return $events;
            }
        }

        return null;
    }

    public function close(): int
    {
        fclose($this->stdin);
        stream_set_blocking($this->stdout, true);
        stream_set_timeout($this->stdout, 30);
        stream_get_contents($this->stdout);
        fclose($this->stdout);

        return proc_close($this->process);
    }
}
