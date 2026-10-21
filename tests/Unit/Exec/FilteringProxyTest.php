<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Exec;

use Phycode\Exec\ProxyProcess;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class FilteringProxyTest extends TestCase
{
    private $web = null;
    private int $port = 0;
    private ?ProxyProcess $proxy = null;

    protected function setUp(): void
    {
        $docroot = Kit::folder('proxy-web');
        file_put_contents($docroot . '/hello.txt', "hello from upstream\n");
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) stream_socket_get_name($probe, false), (int) strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);
        $this->web = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", '-t', $docroot], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($waited = 0; $waited < 100; $waited++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $message, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
        self::fail('the web server does not listen');
    }

    protected function tearDown(): void
    {
        $this->proxy?->stop();
        if (is_resource($this->web)) {
            proc_terminate($this->web);
            proc_close($this->web);
        }
    }

    private function proxy(array $allow, bool $private = true): ProxyProcess
    {
        return $this->proxy = new ProxyProcess($allow, $private);
    }

    private function ask(ProxyProcess $proxy, string $request, ?string $afterHead = null): string
    {
        $socket = stream_socket_client('unix://' . $proxy->socket(), $errno, $message, 5);
        fwrite($socket, $request);
        stream_set_timeout($socket, 5);
        $answer = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($socket)['timed_out']) {
                    break;
                }
                continue;
            }
            $answer .= $chunk;
            if ($afterHead !== null && str_contains($answer, "\r\n\r\n") && !str_contains($answer, 'hello from') && $afterHead !== '') {
                fwrite($socket, $afterHead);
                $afterHead = '';
            }
        }
        fclose($socket);

        return $answer;
    }

    public function testAConnectToAnAllowedHostIsATunnelToTheRealServer(): void
    {
        $proxy = $this->proxy(["localhost:{$this->port}"]);

        $answer = $this->ask($proxy, "CONNECT localhost:{$this->port} HTTP/1.1\r\nHost: localhost:{$this->port}\r\n\r\n", "GET /hello.txt HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

        self::assertStringStartsWith("HTTP/1.1 200 Connection Established\r\n\r\n", $answer);
        self::assertStringContainsString('hello from upstream', $answer);
        self::assertStringContainsString("allowed localhost:{$this->port}", (string) file_get_contents($proxy->log()));
    }

    public function testAPlainHttpRequestToAnAllowedHostIsForwardedAsOneRequest(): void
    {
        $proxy = $this->proxy(["localhost:{$this->port}"]);

        $answer = $this->ask($proxy, "GET http://localhost:{$this->port}/hello.txt HTTP/1.1\r\nHost: localhost:{$this->port}\r\nProxy-Connection: keep-alive\r\nConnection: keep-alive\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 200 OK', $answer);
        self::assertStringContainsString('hello from upstream', $answer);
    }

    public function testAHostThatIsNotInTheListIsRefusedWithTheReasonAndNothingIsConnected(): void
    {
        $proxy = $this->proxy(['github.com']);

        $answer = $this->ask($proxy, "CONNECT localhost:{$this->port} HTTP/1.1\r\nHost: x\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 403 Forbidden', $answer);
        self::assertStringContainsString("localhost:{$this->port} is not in sandbox.allow_domains", $answer);
        self::assertStringContainsString('denied localhost:' . $this->port, (string) file_get_contents($proxy->log()));
    }

    public function testAnAllowedNameThatPointsToThisMachineIsRefusedUnlessTheTestHookIsOn(): void
    {
        $proxy = $this->proxy(["localhost:{$this->port}"], false);

        $answer = $this->ask($proxy, "CONNECT localhost:{$this->port} HTTP/1.1\r\nHost: x\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 403 Forbidden', $answer);
        self::assertStringContainsString('points to 127.0.0.1, an address on this machine or on a private network', $answer);
    }

    public function testAnAddressInsteadOfANameIsRefusedEvenWhenTheHostIsInTheList(): void
    {
        $proxy = $this->proxy(["localhost:{$this->port}"]);

        $byAddress = $this->ask($proxy, "CONNECT 127.0.0.1:{$this->port} HTTP/1.1\r\nHost: x\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 403 Forbidden', $byAddress);
        self::assertStringContainsString('is not in sandbox.allow_domains', $byAddress);
    }

    public function testAHostThatDoesNotResolveIsABadGatewayAndOtherRequestsAreRefused(): void
    {
        $proxy = $this->proxy(['no-such-host.invalid']);

        self::assertStringStartsWith('HTTP/1.1 502 Bad Gateway', $this->ask($proxy, "CONNECT no-such-host.invalid:443 HTTP/1.1\r\nHost: x\r\n\r\n"));
        self::assertStringStartsWith('HTTP/1.1 405 Method Not Allowed', $this->ask($proxy, "GET /hello.txt HTTP/1.1\r\nHost: x\r\n\r\n"));
        self::assertStringStartsWith('HTTP/1.1 405 Method Not Allowed', $this->ask($proxy, "POST https://example.com/ HTTP/1.1\r\nHost: x\r\n\r\n"));
        self::assertStringStartsWith('HTTP/1.1 431', $this->ask($proxy, 'GET http://localhost/ HTTP/1.1' . "\r\n" . str_repeat('X-Pad: ' . str_repeat('a', 1000) . "\r\n", 20)));
    }

    public function testTheProxyKeepsServingAfterABadConnectionAndEndsWithItsProcessObject(): void
    {
        $proxy = $this->proxy(["localhost:{$this->port}"]);
        $socket = stream_socket_client('unix://' . $proxy->socket());
        fwrite($socket, "garbage without an end");
        fclose($socket);

        $answer = $this->ask($proxy, "GET http://localhost:{$this->port}/hello.txt HTTP/1.1\r\nHost: x\r\n\r\n");

        self::assertStringContainsString('hello from upstream', $answer);
        $socketPath = $proxy->socket();
        $proxy->stop();
        $this->proxy = null;
        self::assertFileDoesNotExist($socketPath);
    }
}
