<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Exec;

use Phycode\Exec\DomainAllowlist;
use Phycode\Exec\FilteringProxy;
use Phycode\Support\PhycodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainAllowlistTest extends TestCase
{
    public static function checks(): iterable
    {
        yield 'a name on the default ports' => [['github.com'], 'github.com', 443, true];
        yield 'port 80 too' => [['github.com'], 'github.com', 80, true];
        yield 'another port' => [['github.com'], 'github.com', 22, false];
        yield 'the port of the entry' => [['registry.local:8443'], 'registry.local', 8443, true];
        yield 'not the default ports then' => [['registry.local:8443'], 'registry.local', 443, false];
        yield 'a subdomain of a plain name' => [['github.com'], 'api.github.com', 443, false];
        yield 'a wildcard matches a subdomain' => [['*.npmjs.org'], 'registry.npmjs.org', 443, true];
        yield 'a wildcard matches any depth' => [['*.npmjs.org'], 'a.b.npmjs.org', 443, true];
        yield 'a wildcard does not match the name itself' => [['*.npmjs.org'], 'npmjs.org', 443, false];
        yield 'a wildcard does not match a look-alike' => [['*.npmjs.org'], 'evilnpmjs.org', 443, false];
        yield 'a name that ends like the entry' => [['github.com'], 'notgithub.com', 443, false];
        yield 'capitals and a dot at the end' => [['GitHub.com'], 'GITHUB.COM.', 443, true];
        yield 'nothing is allowed by an empty list' => [[], 'github.com', 443, false];
        yield 'one of several' => [['a.example.com', 'b.example.com'], 'b.example.com', 80, true];
        yield 'localhost by name' => [['localhost:8080'], 'localhost', 8080, true];
    }

    #[DataProvider('checks')]
    public function testAHostAndAPortAreAllowedOnlyWhenAnEntryNamesThem(array $entries, string $host, int $port, bool $expected): void
    {
        self::assertSame($expected, (new DomainAllowlist($entries))->allows($host, $port));
    }

    public static function refused(): iterable
    {
        yield 'a star' => ['*', 'is not a host name'];
        yield 'a star in the middle' => ['a.*.com', 'is not a host name'];
        yield 'an address' => ['93.184.216.34', 'is an address; name the host instead'];
        yield 'a name with a space' => ['git hub.com', 'is not a host name'];
        yield 'a name without a dot' => ['intranet', 'is not a host name'];
        yield 'a url' => ['https://github.com', 'is not a host name'];
        yield 'a port out of range' => ['github.com:99999', 'is not from 1 to 65535'];
        yield 'port zero' => ['github.com:0', 'is not from 1 to 65535'];
        yield 'an empty entry' => ['', 'is not a host name'];
    }

    #[DataProvider('refused')]
    public function testAnEntryThatIsNotAHostNameIsRefusedWhenTheListIsRead(string $entry, string $message): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage($message);

        new DomainAllowlist([$entry]);
    }

    public function testAddressesOnThisMachineAndOnPrivateNetworksAreInside(): void
    {
        foreach (['127.0.0.1', '127.8.8.8', '10.0.0.5', '172.16.9.9', '192.168.1.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '100.127.255.254', '240.0.0.1'] as $address) {
            self::assertTrue(FilteringProxy::inside($address), $address);
        }
        foreach (['93.184.216.34', '8.8.8.8', '100.63.255.255', '100.128.0.1', '172.32.0.1'] as $address) {
            self::assertFalse(FilteringProxy::inside($address), $address);
        }
    }
}
