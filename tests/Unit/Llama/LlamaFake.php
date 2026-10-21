<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llama;

use Phycode\Config\Config;
use Phycode\Config\Profile;
use Phycode\Tests\Support\Kit;

final class LlamaFake
{
    private $process;
    private readonly string $folder;
    private readonly string $address;
    private readonly string $baseUrl;

    public function __construct(array $routes, bool $socket = false, ?string $apiKey = null)
    {
        $this->folder = Kit::folder('llama-fake');
        file_put_contents($this->folder . '/routes.json', json_encode($routes, JSON_THROW_ON_ERROR));
        if ($apiKey !== null) {
            file_put_contents($this->folder . '/api-key', $apiKey);
        }
        if ($socket) {
            $this->address = 'unix://' . $this->folder . '/llama.sock';
            $this->baseUrl = $this->address;
        } else {
            $port = self::freePort();
            $this->address = "tcp://127.0.0.1:{$port}";
            $this->baseUrl = "http://127.0.0.1:{$port}";
        }

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fake-llama.php', $this->address, $this->folder],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->folder . '/out.log', 'w'], 2 => ['file', $this->folder . '/err.log', 'w']],
            $pipes,
            $this->folder,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start the fake llama-server');
        }
        $this->process = $process;
        $this->waitUntilListening();
    }

    public function url(): string
    {
        return $this->baseUrl;
    }

    public function profile(array $fields = [], string $model = 'qwen35-2b'): Profile
    {
        $raw = array_merge(['base_url' => $this->baseUrl, 'model' => $model], $fields);

        return Config::fromArrays(['profiles' => ['test' => $raw]], null, [], '/nonexistent')->profile('test');
    }

    public function requests(): array
    {
        $file = $this->folder . '/requests.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $requests = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($file))) as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $path = (string) parse_url((string) $entry['target'], PHP_URL_PATH);
            $query = [];
            parse_str((string) parse_url((string) $entry['target'], PHP_URL_QUERY), $query);
            $json = json_decode((string) $entry['body'], true);
            $requests[] = [
                'method' => (string) $entry['method'],
                'target' => (string) $entry['target'],
                'path' => $path,
                'query' => array_map('strval', $query),
                'host' => $entry['host'],
                'authorization' => $entry['authorization'],
                'body' => (string) $entry['body'],
                'json' => is_array($json) ? $json : [],
            ];
        }

        return $requests;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }

    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if ($probe === false) {
            throw new \RuntimeException("no free port for the fake llama-server: {$message}");
        }
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            if (is_file($this->folder . '/ready')) {
                return;
            }
            usleep(20000);
        }

        throw new \RuntimeException('the fake llama-server did not start: ' . (string) file_get_contents($this->folder . '/err.log'));
    }
}
