<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

use Phycode\Config\Config;
use Phycode\Config\Profile;
use Phycode\Support\Json;

final class ProtocolServer
{
    private $process;
    private readonly string $folder;
    private readonly int $port;

    public function __construct(array $scenarios)
    {
        $this->folder = Kit::folder('protocol-server');
        file_put_contents($this->folder . '/scenarios.json', json_encode($scenarios, JSON_THROW_ON_ERROR));

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if ($probe === false) {
            throw new \RuntimeException("no free port for the loopback server: {$message}");
        }
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $this->port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", __DIR__ . '/protocol-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->folder . '/access.log', 'w'], 2 => ['file', $this->folder . '/server.log', 'w']],
            $pipes,
            $this->folder,
            ['PATH' => (string) getenv('PATH'), 'PHYCODE_PROTOCOL_LOOPBACK' => $this->folder],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start the loopback server');
        }
        $this->process = $process;
        $this->waitUntilListening();
    }

    public function url(string $scenario): string
    {
        return "http://127.0.0.1:{$this->port}/{$scenario}";
    }

    public function profile(string $scenario, array $fields = []): Profile
    {
        $raw = array_merge(['base_url' => $this->url($scenario), 'model' => 'test-model'], $fields);

        return Config::fromArrays(['profiles' => ['test' => $raw]], null, [], '/nonexistent')->profile('test');
    }

    public function requests(?string $method = null): array
    {
        $file = $this->folder . '/requests.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $requests = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($file))) as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($method !== null && $entry['method'] !== $method) {
                continue;
            }
            $entry['json'] = Json::object((string) $entry['body']) ?? [];
            $requests[] = $entry;
        }

        return $requests;
    }

    public static function fixture(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/Fixtures/' . $path);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $message, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(20000);
        }

        throw new \RuntimeException('the loopback server does not listen on port ' . $this->port);
    }
}
