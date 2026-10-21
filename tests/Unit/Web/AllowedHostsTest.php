<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Support\Vault;
use Phycode\Tests\Support\Kit;
use Phycode\Web\SsrfGuard;
use Phycode\Web\WebException;
use Phycode\Web\WebServices;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllowedHostsTest extends TestCase
{
    private const PRIVATE = ['10.0.0.5'];

    private static function resolver(): \Closure
    {
        return static fn (string $host): array => self::PRIVATE;
    }

    public function testAWildcardReachesEverySubdomainOnItsPort(): void
    {
        $guard = new SsrfGuard(['*.example.test:443'], self::resolver());

        self::assertSame('a.example.test', $guard->check('https://a.example.test/')['host']);
        self::assertSame('deep.a.example.test', $guard->check('https://deep.a.example.test/x')['host']);
        self::assertSame('A.Example.Test', $guard->check('https://A.Example.Test/')['host']);
    }

    public static function notReached(): iterable
    {
        yield 'the bare domain' => ['https://example.test/'];
        yield 'another port' => ['https://a.example.test:8443/'];
        yield 'a name that only ends with the domain' => ['https://evilexample.test/'];
        yield 'the domain as a label of another name' => ['https://example.test.evil.test/'];
    }

    #[DataProvider('notReached')]
    public function testAWildcardDoesNotReachTheDomainItselfOrAnotherPort(string $url): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('resolves to 10.0.0.5, which is a private address');

        (new SsrfGuard(['*.example.test:443'], self::resolver()))->check($url);
    }

    public function testAnExactEntryReachesOnlyItsPort(): void
    {
        $guard = new SsrfGuard(['fixture.test:80', 'fixture.test:443'], self::resolver());

        self::assertSame('fixture.test', $guard->check('http://fixture.test/')['host']);
        self::assertSame(443, $guard->check('https://fixture.test/')['port']);

        $this->expectException(WebException::class);
        $guard->check('http://fixture.test:8080/');
    }

    public function testAnEntryWithoutAPortIsRefused(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('allowlist entry *.example.test is not host:port');

        new SsrfGuard(['*.example.test']);
    }

    public function testTheWebServicesSayWhetherSearchIsOnAndReadTheirKeyOnlyWhenUsed(): void
    {
        $folder = Kit::folder('services');
        $services = WebServices::fromConfig([
            'search' => ['name' => null, 'backend' => 'brave', 'url' => null, 'api_key_store' => false, 'api_key_file' => $folder . '/key'],
            'fetch' => ['max_bytes' => 1048576, 'timeout_seconds' => 20],
            'allow_hosts' => ['docs.example.test', '*.example.net'],
            'cache_minutes' => 0,
        ], $folder . '/cache', new Vault($folder));

        self::assertTrue($services->searchOn);
        self::assertSame(["the search key file cannot be read: {$folder}/key"], $services->search->search('q', 2)['errors']);
    }
}
