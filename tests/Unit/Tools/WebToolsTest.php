<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Unit\Web\FakeSearchBackend;
use Phycode\Tests\Unit\Web\FixtureServer;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use Phycode\Tools\WebFetchTool;
use Phycode\Tools\WebSearchTool;
use Phycode\Web\Fetcher;
use Phycode\Web\SearchChain;
use Phycode\Web\SsrfGuard;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebToolsTest extends TestCase
{
    private ?FixtureServer $server = null;
    private ToolContext $context;

    protected function setUp(): void
    {
        $this->context = Kit::context(Kit::folder('web-tools'), Kit::agent(), Kit::policy('ask'), new RecordingUi());
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function search(FakeSearchBackend $backend, bool $enabled = true): WebSearchTool
    {
        return new WebSearchTool(new SearchChain([$backend]), $enabled);
    }

    public function testWebSearchIsARead(): void
    {
        self::assertSame(ToolCategory::Read, $this->search(new FakeSearchBackend([]))->category());
        self::assertSame('web_search: rust', $this->search(new FakeSearchBackend([]))->summarize(['query' => 'rust']));
    }

    public function testWebSearchIsOfferedOnlyWhenItIsEnabled(): void
    {
        $policy = Kit::policy();

        self::assertTrue($this->search(new FakeSearchBackend([]), true)->isAvailable($policy));
        self::assertFalse($this->search(new FakeSearchBackend([]), false)->isAvailable($policy));
    }

    public function testResultsAreListedAndMarkedAsUntrustedWeb(): void
    {
        $backend = new FakeSearchBackend(['rust' => [
            FakeSearchBackend::hit('https://a.test/1', 'Rust book', 'a language guide'),
            FakeSearchBackend::hit('https://b.test/2', 'Second'),
        ]]);

        $result = $this->search($backend)->execute(['query' => 'rust'], $this->context);

        self::assertTrue($result->ok);
        self::assertStringStartsWith('<untrusted_web_content source="web search: rust">' . "\n", $result->content);
        self::assertStringEndsWith("\n</untrusted_web_content>", $result->content);
        self::assertStringContainsString("1. Rust book\n   https://a.test/1\n   a language guide\n2. Second\n   https://b.test/2", $result->content);
    }

    public static function limits(): iterable
    {
        yield 'the default' => [['query' => 'q'], 5];
        yield 'a given limit' => [['query' => 'q', 'limit' => 3], 3];
        yield 'a limit above the maximum is cut' => [['query' => 'q', 'limit' => 99], 10];
        yield 'a limit below the minimum is raised' => [['query' => 'q', 'limit' => 0], 1];
    }

    #[DataProvider('limits')]
    public function testTheLimitIsCutToARangeAndPassedToTheBackend(array $arguments, int $expected): void
    {
        $backend = new FakeSearchBackend([]);

        $this->search($backend)->execute($arguments, $this->context);

        self::assertSame([$expected], $backend->limits);
    }

    public function testNoResultsIsAnAnswerNotAFailure(): void
    {
        $result = $this->search(new FakeSearchBackend([]))->execute(['query' => 'nothing here'], $this->context);

        self::assertTrue($result->ok);
        self::assertSame('No results for: nothing here', $result->content);
    }

    public function testWhenEveryBackendFailsTheReasonsAreTheFailure(): void
    {
        $result = $this->search(new FakeSearchBackend(['q' => new WebException('Brave search failed: HTTP 500')]))
            ->execute(['query' => 'q'], $this->context);

        self::assertFalse($result->ok);
        self::assertSame('web search failed: Brave search failed: HTTP 500', $result->content);
    }

    public function testAPartlyFailedSearchKeepsItsResultsAndNamesTheFailure(): void
    {
        $chain = new SearchChain([
            new FakeSearchBackend(['q' => new WebException('Tavily search failed: HTTP 429')]),
            new FakeSearchBackend(['q' => [FakeSearchBackend::hit('https://c.test/')]]),
        ]);

        $result = (new WebSearchTool($chain, true))->execute(['query' => 'q'], $this->context);

        self::assertTrue($result->ok);
        self::assertStringContainsString('https://c.test/', $result->content);
        self::assertStringEndsWith("\nSome search backends failed: Tavily search failed: HTTP 429", $result->content);
    }

    public function testAnEmptyQueryIsRefused(): void
    {
        $result = $this->search(new FakeSearchBackend([]))->execute(['query' => '   '], $this->context);

        self::assertFalse($result->ok);
        self::assertSame("argument 'query' is empty", $result->content);
    }

    private function fetch(bool $enabled = true): WebFetchTool
    {
        $this->server?->stop();
        $this->server = new FixtureServer([
            '/article' => [['fixture' => 'article.html']],
            '/missing' => [['status' => 404, 'body' => 'nope', 'type' => 'text/html']],
        ]);
        $fetcher = new Fetcher(new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"])));

        return new WebFetchTool($fetcher, $enabled);
    }

    public function testWebFetchIsARead(): void
    {
        self::assertSame(ToolCategory::Read, (new WebFetchTool(new Fetcher(new Transport(new SsrfGuard())), true))->category());
    }

    public function testWebFetchIsOfferedOnlyWhenItIsEnabled(): void
    {
        $policy = Kit::policy();

        self::assertTrue($this->fetch(true)->isAvailable($policy));
        self::assertFalse($this->fetch(false)->isAvailable($policy));
    }

    public function testAPageIsReturnedAsMarkdownInsideTheUntrustedWrapper(): void
    {
        $tool = $this->fetch();
        $url = $this->server?->url('/article');

        $result = $tool->execute(['url' => $url], $this->context);

        self::assertTrue($result->ok);
        self::assertStringStartsWith('<untrusted_web_content source="' . $url . '">' . "\n", $result->content);
        self::assertStringContainsString("Title: Fixture article: the basics\nURL: {$url}\n\n# Getting started\n", $result->content);
        self::assertStringEndsWith("\n</untrusted_web_content>", $result->content);
    }

    public function testAFailingPageIsAFailureWithTheReason(): void
    {
        $tool = $this->fetch();

        $result = $tool->execute(['url' => $this->server?->url('/missing')], $this->context);

        self::assertFalse($result->ok);
        self::assertSame('HTTP 404', $result->content);
    }

    public function testAPrivateAddressIsRefusedBeforeAnythingIsRequested(): void
    {
        $tool = new WebFetchTool(new Fetcher(new Transport(new SsrfGuard())), true);

        $result = $tool->execute(['url' => 'http://10.0.0.1/admin'], $this->context);

        self::assertFalse($result->ok);
        self::assertSame('refused: 10.0.0.1 is a private address', $result->content);
    }

    public function testAnEmptyUrlIsRefused(): void
    {
        $result = (new WebFetchTool(new Fetcher(new Transport(new SsrfGuard())), true))->execute(['url' => ' '], $this->context);

        self::assertFalse($result->ok);
        self::assertSame("argument 'url' is empty", $result->content);
    }
}
