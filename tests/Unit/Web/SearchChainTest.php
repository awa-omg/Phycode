<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Tests\Support\Kit;
use Phycode\Web\SearchChain;
use Phycode\Web\SsrfGuard;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchChainTest extends TestCase
{
    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testResultsOfTwoBackendsAreMergedInTheOrderTheyAreAsked(): void
    {
        $first = new FakeSearchBackend(['rust' => [FakeSearchBackend::hit('https://a.test/1'), FakeSearchBackend::hit('https://a.test/2')]]);
        $second = new FakeSearchBackend(['rust' => [FakeSearchBackend::hit('https://b.test/1'), FakeSearchBackend::hit('https://a.test/2'), FakeSearchBackend::hit('https://b.test/2')]]);

        $found = (new SearchChain([$first, $second]))->search('rust', 10);

        self::assertSame(['https://a.test/1', 'https://a.test/2', 'https://b.test/1', 'https://b.test/2'], array_column($found['results'], 'url'));
        self::assertSame([], $found['errors']);
    }

    public function testAUrlThatTwoBackendsGiveIsKeptOnceWithTheFirstSnippet(): void
    {
        $first = new FakeSearchBackend(['q' => [FakeSearchBackend::hit('https://a.test/page', 'First title', 'first snippet')]]);
        $second = new FakeSearchBackend(['q' => [FakeSearchBackend::hit('https://a.test/page', 'Second title', 'second snippet')]]);

        $found = (new SearchChain([$first, $second]))->search('q', 5);

        self::assertCount(1, $found['results']);
        self::assertSame(['title' => 'First title', 'url' => 'https://a.test/page', 'snippet' => 'first snippet'], $found['results'][0]);
    }

    public static function sameUrl(): iterable
    {
        yield 'a fragment' => ['https://a.test/page#intro'];
        yield 'a trailing slash' => ['https://a.test/page/'];
        yield 'surrounding spaces' => ['  https://a.test/page  '];
    }

    #[DataProvider('sameUrl')]
    public function testTheSameUrlWrittenDifferentlyIsOneResult(string $second): void
    {
        $first = new FakeSearchBackend(['q' => [FakeSearchBackend::hit('https://a.test/page', 'A', 'kept')]]);
        $other = new FakeSearchBackend(['q' => [FakeSearchBackend::hit($second, 'B', 'dropped')]]);

        $found = (new SearchChain([$first, $other]))->search('q', 5);

        self::assertCount(1, $found['results']);
        self::assertSame('kept', $found['results'][0]['snippet']);
    }

    public function testTheLimitCapsTheMergeAndTheLaterBackendIsNotAsked(): void
    {
        $first = new FakeSearchBackend(['q' => [FakeSearchBackend::hit('https://a.test/1'), FakeSearchBackend::hit('https://a.test/2')]]);
        $second = new FakeSearchBackend(['q' => [FakeSearchBackend::hit('https://b.test/1')]]);

        $found = (new SearchChain([$first, $second]))->search('q', 2);

        self::assertCount(2, $found['results']);
        self::assertSame([], $second->asked, 'the limit was reached by the first backend');
    }

    public function testAFailingBackendIsReportedAndTheNextOneIsStillAsked(): void
    {
        $failing = new FakeSearchBackend(['q' => new WebException('Brave search failed: HTTP 500')]);
        $working = new FakeSearchBackend(['q' => [FakeSearchBackend::hit('https://b.test/1')]]);

        $found = (new SearchChain([$failing, $working]))->search('q', 5);

        self::assertSame(['https://b.test/1'], array_column($found['results'], 'url'));
        self::assertSame(['Brave search failed: HTTP 500'], $found['errors']);
    }

    public function testWhenEveryBackendFailsThereAreNoResultsAndEveryReason(): void
    {
        $chain = new SearchChain([
            new FakeSearchBackend(['q' => new WebException('one failed')]),
            new FakeSearchBackend(['q' => new WebException('two failed')]),
        ]);

        self::assertSame(['results' => [], 'errors' => ['one failed', 'two failed']], $chain->search('q', 5));
    }

    public function testNoBackendGivesNothingAndNoError(): void
    {
        self::assertSame(['results' => [], 'errors' => []], (new SearchChain([]))->search('q', 5));
    }

    public function testALimitBelowOneIsRefused(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('the result limit must be at least 1');

        (new SearchChain([new FakeSearchBackend([])]))->search('q', 0);
    }

    public function testTheKeyOfAKeyedBackendIsReadFromItsFileWhenItIsFirstNeeded(): void
    {
        $folder = Kit::folder('keys');
        $chain = SearchChain::fromSettings(
            ['backend' => 'brave', 'url' => null, 'api_key_file' => $folder . '/missing-key'],
            new Transport(new SsrfGuard()),
        );

        $found = $chain->search('q', 3);

        self::assertSame([], $found['results']);
        self::assertSame(["the search key file cannot be read: {$folder}/missing-key"], $found['errors'], 'the failure is reported at search time, without a request');
    }

    public function testAnEmptyKeyFileIsAFailureThatNamesTheFile(): void
    {
        $folder = Kit::folder('keys');
        Kit::write($folder . '/empty-key', "  \n");

        $found = SearchChain::fromSettings(
            ['backend' => 'exa', 'url' => null, 'api_key_file' => $folder . '/empty-key'],
            new Transport(new SsrfGuard()),
        )->search('q', 3);

        self::assertSame(["the search key file is empty: {$folder}/empty-key"], $found['errors']);
    }

    public function testAKeyedBackendWithoutAKeyFileIsAFailure(): void
    {
        $found = SearchChain::fromSettings(['backend' => 'tavily', 'url' => null, 'api_key_file' => null], new Transport(new SsrfGuard()))->search('q', 3);

        self::assertSame(['the search backend needs web.search.api_key_file'], $found['errors']);
    }

    public function testNoBackendNamedMeansNoSearch(): void
    {
        $found = SearchChain::fromSettings(['backend' => 'none', 'url' => null, 'api_key_file' => null], new Transport(new SsrfGuard()))->search('q', 3);

        self::assertSame(['results' => [], 'errors' => []], $found);
    }

    public function testSearxngAnswersThroughTheConfiguredAddress(): void
    {
        $this->server = new FixtureServer(['/search' => [['fixture' => 'searxng-search.json', 'type' => 'application/json']]]);
        $chain = SearchChain::fromSettings(
            ['backend' => 'searxng', 'url' => $this->server->url(), 'api_key_file' => null],
            new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"])),
        );

        $found = $chain->search('web search engines', 2);

        self::assertSame([], $found['errors']);
        self::assertSame('SearXNG', $found['results'][0]['title']);
        self::assertSame('https://github.com/searxng/searxng', $found['results'][0]['url']);
        self::assertCount(2, $found['results']);
        self::assertSame('/search', $this->server->requests()[0]['path']);
    }
}
