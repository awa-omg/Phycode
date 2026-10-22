<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Browser;

use Phycode\Browser\BrowserPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrowserPolicyTest extends TestCase
{
    private int $lookups = 0;

    private function policy(array $allow = ['localhost', '127.0.0.1']): BrowserPolicy
    {
        return new BrowserPolicy($allow, function (string $host): array {
            $this->lookups++;

            return match ($host) {
                'localhost' => ['127.0.0.1', '::1'],
                'app.test' => ['10.0.0.8'],
                'metadata.test' => ['169.254.169.254'],
                'mapped.test' => ['::ffff:169.254.169.254'],
                'nat64.test' => ['64:ff9b::a9fe:a9fe'],
                'v6link.test' => ['fe80::1'],
                'rebind.test' => ['93.184.216.34', '127.0.0.1'],
                'gone.test' => [],
                default => ['93.184.216.34'],
            };
        });
    }

    public static function allowedUrls(): iterable
    {
        yield 'a public name' => ['https://docs.example.test/a?b=1#c'];
        yield 'a public address' => ['http://93.184.216.34:8080/'];
        yield 'localhost on any port' => ['http://localhost:5173/'];
        yield 'the loopback address' => ['http://127.0.0.1:3000/x'];
        yield 'the loopback address in capitals of a name' => ['http://LOCALHOST/'];
    }

    #[DataProvider('allowedUrls')]
    public function testItAllowsPublicAddressesAndTheListedHosts(string $url): void
    {
        self::assertNull($this->policy()->refusal($url));
    }

    public static function refusedUrls(): iterable
    {
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data/', 'a link-local address'];
        yield 'metadata over a name' => ['http://metadata.test/', 'link-local address'];
        yield 'metadata in IPv6 form' => ['http://[::ffff:169.254.169.254]/', 'link-local'];
        yield 'metadata behind a name in IPv6 form' => ['http://mapped.test/', 'link-local'];
        yield 'metadata behind NAT64' => ['http://nat64.test/', 'link-local'];
        yield 'IPv6 link-local' => ['http://[fe80::1]/', 'link-local'];
        yield 'IPv6 link-local behind a name' => ['http://v6link.test/', 'link-local'];
        yield 'a private address' => ['http://10.0.0.5/', 'private'];
        yield 'a name with a private address' => ['http://app.test/', 'private'];
        yield 'a private address of 172' => ['http://172.16.0.1/', 'private'];
        yield 'a private address of 192' => ['http://192.168.0.1/', 'private'];
        yield 'the unspecified address' => ['http://0.0.0.0:3000/', 'unspecified'];
        yield 'IPv6 loopback, which is not listed' => ['http://[::1]:3000/', 'loopback'];
        yield 'a name that also points at the loopback' => ['http://rebind.test/', 'loopback'];
        yield 'a name that does not resolve' => ['http://gone.test/', 'cannot resolve'];
        yield 'a decimal address' => ['http://2130706433/', 'invalid IPv4'];
        yield 'a short address' => ['http://127.1/', 'invalid IPv4'];
        yield 'a hexadecimal address' => ['http://0x7f.0.0.1/', 'invalid IPv4'];
        yield 'credentials' => ['http://user:pw@example.test/', 'credentials'];
        yield 'a file' => ['file:///etc/passwd', 'only http and https'];
        yield 'an ftp address' => ['ftp://example.test/', 'only http and https'];
        yield 'a javascript address' => ['javascript:alert(1)', 'only http and https'];
        yield 'a data address as a page' => ['data:text/html,hi', 'only http and https'];
        yield 'a backslash' => ['http://127.0.0.1\\@evil.test/', 'backslash'];
        yield 'not a url' => ['not a url', 'invalid URL'];
        yield 'no host' => ['http:///path', 'invalid URL'];
    }

    #[DataProvider('refusedUrls')]
    public function testItRefusesTheRest(string $url, string $reason): void
    {
        $refusal = $this->policy()->refusal($url);

        self::assertNotNull($refusal);
        self::assertStringContainsString($reason, $refusal);
    }

    public function testAPrivateRangeIsOpenOnlyForTheHostsTheUserNames(): void
    {
        $policy = $this->policy(['10.0.0.5', 'app.test']);

        self::assertNull($policy->refusal('http://10.0.0.5:8080/'));
        self::assertNull($policy->refusal('http://app.test/'));
        self::assertNotNull($policy->refusal('http://10.0.0.6/'));
        self::assertNotNull($policy->refusal('http://localhost:3000/'), 'naming other hosts replaces the default list');
    }

    public function testAWildcardNamesEveryHostBelowIt(): void
    {
        $policy = new BrowserPolicy(['*.dev.test'], static fn (): array => ['10.1.1.1']);

        self::assertNull($policy->refusal('http://api.dev.test/'));
        self::assertNull($policy->refusal('https://a.b.dev.test:8443/'));
        self::assertNotNull($policy->refusal('http://dev.test/'));
        self::assertNotNull($policy->refusal('http://evildev.test/'));
    }

    public function testLinkLocalAndUnspecifiedAddressesAreNeverOpenedEvenWhenListed(): void
    {
        $policy = $this->policy(['169.254.169.254', 'metadata.test', 'mapped.test', 'nat64.test', 'v6link.test', '0.0.0.0', 'fe80::1']);

        foreach (['http://169.254.169.254/', 'http://metadata.test/', 'http://mapped.test/', 'http://nat64.test/', 'http://v6link.test/', 'http://0.0.0.0/', 'http://[fe80::1]/'] as $url) {
            $refusal = $policy->refusal($url);
            self::assertNotNull($refusal, $url);
            self::assertStringContainsString('no setting allows it', $refusal, $url);
        }
    }

    public function testAnotherSchemeOfASubRequestPassesOnlyWhenItLeavesNoTrace(): void
    {
        $policy = $this->policy();

        foreach (['data:text/plain,hi', 'blob:http://127.0.0.1:3000/uuid', 'about:blank'] as $url) {
            self::assertNull($policy->refusal($url, true), $url);
            self::assertNotNull($policy->refusal($url), "{$url} is not a page that may be opened");
        }
        self::assertNotNull($policy->refusal('file:///etc/passwd', true));
        self::assertNotNull($policy->refusal('chrome://settings', true));
        self::assertNotNull($policy->refusal('ws://10.0.0.1/', true));
    }

    public function testTheAnswerForAnAddressIsRememberedSoThatPagesDoNotCauseLookupStorms(): void
    {
        $policy = $this->policy();

        for ($index = 0; $index < 50; $index++) {
            $policy->refusal("https://cdn.example.test/asset-{$index}.js", true);
        }
        $policy->refusal('https://cdn.example.test:8443/a.js', true);
        $policy->refusal('http://cdn.example.test/a.js', true);

        self::assertSame(3, $this->lookups, 'one lookup for each scheme, host and port');
    }

    public function testAFailedLookupIsNotRememberedSoThatTheNextTryCanSucceed(): void
    {
        $policy = $this->policy();

        $policy->refusal('http://gone.test/');
        $policy->refusal('http://gone.test/');

        self::assertSame(2, $this->lookups);
    }

    public function testTheMemoryOfAnswersIsBounded(): void
    {
        $policy = $this->policy();

        for ($index = 0; $index < 2500; $index++) {
            $policy->refusal("http://host-{$index}.example.test/");
        }

        $remembered = (new \ReflectionProperty($policy, 'verdicts'))->getValue($policy);
        self::assertLessThanOrEqual(1000, count($remembered));
        self::assertSame(2500, $this->lookups);
    }

    public function testListedHostsAreRecognisedWithoutLookingAnythingUp(): void
    {
        $policy = $this->policy(['localhost', '*.dev.test', '::1']);

        self::assertTrue($policy->listsHost('LOCALHOST'));
        self::assertTrue($policy->listsHost('api.dev.test'));
        self::assertTrue($policy->listsHost('[::1]'));
        self::assertFalse($policy->listsHost('dev.test'));
        self::assertFalse($policy->listsHost('example.test'));
        self::assertFalse($policy->listsHost(''));
        self::assertSame(0, $this->lookups);
    }
}
