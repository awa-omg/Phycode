<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\BraveBackend;
use Phycode\Web\SsrfGuard;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\TestCase;

final class BraveBackendTest extends TestCase
{
    private const KEY = 'BSA-test-token-7f3e9d21';

    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function backend(array $steps): BraveBackend
    {
        $this->server = new FixtureServer(['/res/v1/web/search' => $steps]);
        $transport = new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"]));

        return new BraveBackend(self::KEY, $transport, $this->server->url('/res/v1/web/search'));
    }

    public function testItSendsTheTokenAndReadsTheWebResults(): void
    {
        $backend = $this->backend([['fixture' => 'brave-web-search.json']]);

        $results = $backend->search('web search engines', 3);

        $request = $this->server?->requests()[0] ?? [];
        self::assertSame('GET', $request['method'] ?? null);
        self::assertSame('q=web%20search%20engines&count=3', $request['query'] ?? null);
        self::assertSame(self::KEY, $request['headers']['x-subscription-token'] ?? null);
        self::assertSame([
            ['title' => 'Web search engine - Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Web_search_engine', 'snippet' => 'A web search engine is a software system designed to search for information on the World Wide Web.'],
            ['title' => 'Python Web Frameworks', 'url' => 'https://example.com/python-frameworks', 'snippet' => 'Main snippet text about frameworks for building web applications in Python.'],
            ['title' => 'How search engines rank pages', 'url' => 'https://example.net/ranking', 'snippet' => 'An overview of crawling, indexing and ranking, written for developers.'],
        ], $results);
    }

    public function testTheCountIsCappedAtTheMaximumOfTwenty(): void
    {
        $backend = $this->backend([['fixture' => 'brave-web-search.json']]);

        $backend->search('web search engines', 50);

        self::assertSame('q=web%20search%20engines&count=20', $this->server?->requests()[0]['query'] ?? null);
    }

    public function testTheLimitCapsTheResultsAndTagsAreRemovedFromTheSnippets(): void
    {
        $body = json_encode(['web' => ['results' => [
            ['title' => 'One', 'url' => 'https://one.test/', 'description' => 'a <strong>bold</strong> word'],
            ['title' => 'Two', 'url' => 'https://two.test/', 'description' => 'second'],
        ]]], JSON_THROW_ON_ERROR);
        $backend = $this->backend([['body' => $body, 'type' => 'application/json']]);

        self::assertSame([
            ['title' => 'One', 'url' => 'https://one.test/', 'snippet' => 'a bold word'],
        ], $backend->search('x', 1));
    }

    public function testAFailureNamesTheStatusButNeverTheToken(): void
    {
        $backend = $this->backend([[
            'status' => 401,
            'body' => '{"error":{"status":401,"detail":"the token ' . self::KEY . ' is not valid"}}',
            'type' => 'application/json',
        ]]);

        try {
            $backend->search('x', 5);
            self::fail('a 401 reply must fail');
        } catch (WebException $error) {
            self::assertSame('Brave search failed: HTTP 401', $error->getMessage());
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
        }
    }
}
