<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Exec\Locale;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;

final class McpClient extends McpConnection
{
    private const MAX_MESSAGE_BYTES = 16777216;
    private const CHUNK_BYTES = 65536;
    private const EXIT_GRACE_SECONDS = 1.0;
    private const TERMINATE_SECONDS = 1.0;
    private const DRAIN_SECONDS = 0.5;

    private $process = null;
    private $stdin = null;
    private $stdout = null;
    private $stderr = null;

    private string $outgoing = '';
    private string $incoming = '';
    private string $stderrTail = '';
    private int $lastId = 0;

    public function __construct(
        string $name,
        private readonly array $argv,
        private readonly ?string $cwd = null,
        private readonly array $env = [],
        string $clientVersion = '0.1.0',
        private readonly float $timeout = 30.0,
        private readonly float $shutdownSeconds = 2.0,
        private readonly int $stderrBytes = 8192,
    ) {
        parent::__construct($name, $clientVersion);
        if ($argv === [] || !array_is_list($argv)) {
            throw new PhycodeException("MCP server {$name}: the command must be a non-empty list");
        }
        foreach ($argv as $part) {
            if (!is_string($part) || str_contains($part, "\0")) {
                throw new PhycodeException("MCP server {$name}: every part of the command must be a string");
            }
        }
        if ($timeout <= 0.0 || $shutdownSeconds < 0.0 || $stderrBytes < 1) {
            throw new PhycodeException("MCP server {$name}: the timeouts must be positive and the stderr limit at least 1 byte");
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function stderr(): string
    {
        return $this->stderrTail;
    }

    protected function open(): void
    {
        $pipes = [];
        $cwd = $this->cwd ?? (getcwd() ?: sys_get_temp_dir());
        $process = @proc_open(
            $this->argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            array_merge(self::baseEnvironment(), $this->env),
        );
        if (!is_resource($process)) {
            throw new PhycodeException("MCP server {$this->name}: cannot start {$this->argv[0]}");
        }

        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];
        stream_set_blocking($this->stdin, false);
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
        stream_set_read_buffer($this->stdout, 0);
        stream_set_read_buffer($this->stderr, 0);
    }

    private static function baseEnvironment(): array
    {
        $environment = [
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => (string) (getenv('HOME') ?: '/root'),
            ...Locale::variables(),
            'TERM' => 'dumb',
            'PYTHONUNBUFFERED' => '1',
        ];
        $temporary = getenv('TMPDIR');
        if (is_string($temporary) && $temporary !== '') {
            $environment['TMPDIR'] = $temporary;
        }

        return $environment;
    }

    protected function exchange(string $method, array $params): array
    {
        $id = ++$this->lastId;
        $this->queue(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);
        $response = $this->awaitResponse($id, $method);
        $this->flush();

        if (isset($response['error'])) {
            $error = is_array($response['error']) ? $response['error'] : [];
            $message = is_string($error['message'] ?? null) ? self::plain($error['message']) : 'no message';
            $code = is_int($error['code'] ?? null) ? $error['code'] : null;

            throw new McpRpcException("MCP server {$this->name}: {$method} failed: {$message}" . ($code === null ? '' : " (code {$code})"), $code, $error['data'] ?? null);
        }

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    private function awaitResponse(int $id, string $method): array
    {
        $deadline = microtime(true) + $this->timeout;
        $exitedAt = null;
        while (true) {
            while (($message = $this->nextMessage()) !== null) {
                if (array_key_exists('method', $message)) {
                    $this->answer($message);
                } elseif (($message['id'] ?? null) === $id) {
                    return $message;
                }
            }

            if (!$this->running()) {
                $exitedAt ??= microtime(true);
            }
            if ($this->stdin === null || $this->stdout === null || ($exitedAt !== null && microtime(true) - $exitedAt > self::EXIT_GRACE_SECONDS)) {
                throw $this->fail($this->stoppedMessage("while waiting for {$method}"));
            }

            $left = $deadline - microtime(true);
            if ($left <= 0.0) {
                if ($method !== 'initialize' && $method !== 'server/discover') {
                    $this->queue(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => $id, 'reason' => 'timed out']]);
                }

                throw new McpTimeout("MCP server {$this->name} did not answer {$method} within {$this->timeout} s");
            }
            $this->pump(min($left, 0.1));
        }
    }

    private function nextMessage(): ?array
    {
        while (($end = strpos($this->incoming, "\n")) !== false) {
            $line = substr($this->incoming, 0, $end);
            $this->incoming = substr($this->incoming, $end + 1);
            $message = Json::object(trim($line));
            if ($message !== null) {
                return $message;
            }
        }
        if (strlen($this->incoming) > self::MAX_MESSAGE_BYTES) {
            throw $this->fail("MCP server {$this->name} sent a message longer than " . intdiv(self::MAX_MESSAGE_BYTES, 1048576) . ' MB');
        }

        return null;
    }

    private function answer(array $message): void
    {
        if (!isset($message['id'])) {
            return;
        }
        $response = ($message['method'] ?? null) === 'ping'
            ? ['result' => new \stdClass()]
            : ['error' => ['code' => -32601, 'message' => 'Method not found']];

        $this->queue(['jsonrpc' => '2.0', 'id' => $message['id']] + $response);
    }

    protected function notify(string $method, array $params = []): void
    {
        $this->queue(['jsonrpc' => '2.0', 'method' => $method] + ($params === [] ? [] : ['params' => $params]));
        $this->flush();
    }

    private function queue(array $message): void
    {
        $this->outgoing .= Json::encode($message) . "\n";
    }

    private function flush(): void
    {
        $deadline = microtime(true) + $this->timeout;
        while ($this->outgoing !== '' && $this->stdin !== null && ($left = $deadline - microtime(true)) > 0.0) {
            $this->pump(min($left, 0.1));
        }
    }

    private function pump(float $seconds): void
    {
        $read = [];
        if ($this->stdout !== null) {
            $read[] = $this->stdout;
        }
        if ($this->stderr !== null) {
            $read[] = $this->stderr;
        }
        $write = $this->stdin !== null && $this->outgoing !== '' ? [$this->stdin] : [];
        if ($read === [] && $write === []) {
            usleep((int) ($seconds * 1_000_000));

            return;
        }

        $except = null;
        if (@stream_select($read, $write, $except, 0, (int) ($seconds * 1_000_000)) === false) {
            return;
        }
        foreach ($read as $stream) {
            $chunk = fread($stream, self::CHUNK_BYTES);
            $chunk = is_string($chunk) ? $chunk : '';
            $ended = feof($stream);
            if ($stream === $this->stdout) {
                $this->incoming .= $chunk;
                if ($ended) {
                    fclose($stream);
                    $this->stdout = null;
                }
            } else {
                $this->stderrTail = substr($this->stderrTail . $chunk, -$this->stderrBytes);
                if ($ended) {
                    fclose($stream);
                    $this->stderr = null;
                }
            }
        }

        if ($write !== [] && $this->stdin !== null) {
            $written = @fwrite($this->stdin, $this->outgoing);
            if ($written === false) {
                fclose($this->stdin);
                $this->stdin = null;
                $this->outgoing = '';
            } else {
                $this->outgoing = substr($this->outgoing, $written);
            }
        }
    }

    private function running(): bool
    {
        return $this->process !== null && proc_get_status($this->process)['running'];
    }

    private function fail(string $message): PhycodeException
    {
        $this->failure = $message;
        $this->shutdown();

        return new PhycodeException($message);
    }

    private function stoppedMessage(string $during): string
    {
        $this->waitForExit(self::EXIT_GRACE_SECONDS);
        $state = 'closed its output';
        if ($this->process !== null) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $state = $status['signaled'] ? "was killed by signal {$status['termsig']}" : "exited with code {$status['exitcode']}";
            }
        }
        $this->drainStderr();
        $message = "MCP server {$this->name} {$state} {$during}";
        $excerpt = $this->excerpt();

        return $excerpt === '' ? $message : "{$message}: {$excerpt}";
    }

    private function drainStderr(): void
    {
        $deadline = microtime(true) + self::DRAIN_SECONDS;
        while ($this->stderr !== null && microtime(true) < $deadline) {
            $this->pump(0.05);
        }
    }

    private function excerpt(): string
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $this->stderrTail)),
            static fn (string $line): bool => $line !== '',
        ));

        return Redaction::text(self::plain(implode(' | ', array_slice($lines, -3)), 300));
    }

    protected function shutdown(): void
    {
        if ($this->process === null) {
            return;
        }
        if ($this->stdin !== null) {
            fclose($this->stdin);
            $this->stdin = null;
        }
        $this->outgoing = '';

        $this->waitForExit($this->shutdownSeconds);
        if ($this->running()) {
            proc_terminate($this->process);
            $this->waitForExit(self::TERMINATE_SECONDS);
        }
        if ($this->running()) {
            proc_terminate($this->process, 9);
        }
        $this->drainStderr();

        foreach ([$this->stdout, $this->stderr] as $pipe) {
            if ($pipe !== null) {
                fclose($pipe);
            }
        }
        $this->stdout = null;
        $this->stderr = null;
        proc_close($this->process);
        $this->process = null;
    }

    private function waitForExit(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while ($this->running() && ($left = $deadline - microtime(true)) > 0.0) {
            $this->pump(min($left, 0.05));
        }
    }
}
