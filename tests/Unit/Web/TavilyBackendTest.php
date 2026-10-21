<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\SsrfGuard;
use Phycode\Web\TavilyBackend;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\TestCase;

final class TavilyBackendTest extends TestCase
{
    private const KEY = 'tvly-test-key-4c81a0';

    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function backend(array $steps): TavilyBackend
    {
        $this->server = new FixtureServer(['/search' => $steps]);
        $transport = new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"]));

        return new TavilyBackend(self::KEY, $transport, $this->server->url('/search'));
    }

    public function testItPostsTheKeyAndTheQueryAsJsonAndReadsTheResults(): void
    {
        $backend = $this->backend([['fixture' => 'tavily-search.json']]);

        $results = $backend->search('web search engines', 2);

        $request = $this->server?->requests()[0] ?? [];
        self::assertSame('POST', $request['method'] ?? null);
        self::assertSame('/search', $request['path'] ?? null);
        self::assertSame('application/json', $request['headers']['content-type'] ?? null);
        self::assertSame('Bearer ' . self::KEY, $request['headers']['authorization'] ?? null, 'the key is a bearer token, as the Tavily docs say');
        self::assertSame(
            ['query' => 'web search engines', 'max_results' => 2],
            json_decode((string) ($request['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR),
            'the body has no api_key field',
        );
        self::assertSame([
            ['title' => 'Web search engine - Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Web_search_engine', 'snippet' => 'A web search engine is a software system that is designed to search for information on the World Wide Web.'],
            ['title' => 'Lionel Messi Facts | Britannica', 'url' => 'https://www.britannica.com/facts/Lionel-Messi', 'snippet' => 'Lionel Messi, an Argentine footballer, is widely regarded as one of the greatest football players of his generation.'],
        ], $results);
    }

    public function testMaxResultsIsCappedAtTwenty(): void
    {
        $backend = $this->backend([['fixture' => 'tavily-search.json']]);

        $backend->search('x', 99);

        $body = json_decode((string) ($this->server?->requests()[0]['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(20, $body['max_results'] ?? null);
    }

    public function testAFailureNamesTheStatusButNeverTheKey(): void
    {
        $backend = $this->backend([[
            'status' => 432,
            'body' => '{"detail":{"error":"plan limit for ' . self::KEY . ' reached"}}',
            'type' => 'application/json',
        ]]);

        try {
            $backend->search('x', 5);
            self::fail('a 432 reply must fail');
        } catch (WebException $error) {
            self::assertSame('Tavily search failed: HTTP 432', $error->getMessage());
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
        }
    }
}
