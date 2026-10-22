<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Browser;

use Phycode\Browser\Cdp;
use Phycode\Browser\WebSocket;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class WebSocketHandshakeTest extends TestCase
{
    private ?FakeBrowser $fake = null;
    private $process = null;

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, SIGKILL);
            proc_close($this->process);
        }
        $this->fake?->stop();
    }

    private function serve(array $scenario = []): string
    {
        $this->fake = new FakeBrowser($scenario);
        $profile = Kit::folder('handshake-profile');
        $this->process = proc_open([$this->fake->binary, '--user-data-dir=' . $profile], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $deadline = microtime(true) + 20.0;
        while (!is_file($profile . '/DevToolsActivePort') && microtime(true) < $deadline) {
            usleep(20000);
        }
        [$port, $path] = explode("\n", trim((string) file_get_contents($profile . '/DevToolsActivePort')));

        return "ws://127.0.0.1:{$port}{$path}";
    }

    public function testTheHandshakeWithAServerThatChecksTheKeySucceedsAndCommandsFlow(): void
    {
        $url = $this->serve();

        $cdp = new Cdp(WebSocket::connect($url, 2.0));

        self::assertSame([], $cdp->call('Browser.setDownloadBehavior', ['behavior' => 'deny']));
        $cdp->close();
        $request = $this->fake->http()[0];
        self::assertMatchesRegularExpression('~^/devtools/browser/[0-9a-f-]+$~', $request['target']);
        self::assertSame('websocket', $request['upgrade']);
        self::assertMatchesRegularExpression('~^127\.0\.0\.1:[0-9]+$~', $request['host']);
        self::assertFileDoesNotExist($this->fake->folder . '/protocol.jsonl', 'every frame the client sent was masked');
    }

    public function testAWrongAcceptKeyIsNotABrowser(): void
    {
        $url = $this->serve(['handshake' => 'bad-accept']);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('Sec-WebSocket-Accept');

        WebSocket::connect($url, 2.0);
    }

    public function testARefusalIsReportedWithItsStatusLine(): void
    {
        $url = $this->serve(['handshake' => 'forbidden']);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('the browser refused the WebSocket handshake: HTTP/1.1 403 Forbidden');

        WebSocket::connect($url, 2.0);
    }

    public function testAnotherPathIsNotFound(): void
    {
        $url = $this->serve();

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('HTTP/1.1 404 Not Found');

        WebSocket::connect(substr($url, 0, (int) strpos($url, '/devtools')) . '/devtools/page/other', 2.0);
    }

    public function testAServerThatNeverAnswersTheHandshakeTimesOut(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($server, false);
        $started = microtime(true);

        try {
            WebSocket::connect('ws://' . $name . '/devtools/browser/x', 0.3);
            self::fail('there was no answer');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('did not answer the WebSocket handshake within 0.3s', $error->getMessage());
        } finally {
            fclose($server);
        }
        self::assertLessThan(3.0, microtime(true) - $started);
    }
}
