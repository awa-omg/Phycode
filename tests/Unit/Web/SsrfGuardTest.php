<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\SsrfGuard;
use Phycode\Web\WebException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SsrfGuardTest extends TestCase
{
    public static function refusedUrls(): iterable
    {
        yield 'IPv4 loopback' => ['http://127.0.0.1/'];
        yield 'private 10/8' => ['http://10.0.0.1/'];
        yield 'cloud metadata service' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'IPv6 loopback' => ['http://[::1]/'];
        yield 'IPv6 unique local' => ['http://[fc00::1]/'];
        yield 'unspecified' => ['http://0.0.0.0/'];
        yield 'unspecified IPv6' => ['http://[::]/'];
        yield 'IPv6 link-local' => ['http://[fe80::1]/'];
        yield 'multicast' => ['http://224.0.0.1/'];
        yield 'IPv6 multicast' => ['http://[ff02::1]/'];
        yield 'broadcast' => ['http://255.255.255.255/'];
        yield 'shared address space' => ['http://100.64.0.1/'];
        yield 'documentation range' => ['http://192.0.2.1/'];
        yield 'IPv6 documentation range' => ['http://[2001:db8::1]/'];
        yield 'benchmarking range' => ['http://198.18.0.1/'];
        yield 'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'];
        yield 'IPv4-mapped private' => ['http://[::ffff:10.0.0.1]/'];
        yield 'NAT64 to loopback' => ['http://[64:ff9b::7f00:1]/'];
        yield 'NAT64 to metadata' => ['http://[64:ff9b::a9fe:a9fe]/'];
        yield 'IPv4-compatible loopback' => ['http://[::127.0.0.1]/'];
        yield '6to4 to a private address' => ['http://[2002:7f00:1::1]/'];
        yield 'Teredo' => ['http://[2001::1]/'];
        yield 'discard prefix' => ['http://[100::1]/'];
    }

    #[DataProvider('refusedUrls')]
    public function testAddressesThatAreNotPublicAreRefused(string $url): void
    {
        $this->expectException(WebException::class);

        (new SsrfGuard())->check($url);
    }

    public static function publicUrls(): iterable
    {
        yield 'public IPv4' => ['http://8.8.8.8/', '8.8.8.8'];
        yield 'just above 172.16/12' => ['http://172.32.0.1/', '172.32.0.1'];
        yield 'just above 100.64/10' => ['http://100.128.0.1/', '100.128.0.1'];
        yield 'just above 198.18/15' => ['http://198.20.0.1/', '198.20.0.1'];
        yield 'public IPv6' => ['http://[2606:4700:4700::1111]/', '2606:4700:4700::1111'];
        yield 'public IPv6 in another block' => ['http://[2001:4860:4860::8888]/', '2001:4860:4860::8888'];
        yield 'IPv4-mapped public' => ['http://[::ffff:8.8.8.8]/', '::ffff:8.8.8.8'];
    }

    #[DataProvider('publicUrls')]
    public function testPublicAddressesAreAllowed(string $url, string $address): void
    {
        $target = (new SsrfGuard())->check($url);

        self::assertSame([$address], $target['addresses']);
    }

    public function testTheRefusalNamesTheAddressAndItsKind(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('refused: 169.254.169.254 is a link-local address');

        (new SsrfGuard())->check('http://169.254.169.254/latest/meta-data/');
    }

    public function testALoopbackLiteralIsRefusedWithItsKind(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('refused: 127.0.0.1 is a loopback address');

        (new SsrfGuard())->check('http://127.0.0.1:8080/admin');
    }

    public function testAnAllowlistedLoopbackPortIsReachable(): void
    {
        $target = (new SsrfGuard(['127.0.0.1:8123']))->check('http://127.0.0.1:8123/page');

        self::assertSame(['url' => 'http://127.0.0.1:8123/page', 'host' => '127.0.0.1', 'port' => 8123, 'addresses' => ['127.0.0.1']], $target);
    }

    public function testAnAllowlistedPairDoesNotOpenOtherPortsOfTheSameHost(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('refused: 127.0.0.1 is a loopback address');

        (new SsrfGuard(['127.0.0.1:8123']))->check('http://127.0.0.1:8124/page');
    }

    public function testAnAllowlistedPairIsMatchedByHostNameToo(): void
    {
        $guard = new SsrfGuard(['internal.test:8080'], static fn (string $host): array => ['10.1.2.3']);

        self::assertSame(['10.1.2.3'], $guard->check('http://internal.test:8080/')['addresses']);
    }

    public function testAnAllowlistedAddressDoesNotCoverAHostNameThatResolvesToIt(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('refused: localhost resolves to 127.0.0.1, which is a loopback address');

        (new SsrfGuard(['127.0.0.1:8123']))->check('http://localhost:8123/');
    }

    public function testAnAllowlistedIpv6PairUsesTheBrackets(): void
    {
        $target = (new SsrfGuard(['[::1]:8080']))->check('http://[::1]:8080/');

        self::assertSame(['url' => 'http://[::1]:8080/', 'host' => '::1', 'port' => 8080, 'addresses' => ['::1']], $target);
    }

    public function testAnAllowlistEntryWithoutAPortIsRejected(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('allowlist entry localhost is not host:port');

        new SsrfGuard(['localhost']);
    }

    public function testEveryAddressOfAHostNameIsChecked(): void
    {
        $guard = new SsrfGuard([], static fn (string $host): array => ['93.184.216.34', '10.0.0.7']);

        $this->expectException(WebException::class);
        $this->expectExceptionMessage('refused: files.example.test resolves to 10.0.0.7, which is a private address');

        $guard->check('https://files.example.test/report.pdf');
    }

    public function testAHostNameWithOnlyPublicAddressesIsAllowedAndItsAddressesAreReturned(): void
    {
        $guard = new SsrfGuard([], static fn (string $host): array => ['93.184.216.34', '2606:4700:4700::1111']);

        $target = $guard->check('https://docs.example.test/start');

        self::assertSame(
            ['url' => 'https://docs.example.test/start', 'host' => 'docs.example.test', 'port' => 443, 'addresses' => ['93.184.216.34', '2606:4700:4700::1111']],
            $target,
        );
    }

    public function testAHostNameThatDoesNotResolveIsRefused(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('cannot resolve nothing.example.test');

        (new SsrfGuard([], static fn (string $host): array => []))->check('http://nothing.example.test/');
    }

    public function testTheSystemResolverFindsTheLoopbackOfLocalhost(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('refused: localhost resolves to 127.0.0.1, which is a loopback address');

        (new SsrfGuard())->check('http://localhost/');
    }

    public static function otherSchemes(): iterable
    {
        yield 'file' => ['file:///etc/passwd', 'file URLs are refused; only http and https are allowed'];
        yield 'gopher' => ['gopher://93.184.216.34/', 'gopher URLs are refused; only http and https are allowed'];
        yield 'ftp' => ['ftp://93.184.216.34/', 'ftp URLs are refused; only http and https are allowed'];
        yield 'javascript' => ['javascript:alert(1)', 'javascript URLs are refused; only http and https are allowed'];
    }

    #[DataProvider('otherSchemes')]
    public function testOnlyHttpAndHttpsAreAllowed(string $url, string $message): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage($message);

        (new SsrfGuard())->check($url);
    }

    public function testAUrlWithCredentialsIsRefusedAndTheCredentialsAreNotEchoed(): void
    {
        try {
            (new SsrfGuard())->check('http://user:hunter2x@93.184.216.34/');
            self::fail('a URL with credentials must be refused');
        } catch (WebException $error) {
            self::assertSame('URLs with credentials are refused', $error->getMessage());
        }
    }

    public function testAUrlWithoutAHostIsInvalid(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('invalid URL');

        (new SsrfGuard())->check('http:///path-only');
    }

    public static function hostsThatAreNotNames(): iterable
    {
        yield 'a percent-encoded dot' => ['http://localhost%2ename:8080/x', 'invalid host name localhost%2ename'];
        yield 'an upper-case escape' => ['http://localhost%2Ename/', 'invalid host name localhost%2Ename'];
        yield 'an encoded address' => ['http://%31%32%37.0.0.1/', 'invalid host name %31%32%37.0.0.1'];
        yield 'an encoded name with a trailing dot' => ['https://docs.example.test%2e/', 'invalid host name docs.example.test%2e'];
        yield 'an octal address' => ['http://0177.0.0.1/', 'invalid IPv4 address 0177.0.0.1'];
        yield 'a decimal address' => ['http://2130706433/', 'invalid IPv4 address 2130706433'];
        yield 'a short address' => ['http://127.1/', 'invalid IPv4 address 127.1'];
        yield 'a hexadecimal address' => ['http://0x7f.1/', 'invalid IPv4 address 0x7f.1'];
        yield 'an address with a trailing dot' => ['http://8.8.8.8./', 'invalid IPv4 address 8.8.8.8.'];
        yield 'an IPv6 zone' => ['http://[fe80::1%25eth0]/', 'invalid IPv6 address [fe80::1%25eth0]'];
        yield 'an IPv6 address without its closing bracket' => ['http://[::1/', 'invalid IPv6 address [:'];
        yield 'an IPv6 address without brackets' => ['http://::1/', 'invalid IPv6 address :'];
        yield 'an empty label' => ['https://docs..example.test/', 'invalid host name docs..example.test'];
        yield 'a bracket in a name' => ['https://docs.example.test]/', 'invalid host name docs.example.test]'];
    }

    #[DataProvider('hostsThatAreNotNames')]
    public function testAHostThatIsNotAnAddressOrADnsNameIsRefusedEvenWhenItResolvesToAPublicAddress(string $url, string $message): void
    {
        $guard = new SsrfGuard([], static fn (string $host): array => ['93.184.216.34']);

        $this->expectException(WebException::class);
        $this->expectExceptionMessage($message);

        $guard->check($url);
    }

    public static function urlsWithSpacesBackslashesOrControlCharacters(): iterable
    {
        yield 'a space' => ['http://docs.example.test/a b'];
        yield 'a backslash that would end the host' => ['http://docs.example.test\\.evil.test/'];
        yield 'a tab' => ["http://docs.example.test/a\tb"];
        yield 'a line feed' => ["http://docs.example.test/\n"];
        yield 'a NUL byte' => ["http://docs.example.test/\0x"];
    }

    #[DataProvider('urlsWithSpacesBackslashesOrControlCharacters')]
    public function testAUrlWithASpaceABackslashOrAControlCharacterIsInvalid(string $url): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('invalid URL: it has a space, a backslash or a control character');

        (new SsrfGuard([], static fn (string $host): array => ['93.184.216.34']))->check($url);
    }

    public static function validNames(): iterable
    {
        yield 'upper case' => ['https://Docs.Example.TEST/', 'Docs.Example.TEST'];
        yield 'a trailing dot' => ['https://pub.example./', 'pub.example.'];
        yield 'an underscore' => ['https://my_service.example/', 'my_service.example'];
        yield 'hyphens' => ['https://a-b.c-d.example/', 'a-b.c-d.example'];
    }

    #[DataProvider('validNames')]
    public function testAValidDnsNameIsCheckedAsItIsWritten(string $url, string $host): void
    {
        $target = (new SsrfGuard([], static fn (string $name): array => ['93.184.216.34']))->check($url);

        self::assertSame($host, $target['host']);
        self::assertSame(['93.184.216.34'], $target['addresses']);
    }

    public function testAnInternationalNameIsCheckedAndRequestedInItsAsciiForm(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('the intl extension converts a name that is not ASCII');
        }
        $guard = new SsrfGuard([], static fn (string $host): array => $host === 'xn--bcher-kva.example' ? ['93.184.216.34'] : []);

        self::assertSame(
            ['url' => 'https://xn--bcher-kva.example/', 'host' => 'xn--bcher-kva.example', 'port' => 443, 'addresses' => ['93.184.216.34']],
            $guard->check('https://bücher.example/'),
        );
    }

    public function testTheUrlThatIsReturnedIsBuiltFromTheCheckedParts(): void
    {
        $guard = new SsrfGuard([], static fn (string $host): array => ['93.184.216.34']);

        self::assertSame('https://docs.example.test/a/b?x=1&y=%2F', $guard->check('https://docs.example.test/a/b?x=1&y=%2F#part')['url']);
        self::assertSame('https://docs.example.test:8443/', $guard->check('https://docs.example.test:8443/')['url']);
        self::assertSame('https://docs.example.test?q=1', $guard->check('https://docs.example.test?q=1')['url']);
    }

    public function testAnAllowlistedNameWithAnUnderscoreIsReached(): void
    {
        $guard = new SsrfGuard(['searx_app:8888'], static fn (string $host): array => ['172.18.0.5']);

        self::assertSame(['172.18.0.5'], $guard->check('http://searx_app:8888/search')['addresses']);
    }
}
