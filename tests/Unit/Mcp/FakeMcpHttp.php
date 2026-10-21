<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Mcp;

use Phycode\Tests\Support\Kit;

final class FakeMcpHttp
{
    private $process;
    private readonly string $folder;
    private readonly int $port;

    public function __construct(array $scenario = [])
    {
        $this->folder = Kit::folder('fake-mcp-http');
        file_put_contents($this->folder . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR));
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if ($probe === false) {
            throw new \RuntimeException("no free port: {$message}");
        }
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $this->port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", dirname(__DIR__, 2) . '/Support/fake-mcp-http.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->folder . '/access.log', 'w'], 2 => ['file', $this->folder . '/server.log', 'w']],
            $pipes,
            $this->folder,
            ['PATH' => (string) getenv('PATH'), 'PHYCODE_FAKE_MCP_HTTP' => $this->folder],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start the fake MCP server');
        }
        $this->process = $process;
        for ($waited = 0; $waited < 100; $waited++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $message, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException('the fake MCP server does not listen');
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function url(string $path = '/mcp'): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    public function requests(): array
    {
        $file = $this->folder . '/requests.jsonl';

        return is_file($file) ? array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) file_get_contents($file))))) : [];
    }

    public function methods(): array
    {
        return array_values(array_map(static fn (array $request): string => $request['method'] === 'POST' ? (string) (json_decode($request['body'], true)['method'] ?? '?') : $request['method'], $this->requests()));
    }

    public function lines(string $file): array
    {
        $path = $this->folder . '/' . $file;

        return is_file($path) ? array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) file_get_contents($path))))) : [];
    }

    public function clients(): array
    {
        $path = $this->folder . '/clients.json';

        return is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
    }

    public function revokeAccessTokens(): void
    {
        $path = $this->folder . '/tokens.json';
        $tokens = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
        foreach ($tokens as $name => $token) {
            $tokens[$name]['revoked'] = true;
        }
        file_put_contents($path, json_encode($tokens));
    }

    public function deletedSession(): ?string
    {
        $file = $this->folder . '/deleted';

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
