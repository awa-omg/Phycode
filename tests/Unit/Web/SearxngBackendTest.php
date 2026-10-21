<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\SearxngBackend;
use Phycode\Web\SsrfGuard;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\TestCase;

final class SearxngBackendTest extends TestCase
{
    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function backend(array $steps): SearxngBackend
    {
        $this->server = new FixtureServer(['/search' => $steps]);
        $transport = new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"]));

        return new SearxngBackend($this->server->url(), $transport);
    }

    public function testItSearchesTheInstanceAsJsonAndReturnsTheResults(): void
    {
        $backend = $this->backend([['fixture' => 'searxng-search.json']]);

        $results = $backend->search('web search engines', 10);

        $request = $this->server?->requests()[0] ?? [];
        self::assertSame('GET', $request['method'] ?? null);
        self::assertSame('/search', $request['path'] ?? null);
        self::assertSame('q=web%20search%20engines&format=json', $request['query'] ?? null);
        self::assertSame([
            ['title' => 'SearXNG', 'url' => 'https://github.com/searxng/searxng', 'snippet' => 'A free internet metasearch engine which aggregates results from more than 70 search services.'],
            ['title' => 'Metasearch engine - Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Metasearch_engine', 'snippet' => 'A metasearch engine is an online information retrieval tool that uses the data of a web search engine to produce its own results.'],
            ['title' => 'A review of privacy-friendly search engines', 'url' => 'https://example.org/a-search-engine-review', 'snippet' => 'Notes on the engines that do not track their users, and on what each one returns.'],
        ], $results);
    }

    public function testTheLimitCapsTheNumberOfResults(): void
    {
        $results = $this->backend([['fixture' => 'searxng-search.json']])->search('web search engines', 2);

        self::assertCount(2, $results);
        self::assertSame('https://en.wikipedia.org/wiki/Metasearch_engine', $results[1]['url']);
    }

    public function testResultsWithoutAnHttpUrlAreSkippedAndAMissingTitleShowsTheUrl(): void
    {
        $body = json_encode(['results' => [
            ['url' => 'javascript:alert(1)', 'title' => 'bad'],
            ['url' => 'https://a.test/x', 'content' => 'c &amp; <b>d</b>'],
            ['url' => 'https://b.test/y', 'title' => '  B  '],
        ]], JSON_THROW_ON_ERROR);
        $backend = $this->backend([['body' => $body, 'type' => 'application/json']]);

        self::assertSame([
            ['title' => 'https://a.test/x', 'url' => 'https://a.test/x', 'snippet' => 'c & d'],
            ['title' => 'B', 'url' => 'https://b.test/y', 'snippet' => ''],
        ], $backend->search('x', 5));
    }

    public function testAnInstanceThatRefusesJsonIsAFailureThatSaysWhatToEnable(): void
    {
        $backend = $this->backend([['status' => 403, 'body' => 'Forbidden', 'type' => 'text/html']]);

        $this->expectException(WebException::class);
        $this->expectExceptionMessage('SearXNG search failed: HTTP 403. Add json to search.formats in the settings.yml of the instance');

        $backend->search('x', 5);
    }

    public function testAReplyThatIsNotAJsonObjectIsAFailure(): void
    {
        $backend = $this->backend([['body' => '[1, 2', 'type' => 'application/json']]);

        $this->expectException(WebException::class);
        $this->expectExceptionMessage('SearXNG search returned no JSON object');

        $backend->search('x', 5);
    }

    public function testAnHtmlPageInPlaceOfJsonIsRefusedByItsType(): void
    {
        $backend = $this->backend([['body' => '<html>login</html>', 'type' => 'text/html']]);

        $this->expectException(WebException::class);
        $this->expectExceptionMessage('unsupported content type text/html');

        $backend->search('x', 5);
    }

    public function testTheLimitMustBeAtLeastOne(): void
    {
        $backend = new SearxngBackend('http://127.0.0.1:1', new Transport(new SsrfGuard()));

        $this->expectException(WebException::class);
        $this->expectExceptionMessage('the result limit must be at least 1');

        $backend->search('x', 0);
    }
}
