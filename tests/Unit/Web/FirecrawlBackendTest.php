<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\FirecrawlBackend;
use Phycode\Web\SsrfGuard;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\TestCase;

final class FirecrawlBackendTest extends TestCase
{
    private const KEY = 'fc-test-key-3e8a52d1';

    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function backend(array $steps): FirecrawlBackend
    {
        $this->server = new FixtureServer(['/v2/search' => $steps]);
        $transport = new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"]));

        return new FirecrawlBackend(self::KEY, $transport, $this->server->url('/v2/search'));
    }

    public function testItPostsTheQueryAsJsonWithTheKeyAsABearerTokenAndReadsTheWebResults(): void
    {
        $backend = $this->backend([['fixture' => 'firecrawl-search.json']]);

        $results = $backend->search('web search engines', 3);

        $request = $this->server?->requests()[0] ?? [];
        self::assertSame('POST', $request['method'] ?? null);
        self::assertSame('/v2/search', $request['path'] ?? null);
        self::assertSame('application/json', $request['headers']['content-type'] ?? null);
        self::assertSame('Bearer ' . self::KEY, $request['headers']['authorization'] ?? null);
        self::assertSame(
            ['query' => 'web search engines', 'limit' => 3],
            json_decode((string) ($request['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame([
            ['title' => 'Web search engine - Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Web_search_engine', 'snippet' => 'A web search engine is a software system designed to search for information on the World Wide Web.'],
            ['title' => 'How a search engine works', 'url' => 'https://example.com/how-search-works', 'snippet' => 'Crawlers fetch pages, and an index records which words appear on which pages.'],
            ['title' => 'A page without a description', 'url' => 'https://example.org/no-description', 'snippet' => ''],
        ], $results);
    }

    public function testTheLimitIsCappedAtOneHundred(): void
    {
        $backend = $this->backend([['fixture' => 'firecrawl-search.json']]);

        $backend->search('x', 500);

        $body = json_decode((string) ($this->server?->requests()[0]['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(100, $body['limit'] ?? null);
    }

    public function testAFailureNamesTheStatusButNeverTheKey(): void
    {
        $backend = $this->backend([[
            'status' => 402,
            'body' => '{"success":false,"error":"no credits left for ' . self::KEY . '"}',
            'type' => 'application/json',
        ]]);

        try {
            $backend->search('x', 5);
            self::fail('a 402 reply must fail');
        } catch (WebException $error) {
            self::assertSame('Firecrawl search failed: HTTP 402', $error->getMessage());
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
        }
    }
}
