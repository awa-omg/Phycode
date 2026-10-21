<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\OllamaBackend;
use Phycode\Web\SsrfGuard;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\TestCase;

final class OllamaBackendTest extends TestCase
{
    private const KEY = 'ollama-test-key-9b41c7e2';

    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function backend(array $steps): OllamaBackend
    {
        $this->server = new FixtureServer(['/api/web_search' => $steps]);
        $transport = new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"]));

        return new OllamaBackend(self::KEY, $transport, $this->server->url('/api/web_search'));
    }

    public function testItPostsTheQueryAsJsonWithTheKeyAsABearerTokenAndReadsTheContent(): void
    {
        $backend = $this->backend([['fixture' => 'ollama-search.json']]);

        $results = $backend->search('web search engines', 3);

        $request = $this->server?->requests()[0] ?? [];
        self::assertSame('POST', $request['method'] ?? null);
        self::assertSame('/api/web_search', $request['path'] ?? null);
        self::assertSame('application/json', $request['headers']['content-type'] ?? null);
        self::assertSame('Bearer ' . self::KEY, $request['headers']['authorization'] ?? null);
        self::assertSame(
            ['query' => 'web search engines', 'max_results' => 3],
            json_decode((string) ($request['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame([
            ['title' => 'Web search engine - Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Web_search_engine', 'snippet' => 'A web search engine is a software system designed to search for information on the World Wide Web.'],
            ['title' => 'Python Web Frameworks', 'url' => 'https://example.com/python-frameworks', 'snippet' => 'Main snippet text about frameworks for building web applications in Python.'],
            ['title' => 'How search engines rank pages', 'url' => 'https://example.net/ranking', 'snippet' => 'An overview of crawling, indexing and ranking, written for developers.'],
        ], $results);
    }

    public function testMaxResultsIsCappedAtTen(): void
    {
        $backend = $this->backend([['fixture' => 'ollama-search.json']]);

        $backend->search('x', 50);

        $body = json_decode((string) ($this->server?->requests()[0]['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(10, $body['max_results'] ?? null);
    }

    public function testAFailureNamesTheStatusButNeverTheKey(): void
    {
        $backend = $this->backend([[
            'status' => 401,
            'body' => '{"error":"unauthorized for key ' . self::KEY . '"}',
            'type' => 'application/json',
        ]]);

        try {
            $backend->search('x', 5);
            self::fail('a 401 reply must fail');
        } catch (WebException $error) {
            self::assertSame('Ollama search failed: HTTP 401', $error->getMessage());
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
        }
    }
}
