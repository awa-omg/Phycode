<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\WebException;
use Phycode\Web\WebUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebUrlTest extends TestCase
{
    private const BASE = 'http://a/b/c/d;p?q';

    public static function references(): iterable
    {
        yield 'a sibling' => ['g', 'http://a/b/c/g'];
        yield 'a child directory' => ['./g', 'http://a/b/c/g'];
        yield 'a directory' => ['g/', 'http://a/b/c/g/'];
        yield 'from the root' => ['/g', 'http://a/g'];
        yield 'the same scheme and host' => ['//g', 'http://g'];
        yield 'a query only' => ['?y', 'http://a/b/c/d;p?y'];
        yield 'a path with a query' => ['g?y', 'http://a/b/c/g?y'];
        yield 'a fragment only' => ['#s', 'http://a/b/c/d;p?q'];
        yield 'a fragment is dropped' => ['g#s', 'http://a/b/c/g'];
        yield 'a query and a fragment' => ['g?y#s', 'http://a/b/c/g?y'];
        yield 'parameters' => ['g;x', 'http://a/b/c/g;x'];
        yield 'the empty reference' => ['', 'http://a/b/c/d;p?q'];
        yield 'the current directory' => ['.', 'http://a/b/c/'];
        yield 'the parent directory' => ['..', 'http://a/b/'];
        yield 'the parent path' => ['../g', 'http://a/b/g'];
        yield 'two levels up' => ['../../g', 'http://a/g'];
        yield 'above the root' => ['../../../g', 'http://a/g'];
        yield 'a dot in the root' => ['/./g', 'http://a/g'];
        yield 'a dotdot in the root' => ['/../g', 'http://a/g'];
        yield 'dots inside a name' => ['g.', 'http://a/b/c/g.'];
        yield 'a name with two dots' => ['..g', 'http://a/b/c/..g'];
        yield 'a dot segment in the middle' => ['g/./h', 'http://a/b/c/g/h'];
        yield 'a dotdot segment in the middle' => ['g/../h', 'http://a/b/c/h'];
        yield 'a dotdot after a query' => ['g?y/../x', 'http://a/b/c/g?y/../x'];
        yield 'an absolute URL' => ['https://other.test/x?y#z', 'https://other.test/x?y'];
    }

    #[DataProvider('references')]
    public function testARelativeReferenceResolvesAgainstTheBase(string $reference, string $expected): void
    {
        self::assertSame($expected, WebUrl::resolve(self::BASE, $reference));
    }

    public function testAQueryOnlyReferenceKeepsThePathOfTheBase(): void
    {
        self::assertSame('https://site.test/docs/page?next=2', WebUrl::resolve('https://site.test/docs/page?first=1', '?next=2'));
    }

    public function testAnInvalidBaseIsAWebException(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('invalid base URL');

        WebUrl::resolve('not a url', 'page');
    }

    public function testTheSafeFormDropsCredentialsQueryAndFragment(): void
    {
        self::assertSame('https://example.com:8443/a/b', WebUrl::safe('https://user:pw@example.com:8443/a/b?token=secret#frag'));
    }

    public function testTheSafeFormKeepsTheBracketsOfAnIpv6Host(): void
    {
        self::assertSame('http://[::1]:8080/x', WebUrl::safe('http://[::1]:8080/x?y'));
    }

    public function testTheSafeFormOfAnUnparsableUrlSaysSo(): void
    {
        self::assertSame('[invalid URL]', WebUrl::safe('not a url'));
    }
}
