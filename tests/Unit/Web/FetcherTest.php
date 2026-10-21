<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Tests\Support\Kit;
use Phycode\Web\Cache;
use Phycode\Web\Fetcher;
use Phycode\Web\SsrfGuard;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\TestCase;

final class FetcherTest extends TestCase
{
    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private static function site(): array
    {
        return [
            '/article' => [['fixture' => 'article.html']],
            '/chain/1' => [['status' => 302, 'headers' => ['Location' => '/chain/2']]],
            '/chain/2' => [['status' => 302, 'headers' => ['Location' => '/chain/3']]],
            '/chain/3' => [['status' => 302, 'headers' => ['Location' => '/chain/4']]],
            '/chain/4' => [['status' => 302, 'headers' => ['Location' => '/chain/5']]],
            '/chain/5' => [['status' => 302, 'headers' => ['Location' => '/chain/6']]],
            '/chain/6' => [['status' => 302, 'headers' => ['Location' => '/article']]],
            '/no-location' => [['status' => 302]],
            '/to-private' => [['status' => 302, 'headers' => ['Location' => 'http://10.0.0.1/admin']]],
            '/to-file' => [['status' => 302, 'headers' => ['Location' => 'file:///etc/passwd']]],
            '/big' => [['bytes' => 3_000_000, 'type' => 'text/html; charset=utf-8']],
            '/image' => [['body_base64' => base64_encode("\x89PNG\r\n\x1a\n"), 'type' => 'image/png']],
            '/text' => [['body' => "  Plain text line\r\nsecond  \n", 'type' => 'text/plain; charset=utf-8']],
            '/json' => [['body' => '{"ok": true}', 'type' => 'application/json']],
            '/xhtml' => [['body' => '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>X</title></head><body><p>Hi</p></body></html>', 'type' => 'application/xhtml+xml']],
            '/missing' => [['status' => 404, 'body' => 'nope', 'type' => 'text/html']],
            '/slow' => [['sleep' => 3, 'body' => 'late', 'type' => 'text/plain']],
            '/latin1' => [['body_base64' => base64_encode("<html><head><title>Caf\xe9</title></head><body><p>Caf\xe9 au lait</p></body></html>"), 'type' => 'text/html; charset=ISO-8859-1']],
            '/latin1-meta' => [['body_base64' => base64_encode("<html><head><meta charset=\"ISO-8859-1\"><title>Caf\xe9</title></head><body><p>Caf\xe9</p></body></html>"), 'type' => 'text/html']],
            '/counted' => [
                ['body' => '<html><title>First</title><p>one</p></html>', 'type' => 'text/html'],
                ['body' => '<html><title>Second</title><p>two</p></html>', 'type' => 'text/html'],
            ],
        ];
    }

    private function serve(): FixtureServer
    {
        return $this->server = new FixtureServer(self::site());
    }

    private function fetcher(FixtureServer $server, int $timeout = 5, int $maxBytes = 2_000_000, ?Cache $cache = null): Fetcher
    {
        return new Fetcher(new Transport(new SsrfGuard(["127.0.0.1:{$server->port()}"]), $timeout, $maxBytes), 5, $cache);
    }

    public function testAPageComesBackAsMarkdownWithItsTitleAndItsUrl(): void
    {
        $server = $this->serve();

        $page = $this->fetcher($server)->fetch($server->url('/article'));

        self::assertSame('Fixture article: the basics', $page['title']);
        self::assertSame($server->url('/article'), $page['url']);
        self::assertStringStartsWith("# Getting started\n", $page['markdown']);
        self::assertFalse($page['truncated']);
    }

    public function testFiveRedirectsAreFollowedAndTheFinalUrlIsReturned(): void
    {
        $server = $this->serve();

        $page = $this->fetcher($server)->fetch($server->url('/chain/2'));

        self::assertSame($server->url('/article'), $page['url']);
        self::assertCount(6, $server->requests(), 'chain/2 to chain/6, then the article');
    }

    public function testASixthRedirectIsRefusedBeforeItIsFollowed(): void
    {
        $server = $this->serve();

        try {
            $this->fetcher($server)->fetch($server->url('/chain/1'));
            self::fail('a sixth redirect must be refused');
        } catch (WebException $error) {
            self::assertSame('too many redirects', $error->getMessage());
        }
        self::assertCount(6, $server->requests());
        self::assertSame([], array_filter($server->requests(), static fn (array $request): bool => $request['path'] === '/article'));
    }

    public function testARedirectWithoutALocationIsAFailure(): void
    {
        $server = $this->serve();

        $this->expectException(WebException::class);
        $this->expectExceptionMessage('HTTP 302 without a location');

        $this->fetcher($server)->fetch($server->url('/no-location'));
    }

    public function testEveryRedirectTargetIsCheckedBeforeItIsRequested(): void
    {
        $server = $this->serve();

        try {
            $this->fetcher($server)->fetch($server->url('/to-private'));
            self::fail('a redirect to a private address must be refused');
        } catch (WebException $error) {
            self::assertSame('refused: 10.0.0.1 is a private address', $error->getMessage());
        }
        self::assertCount(1, $server->requests(), 'only the first hop was requested');
    }

    public function testARedirectToAFileUrlIsRefused(): void
    {
        $server = $this->serve();

        $this->expectException(WebException::class);
        $this->expectExceptionMessage('file URLs are refused; only http and https are allowed');

        $this->fetcher($server)->fetch($server->url('/to-file'));
    }

    public function testFileUrlsAreNeverRead(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('file URLs are refused');

        (new Fetcher(new Transport(new SsrfGuard())))->fetch('file:///etc/passwd');
    }

    public function testTheByteLimitStopsTheTransferAndMarksThePageAsTruncated(): void
    {
        $server = $this->serve();

        $page = $this->fetcher($server, maxBytes: 100_000)->fetch($server->url('/big'));

        self::assertTrue($page['truncated']);
        self::assertNotSame('', $page['markdown']);
        self::assertLessThan(100_000, strlen($page['markdown']));
    }

    public function testAnotherContentTypeIsRefusedWithItsName(): void
    {
        $server = $this->serve();

        $this->expectException(WebException::class);
        $this->expectExceptionMessage('unsupported content type image/png');

        $this->fetcher($server)->fetch($server->url('/image'));
    }

    public function testPlainTextAndJsonAreReturnedWithoutConversion(): void
    {
        $server = $this->serve();
        $fetcher = $this->fetcher($server);

        $text = $fetcher->fetch($server->url('/text'));
        $json = $fetcher->fetch($server->url('/json'));

        self::assertSame('', $text['title']);
        self::assertSame("Plain text line\nsecond", $text['markdown']);
        self::assertSame('{"ok": true}', $json['markdown']);
    }

    public function testXhtmlIsConvertedLikeHtml(): void
    {
        $server = $this->serve();

        $page = $this->fetcher($server)->fetch($server->url('/xhtml'));

        self::assertSame('X', $page['title']);
        self::assertSame('Hi', $page['markdown']);
    }

    public function testAnErrorStatusIsAFailure(): void
    {
        $server = $this->serve();

        $this->expectException(WebException::class);
        $this->expectExceptionMessage('HTTP 404');

        $this->fetcher($server)->fetch($server->url('/missing'));
    }

    public function testATimeoutIsAFailure(): void
    {
        $server = $this->serve();

        $this->expectException(WebException::class);
        $this->expectExceptionMessage('timed out after 1s');

        $this->fetcher($server, timeout: 1)->fetch($server->url('/slow'));
    }

    public function testAConnectionThatIsRefusedIsAFailure(): void
    {
        $server = new FixtureServer([]);
        $port = $server->port();
        $server->stop();

        try {
            (new Fetcher(new Transport(new SsrfGuard(["127.0.0.1:{$port}"]))))->fetch("http://127.0.0.1:{$port}/");
            self::fail('nothing listens on the port');
        } catch (WebException $error) {
            self::assertStringStartsWith('request failed: ', $error->getMessage());
        }
    }

    public function testCredentialsInTheUrlAreRefusedAndNeverEchoed(): void
    {
        $server = $this->serve();
        $url = "http://user:hunter2x@127.0.0.1:{$server->port()}/article";

        try {
            $this->fetcher($server)->fetch($url);
            self::fail('a URL with credentials must be refused');
        } catch (WebException $error) {
            self::assertSame('URLs with credentials are refused', $error->getMessage());
        }
        self::assertCount(0, $server->requests());
    }

    public function testTheCharsetOfTheHeaderDecidesTheText(): void
    {
        $server = $this->serve();

        $page = $this->fetcher($server)->fetch($server->url('/latin1'));

        self::assertSame('Café', $page['title']);
        self::assertSame('Café au lait', $page['markdown']);
    }

    public function testTheMetaCharsetDecidesTheTextWhenTheHeaderHasNone(): void
    {
        $server = $this->serve();

        $page = $this->fetcher($server)->fetch($server->url('/latin1-meta'));

        self::assertSame('Café', $page['title']);
        self::assertSame('Café', $page['markdown']);
    }

    public function testAFetchedPageIsServedFromTheCacheUntilItExpires(): void
    {
        $server = $this->serve();
        $now = 1_000;
        $cache = new Cache(Kit::folder('cache'), 1, static function () use (&$now): int {
            return $now;
        });
        $fetcher = $this->fetcher($server, cache: $cache);

        $first = $fetcher->fetch($server->url('/counted'));
        $second = $fetcher->fetch($server->url('/counted'));
        self::assertSame($first, $second);
        self::assertSame('First', $first['title']);
        self::assertCount(1, $server->requests(), 'the second read did not reach the server');

        $now += 61;
        self::assertSame('Second', $fetcher->fetch($server->url('/counted'))['title']);
        self::assertCount(2, $server->requests(), 'an expired entry is fetched again');
    }

    public function testAHostNameIsConnectedToTheAddressThatWasChecked(): void
    {
        $server = $this->serve();
        $guard = new SsrfGuard(["fixture.test:{$server->port()}"], static fn (string $host): array => ['127.0.0.1']);

        $page = (new Fetcher(new Transport($guard)))->fetch("http://fixture.test:{$server->port()}/article");

        self::assertSame('Fixture article: the basics', $page['title']);
    }
}
