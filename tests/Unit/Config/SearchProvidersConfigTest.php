<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Config\SearchPresets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchProvidersConfigTest extends TestCase
{
    private const PROFILES = ['profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen']]];

    private static function load(array $search): Config
    {
        return Config::fromArrays(self::PROFILES + ['web' => ['search' => $search]], null, [], '/nonexistent');
    }

    public function testTheSearchInUseIsTheProviderThatActiveNames(): void
    {
        $config = self::load([
            'active' => 'main',
            'providers' => [
                'main' => ['backend' => 'brave', 'api_key_store' => true],
                'lan' => ['backend' => 'searxng', 'url' => 'http://192.168.1.20:8888/'],
            ],
        ]);

        $web = $config->webConfig();
        self::assertSame(['name' => 'main', 'backend' => 'brave', 'url' => null, 'api_key_store' => true, 'api_key_file' => null], $web['search']);
        self::assertSame(['backend' => 'brave', 'url' => null, 'api_key_store' => true, 'api_key_file' => null], $web['providers']['main']);
        self::assertSame('http://192.168.1.20:8888', $web['providers']['lan']['url'], 'the trailing slash of a base URL is removed');
        self::assertFalse($web['legacy']);
        self::assertSame([], $config->warnings);
    }

    public function testActiveNullMeansNoSearchAndTheProvidersStayListed(): void
    {
        $web = self::load(['active' => null, 'providers' => ['main' => ['backend' => 'exa', 'api_key_store' => true]]])->webConfig();

        self::assertSame('none', $web['search']['backend']);
        self::assertNull($web['search']['name']);
        self::assertArrayHasKey('main', $web['providers']);
    }

    public function testTheOlderFormStaysInUseWhileActiveIsNotSet(): void
    {
        $web = self::load(['backend' => 'searxng', 'url' => 'http://127.0.0.1:8888', 'providers' => ['main' => ['backend' => 'exa', 'api_key_store' => true]]])->webConfig();

        self::assertTrue($web['legacy']);
        self::assertSame(['name' => null, 'backend' => 'searxng', 'url' => 'http://127.0.0.1:8888', 'api_key_store' => false, 'api_key_file' => null], $web['search']);
    }

    public function testTheOlderFormIsReportedAsIgnoredOnceActiveIsSet(): void
    {
        $config = self::load(['backend' => 'brave', 'api_key_file' => '/keys/brave', 'active' => null]);

        self::assertSame(['none', null], [$config->webConfig()['search']['backend'], $config->webConfig()['search']['name']]);
        self::assertSame(['web.search.backend, url and api_key_file are ignored because web.search.active is set: take them out of the config'], $config->warnings);
    }

    public function testAKeyedProviderKeepsItsKeyFileAndExpandsTheHome(): void
    {
        $web = self::load(['active' => 'file', 'providers' => ['file' => ['backend' => 'firecrawl', 'api_key_file' => '~/keys/firecrawl']]])->webConfig();

        self::assertSame('firecrawl', $web['search']['backend']);
        self::assertSame(false, $web['search']['api_key_store']);
        self::assertStringEndsWith('/keys/firecrawl', (string) $web['search']['api_key_file']);
        self::assertStringStartsNotWith('~', (string) $web['search']['api_key_file']);
    }

    public static function refusedProviders(): iterable
    {
        yield 'a name with capitals' => [['active' => null, 'providers' => ['Brave' => ['backend' => 'brave', 'api_key_store' => true]]], "web.search.providers name 'Brave' is 1 to 32 characters"];
        yield 'a name with a dot' => [['active' => null, 'providers' => ['my.search' => ['backend' => 'brave', 'api_key_store' => true]]], "web.search.providers name 'my.search' is 1 to 32 characters"];
        yield 'a name that starts with a dash' => [['active' => null, 'providers' => ['-x' => ['backend' => 'brave', 'api_key_store' => true]]], "web.search.providers name '-x' is 1 to 32 characters"];
        yield 'a name of 33 characters' => [['active' => null, 'providers' => [str_repeat('a', 33) => ['backend' => 'brave', 'api_key_store' => true]]], 'is 1 to 32 characters'];
        yield 'no backend' => [['active' => null, 'providers' => ['x' => ['api_key_store' => true]]], 'web.search.providers.x.backend must be searxng, brave, tavily, exa, perplexity, ollama or firecrawl'];
        yield 'none is not a provider' => [['active' => null, 'providers' => ['x' => ['backend' => 'none']]], 'web.search.providers.x.backend must be searxng, brave, tavily, exa, perplexity, ollama or firecrawl'];
        yield 'an unknown backend' => [['active' => null, 'providers' => ['x' => ['backend' => 'google', 'api_key_store' => true]]], 'web.search.providers.x.backend must be'];
        yield 'an unknown setting' => [['active' => null, 'providers' => ['x' => ['backend' => 'exa', 'api_key_store' => true, 'model' => 'x']]], 'web.search.providers.x.model is not a known setting'];
        yield 'a user name in the URL' => [['active' => null, 'providers' => ['x' => ['backend' => 'searxng', 'url' => 'https://user:secret@search.example.test']]], 'web.search.providers.x.url must be an http:// or https:// URL, without a user name or a query'];
        yield 'a query in the URL' => [['active' => null, 'providers' => ['x' => ['backend' => 'searxng', 'url' => 'http://127.0.0.1:8888/?q=1']]], 'without a user name or a query'];
        yield 'a URL that is not http' => [['active' => null, 'providers' => ['x' => ['backend' => 'searxng', 'url' => 'ftp://search.example.test']]], 'web.search.providers.x.url must be an http:// or https:// URL'];
        yield 'SearXNG without a URL' => [['active' => null, 'providers' => ['x' => ['backend' => 'searxng']]], 'web.search.providers.x.url is required for searxng'];
        yield 'a keyed endpoint over http' => [['active' => null, 'providers' => ['x' => ['backend' => 'tavily', 'url' => 'http://api.tavily.com/search', 'api_key_store' => true]]], 'web.search.providers.x.url must be an https:// URL, because the key is sent to it'];
        yield 'a keyed provider with no key source' => [['active' => null, 'providers' => ['x' => ['backend' => 'perplexity']]], 'web.search.providers.x needs one key source: api_key_store true, or api_key_file'];
        yield 'a keyed provider with two key sources' => [['active' => null, 'providers' => ['x' => ['backend' => 'ollama', 'api_key_store' => true, 'api_key_file' => '/keys/ollama']]], 'web.search.providers.x needs one key source'];
        yield 'SearXNG with a key' => [['active' => null, 'providers' => ['x' => ['backend' => 'searxng', 'url' => 'http://127.0.0.1:8888', 'api_key_store' => true]]], 'web.search.providers.x: searxng takes no key'];
        yield 'api_key_store that is not a bool' => [['active' => null, 'providers' => ['x' => ['backend' => 'exa', 'api_key_store' => 'yes']]], 'web.search.providers.x.api_key_store must be true or false'];
        yield 'an active name that is not a provider' => [['active' => 'nope', 'providers' => ['x' => ['backend' => 'exa', 'api_key_store' => true]]], 'web.search.active must be null or the name of a provider in web.search.providers'];
        yield 'an active name with no providers' => [['active' => 'x'], 'web.search.active must be null'];
        yield 'an unknown search setting' => [['active' => null, 'engine' => 'x'], 'web.search.engine is not a known setting'];
    }

    #[DataProvider('refusedProviders')]
    public function testAProviderThatTheLoaderCannotUseIsRefusedWithItsReason(array $search, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        self::load($search);
    }

    public static function acceptedProviders(): iterable
    {
        yield 'a keyed provider with the key in the vault' => ['exa', ['api_key_store' => true]];
        yield 'a keyed provider with the key in a file' => ['tavily', ['api_key_file' => '/keys/tavily']];
        yield 'perplexity with its own endpoint' => ['perplexity', ['url' => 'https://pplx.example.test/search', 'api_key_store' => true]];
        yield 'ollama' => ['ollama', ['api_key_store' => true]];
        yield 'firecrawl' => ['firecrawl', ['api_key_store' => true]];
        yield 'SearXNG with no key' => ['searxng', ['url' => 'http://192.168.1.20:8888']];
    }

    #[DataProvider('acceptedProviders')]
    public function testAProviderWithOneKeySourceIsAccepted(string $backend, array $source): void
    {
        $web = self::load(['active' => 'x', 'providers' => ['x' => ['backend' => $backend] + $source]])->webConfig();

        self::assertSame($backend, $web['search']['backend']);
    }

    public function testEveryPresetBackendIsOneThatTheConfigNames(): void
    {
        $presets = array_column(SearchPresets::all(), 'backend');
        $backends = array_values(array_diff(Config::SEARCH_BACKENDS, ['none']));
        sort($presets);
        sort($backends);

        self::assertSame($backends, $presets);
    }

    public function testThePresetsGiveTheEndpointsOfTheProvidersAndNoKeyForSearxng(): void
    {
        $presets = [];
        foreach (SearchPresets::all() as $preset) {
            $presets[$preset['backend']] = [$preset['url'], $preset['keyRequired'], $preset['urlRequired'], $preset['urlMeans']];
        }

        self::assertSame(['https://api.perplexity.ai/search', true, false, 'endpoint'], $presets['perplexity']);
        self::assertSame(['https://ollama.com/api/web_search', true, false, 'endpoint'], $presets['ollama']);
        self::assertSame(['https://api.firecrawl.dev/v2/search', true, false, 'endpoint'], $presets['firecrawl']);
        self::assertSame([null, false, true, 'base'], $presets['searxng']);
    }
}
