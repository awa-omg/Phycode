<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

final class AcpProcess
{
    private $stdin;
    private $stdout;
    private $process;
    private string $pending = '';
    private int $ids = 0;

    public array $log = [];

    private function __construct($process, array $pipes)
    {
        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
    }

    public static function start(string $home, array $arguments = []): self
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, $root . '/bin/phycode', 'acp', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $home . '/stderr.txt', 'w']],
            $pipes,
            $root,
            ['PATH' => (string) getenv('PATH'), 'HOME' => $home, 'XDG_CONFIG_HOME' => $home . '/config', 'XDG_CACHE_HOME' => $home . '/cache'],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start the agent');
        }

        return new self($process, $pipes);
    }

    public static function configure(string $home, string $baseUrl, array $more = [], array $profile = []): void
    {
        $profile += ['base_url' => $baseUrl, 'model' => 'scripted', 'tool_mode' => 'native'];
        Kit::write($home . '/config/phycode/config.json', json_encode($more + ['default_profile' => 'test', 'profiles' => ['test' => $profile]], JSON_THROW_ON_ERROR));
    }

    public function request(string $method, array $params = []): int
    {
        $id = ++$this->ids;
        $this->write(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);

        return $id;
    }

    public function notify(string $method, array $params = []): void
    {
        $this->write(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]);
    }

    public function respond(int|string $id, array $result): void
    {
        $this->write(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    public function write(array $message): void
    {
        fwrite($this->stdin, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
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
                    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $this->log[] = $message;

                    return $message;
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

    public function answer(int $id, ?callable $onRequest = null, float $seconds = 30.0): array
    {
        $deadline = microtime(true) + $seconds;
        while (($message = $this->next(max(0.0, $deadline - microtime(true)))) !== null) {
            if (!isset($message['method']) && ($message['id'] ?? null) === $id) {
                return $message;
            }
            if (isset($message['method'], $message['id']) && $onRequest !== null) {
                $this->respond($message['id'], $onRequest($message));
            }
        }
        throw new \RuntimeException('no answer to request ' . $id . '; log: ' . json_encode($this->log));
    }

    public function updates(string $kind): array
    {
        $found = [];
        foreach ($this->log as $message) {
            if (($message['method'] ?? null) === 'session/update' && ($message['params']['update']['sessionUpdate'] ?? null) === $kind) {
                $found[] = $message['params']['update'];
            }
        }

        return $found;
    }

    public function agentText(): string
    {
        return implode('', array_map(static fn (array $update): string => (string) ($update['content']['text'] ?? ''), $this->updates('agent_message_chunk')));
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
