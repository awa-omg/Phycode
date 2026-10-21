<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\SsrfGuard;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function serve(): FixtureServer
    {
        return $this->server = new FixtureServer(['/hello' => [['body' => 'hello', 'type' => 'text/plain']]]);
    }

    public function testAPercentEncodedHostIsRefusedBeforeAnyConnectionIsMade(): void
    {
        $server = $this->serve();
        $transport = new Transport(new SsrfGuard([], static fn (string $host): array => ['93.184.216.34']));

        try {
            $transport->get("http://localhost%2e:{$server->port()}/hello");
            self::fail('a host with a percent-encoded dot must be refused');
        } catch (WebException $error) {
            self::assertSame('invalid host name localhost%2e', $error->getMessage());
        }
        self::assertSame([], $server->requests(), 'curl decodes localhost%2e to localhost. and would have reached the server');
    }

    public function testANulByteInTheUrlIsAWebExceptionAndNothingIsSent(): void
    {
        $server = $this->serve();
        $transport = new Transport(new SsrfGuard(["127.0.0.1:{$server->port()}"]));

        try {
            $transport->get("http://127.0.0.1:{$server->port()}/hello\0");
            self::fail('a NUL byte must be refused');
        } catch (WebException $error) {
            self::assertSame('invalid URL: it has a space, a backslash or a control character', $error->getMessage());
        }
        self::assertSame([], $server->requests());
    }

    public function testTheRequestGoesToTheCheckedHostAndItsCheckedAddress(): void
    {
        $server = $this->serve();
        $guard = new SsrfGuard(["fixture.test.:{$server->port()}"], static fn (string $host): array => ['127.0.0.1']);

        $reply = (new Transport($guard))->get("http://fixture.test.:{$server->port()}/hello?x=1");

        self::assertSame(200, $reply->status);
        self::assertSame('hello', $reply->body);
        $request = $server->requests()[0];
        self::assertSame('/hello', $request['path']);
        self::assertSame('x=1', $request['query']);
        self::assertSame("fixture.test.:{$server->port()}", $request['headers']['host']);
    }

    public function testAnUppercaseNameIsConnectedToItsCheckedAddress(): void
    {
        $server = $this->serve();
        $guard = new SsrfGuard(["fixture.test:{$server->port()}"], static fn (string $host): array => ['127.0.0.1']);

        self::assertSame('hello', (new Transport($guard))->get("http://FIXTURE.test:{$server->port()}/hello")->body);
    }

    public function testAnInternationalNameIsConnectedToItsCheckedAddressInTheAsciiForm(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('the intl extension converts a name that is not ASCII');
        }
        $server = $this->serve();
        $guard = new SsrfGuard(
            ["xn--bcher-kva.example:{$server->port()}"],
            static fn (string $host): array => $host === 'xn--bcher-kva.example' ? ['127.0.0.1'] : [],
        );

        self::assertSame('hello', (new Transport($guard))->get("http://bücher.example:{$server->port()}/hello")->body);
        self::assertSame("xn--bcher-kva.example:{$server->port()}", $server->requests()[0]['headers']['host']);
    }
}
