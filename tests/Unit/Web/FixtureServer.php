<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Tests\Support\Kit;

final class FixtureServer
{
    private $process;
    private readonly string $folder;
    private readonly int $port;

    public function __construct(array $routes)
    {
        $this->folder = Kit::folder('web');
        file_put_contents($this->folder . '/routes.json', json_encode($routes, JSON_THROW_ON_ERROR));

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if ($probe === false) {
            throw new \RuntimeException("no free port for the fixture server: {$message}");
        }
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $this->port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", __DIR__ . '/fixture-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->folder . '/access.log', 'w'], 2 => ['file', $this->folder . '/server.log', 'w']],
            $pipes,
            $this->folder,
            ['PATH' => (string) getenv('PATH'), 'PHYCODE_WEB_FIXTURES' => $this->folder],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start the fixture server');
        }
        $this->process = $process;
        try {
            $this->waitUntilListening();
        } catch (\RuntimeException $error) {
            $this->stop();

            throw $error;
        }
    }

    public function port(): int
    {
        return $this->port;
    }

    public function url(string $path = '/'): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    public function requests(): array
    {
        $file = $this->folder . '/requests.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $requests = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($file))) as $line) {
            $requests[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }

        return $requests;
    }

    public static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/web/' . $name);
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

        throw new \RuntimeException('the fixture server does not listen on port ' . $this->port);
    }
}
