<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

final class ServeProcess
{
    private $process;
    private $stdout;
    public string $listening = '';

    private function __construct($process, $stdout)
    {
        $this->process = $process;
        $this->stdout = $stdout;
    }

    public static function start(string $home, string $cwd, array $arguments, array $env = []): self
    {
        RpcProcess::configure($home, 'http://127.0.0.1:9', 'text');
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, $root . '/bin/phycode', 'serve', '--cwd', $cwd, ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $home . '/serve-stderr.txt', 'w']],
            $pipes,
            $root,
            [...['PATH' => (string) getenv('PATH'), 'HOME' => $home, 'XDG_CONFIG_HOME' => $home . '/config', 'XDG_CACHE_HOME' => $home . '/cache'], ...$env],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start phycode serve');
        }
        $server = new self($process, $pipes[1]);
        stream_set_timeout($pipes[1], 20);
        $server->listening = trim((string) fgets($pipes[1]));

        return $server;
    }

    public function running(): bool
    {
        return proc_get_status($this->process)['running'];
    }

    public function pid(): int
    {
        return proc_get_status($this->process)['pid'];
    }

    public function stop(): int
    {
        if ($this->running()) {
            posix_kill($this->pid(), SIGTERM);
        }
        $deadline = microtime(true) + 15;
        while ($this->running() && microtime(true) < $deadline) {
            usleep(50000);
        }
        if ($this->running()) {
            posix_kill($this->pid(), SIGKILL);
        }
        fclose($this->stdout);

        return proc_close($this->process);
    }

    public static function connect(string $address, float $seconds = 5.0)
    {
        $socket = @stream_socket_client($address, $errno, $message, $seconds);
        if ($socket === false) {
            throw new \RuntimeException("cannot connect to {$address}: {$message}");
        }
        stream_set_timeout($socket, 15);

        return $socket;
    }

    public static function send($socket, array $message): void
    {
        fwrite($socket, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
    }

    public static function read($socket): ?array
    {
        $line = fgets($socket);

        return $line === false ? null : json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function readUntil($socket, string $type): array
    {
        $events = [];
        while (($event = self::read($socket)) !== null) {
            $events[] = $event;
            if (($event['type'] ?? null) === $type) {
                break;
            }
        }

        return $events;
    }
}
