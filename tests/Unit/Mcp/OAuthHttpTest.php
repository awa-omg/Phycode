<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Mcp;

use Phycode\Mcp\OAuthHttp;
use Phycode\Support\PhycodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OAuthHttpTest extends TestCase
{
    private ?FakeMcpHttp $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public static function addressesThatAreNotPublic(): iterable
    {
        yield 'a private address' => ['https://10.255.255.1/', 'refused: 10.255.255.1 is a private address'];
        yield 'the metadata service of a cloud' => ['https://169.254.169.254/latest/meta-data/', 'refused: 169.254.169.254 is a link-local address'];
        yield 'a private IPv6 address' => ['https://[fd00::1]/token', 'refused: fd00::1 is a private address'];
        yield 'the loopback over https' => ['https://127.0.0.1:9/token', 'refused: 127.0.0.1 is a loopback address'];
        yield 'a name that hides a dot' => ['https://localhost%2e.example/token', 'invalid host name localhost%2e.example'];
    }

    #[DataProvider('addressesThatAreNotPublic')]
    public function testAnAddressThatIsNotPublicIsRefusedBeforeAConnectionIsMade(string $url, string $message): void
    {
        $started = microtime(true);

        try {
            OAuthHttp::request('POST', $url, ['Content-Type' => 'application/json'], '{}');
            self::fail('the address must be refused');
        } catch (PhycodeException $error) {
            self::assertSame($message, $error->getMessage());
        }
        self::assertLessThan(5.0, microtime(true) - $started, 'a connection would have waited for the address');
    }

    public function testPlainHttpToThisMachineIsStillReached(): void
    {
        $this->server = new FakeMcpHttp();

        self::assertSame(405, OAuthHttp::request('GET', $this->server->url('/mcp'))['status']);
        self::assertSame(405, OAuthHttp::request('GET', str_replace('127.0.0.1', 'localhost', $this->server->url('/mcp')))['status']);
    }
}
