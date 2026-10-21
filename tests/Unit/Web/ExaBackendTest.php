<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\ExaBackend;
use Phycode\Web\SsrfGuard;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\TestCase;

final class ExaBackendTest extends TestCase
{
    private const KEY = 'exa-test-key-9b2d74';

    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function backend(array $steps): ExaBackend
    {
        $this->server = new FixtureServer(['/search' => $steps]);
        $transport = new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"]));

        return new ExaBackend(self::KEY, $transport, $this->server->url('/search'));
    }

    public function testItPostsTheQueryWithTheKeyHeaderAndReadsTheHighlights(): void
    {
        $backend = $this->backend([['fixture' => 'exa-search.json']]);

        $results = $backend->search('web search engines', 4);

        $request = $this->server?->requests()[0] ?? [];
        self::assertSame('POST', $request['method'] ?? null);
        self::assertSame(self::KEY, $request['headers']['x-api-key'] ?? null);
        self::assertSame(
            ['query' => 'web search engines', 'numResults' => 4, 'contents' => ['highlights' => true]],
            json_decode((string) ($request['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame([
            ['title' => 'Web search engine - Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Web_search_engine', 'snippet' => 'A web search engine is a software system designed to search for information on the World Wide Web. ... The search results are generally presented in a line of results.'],
            ['title' => 'How a search engine works', 'url' => 'https://example.com/how-search-works', 'snippet' => 'Crawlers fetch pages, and an index records which words appear on which pages.'],
            ['title' => 'A page without highlights', 'url' => 'https://example.org/no-highlights', 'snippet' => ''],
        ], $results);
    }

    public function testTheSummaryIsTheSnippetWhenThereAreNoHighlights(): void
    {
        $body = json_encode(['results' => [['url' => 'https://c.test/', 'title' => 'C', 'summary' => 'Short summary.']]], JSON_THROW_ON_ERROR);
        $backend = $this->backend([['body' => $body, 'type' => 'application/json']]);

        self::assertSame(
            [['title' => 'C', 'url' => 'https://c.test/', 'snippet' => 'Short summary.']],
            $backend->search('x', 1),
        );
    }

    public function testTheNumberOfResultsIsCappedAtTheDocumentedMaximum(): void
    {
        $backend = $this->backend([['fixture' => 'exa-search.json']]);

        $backend->search('x', 500);

        $body = json_decode((string) ($this->server?->requests()[0]['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(100, $body['numResults'] ?? null);
    }

    public function testAFailureNamesTheStatusButNeverTheKey(): void
    {
        $backend = $this->backend([[
            'status' => 401,
            'body' => '{"requestId":"r1","error":"the key ' . self::KEY . ' is not valid","tag":"INVALID_API_KEY"}',
            'type' => 'application/json',
        ]]);

        try {
            $backend->search('x', 5);
            self::fail('a 401 reply must fail');
        } catch (WebException $error) {
            self::assertSame('Exa search failed: HTTP 401', $error->getMessage());
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
        }
    }
}
