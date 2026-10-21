<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

final class FakeModel
{
    private $process;
    private readonly string $folder;
    private readonly int $port;

    public function __construct(array $replies)
    {
        $this->folder = Kit::folder('fake-model');
        file_put_contents($this->folder . '/replies.json', json_encode($replies, JSON_THROW_ON_ERROR));

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if ($probe === false) {
            throw new \RuntimeException("no free port for the fake model: {$message}");
        }
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $this->port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", __DIR__ . '/fake-model.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->folder . '/access.log', 'w'], 2 => ['file', $this->folder . '/server.log', 'w']],
            $pipes,
            $this->folder,
            ['PATH' => (string) getenv('PATH'), 'PHYCODE_FAKE_MODEL' => $this->folder],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start the fake model');
        }
        $this->process = $process;
        $this->waitUntilListening();
    }

    public function url(): string
    {
        return "http://127.0.0.1:{$this->port}";
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

        throw new \RuntimeException('the fake model does not listen on port ' . $this->port);
    }
}
