<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\PerplexityBackend;
use Phycode\Web\SsrfGuard;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\TestCase;

final class PerplexityBackendTest extends TestCase
{
    private const KEY = 'pplx-test-key-6d02b7a4';

    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function backend(array $steps): PerplexityBackend
    {
        $this->server = new FixtureServer(['/search' => $steps]);
        $transport = new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"]));

        return new PerplexityBackend(self::KEY, $transport, $this->server->url('/search'));
    }

    public function testItPostsTheQueryAsJsonWithTheKeyAsABearerTokenAndReadsTheResults(): void
    {
        $backend = $this->backend([['fixture' => 'perplexity-search.json']]);

        $results = $backend->search('web search engines', 2);

        $request = $this->server?->requests()[0] ?? [];
        self::assertSame('POST', $request['method'] ?? null);
        self::assertSame('/search', $request['path'] ?? null);
        self::assertSame('application/json', $request['headers']['content-type'] ?? null);
        self::assertSame('Bearer ' . self::KEY, $request['headers']['authorization'] ?? null);
        self::assertSame(
            ['query' => 'web search engines', 'max_results' => 2],
            json_decode((string) ($request['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame([
            ['title' => 'Web search engine - Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Web_search_engine', 'snippet' => 'A web search engine is a software system designed to search for information on the World Wide Web.'],
            ['title' => 'Lionel Messi Facts | Britannica', 'url' => 'https://www.britannica.com/facts/Lionel-Messi', 'snippet' => 'Lionel Messi, an Argentine footballer, is widely regarded as one of the greatest football players of his generation.'],
        ], $results);
    }

    public function testMaxResultsIsCappedAtTwenty(): void
    {
        $backend = $this->backend([['fixture' => 'perplexity-search.json']]);

        $backend->search('x', 99);

        $body = json_decode((string) ($this->server?->requests()[0]['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(20, $body['max_results'] ?? null);
    }

    public function testAFailureNamesTheStatusButNeverTheKey(): void
    {
        $backend = $this->backend([[
            'status' => 401,
            'body' => '{"detail":"the key ' . self::KEY . ' is not valid"}',
            'type' => 'application/json',
        ]]);

        try {
            $backend->search('x', 5);
            self::fail('a 401 reply must fail');
        } catch (WebException $error) {
            self::assertSame('Perplexity search failed: HTTP 401', $error->getMessage());
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
        }
    }
}
