<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Config\Config;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;
use Phycode\Tests\Support\Kit;
use Phycode\Web\SearchChain;
use Phycode\Web\SearchKeys;
use Phycode\Web\SsrfGuard;
use Phycode\Web\Transport;
use Phycode\Web\WebServices;
use PHPUnit\Framework\TestCase;

final class SearchProvidersTest extends TestCase
{
    private const KEY = 'tvly-stored-KEY-5150';

    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testTheProviderInUseIsAskedAtItsEndpointWithItsKeyFromTheVault(): void
    {
        $folder = Kit::folder('providers');
        $vault = new Vault($folder);
        $vault->storeSearchKey('mine', self::KEY);
        $this->server = new FixtureServer(['/tavily/search' => [['fixture' => 'tavily-search.json']]]);
        $search = ['name' => 'mine', 'backend' => 'tavily', 'url' => $this->server->url('/tavily/search'), 'api_key_store' => true, 'api_key_file' => null];

        $found = SearchChain::fromSettings($search, new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"])), $vault)->search('web search engines', 2);

        self::assertSame([], $found['errors']);
        self::assertCount(2, $found['results']);
        $request = $this->server->requests()[0];
        self::assertSame('/tavily/search', $request['path'], 'the endpoint of the provider, not the default one');
        self::assertSame('Bearer ' . self::KEY, $request['headers']['authorization']);
    }

    public function testASearxngProviderOnTheLanIsReachedThroughTheAllowlistOfItsAddress(): void
    {
        $this->server = new FixtureServer(['/search' => [['fixture' => 'searxng-search.json', 'type' => 'application/json']]]);
        $folder = Kit::folder('providers');
        $config = Config::fromArrays([
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen']],
            'web' => ['search' => ['active' => 'lan', 'providers' => ['lan' => ['backend' => 'searxng', 'url' => $this->server->url()]]]],
        ], null, [], '/nonexistent');

        $services = WebServices::fromConfig((array) $config->webConfig(), $folder . '/cache', new Vault($folder));
        $found = $services->search->search('web search engines', 2);

        self::assertTrue($services->searchOn);
        self::assertSame([], $found['errors']);
        self::assertSame('SearXNG', $found['results'][0]['title']);
        self::assertSame('/search', $this->server->requests()[0]['path']);
    }

    public function testAKeyThatTheServerEchoesIsNotInTheFailure(): void
    {
        $folder = Kit::folder('providers');
        $vault = new Vault($folder);
        $vault->storeSearchKey('mine', self::KEY);
        $this->server = new FixtureServer(['/tavily/search' => [['status' => 401, 'body' => '{"detail":"the key ' . self::KEY . ' is not valid"}', 'type' => 'application/json']]]);
        $search = ['name' => 'mine', 'backend' => 'tavily', 'url' => $this->server->url('/tavily/search'), 'api_key_store' => true, 'api_key_file' => null];

        $found = SearchChain::fromSettings($search, new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"])), $vault)->search('x', 2);

        self::assertSame(['Tavily search failed: HTTP 401'], $found['errors']);
        self::assertStringNotContainsString(self::KEY, (string) json_encode($found, JSON_THROW_ON_ERROR));
    }

    public function testAKeyThatIsNotStoredIsAFailureThatNamesTheProvider(): void
    {
        $search = ['name' => 'mine', 'backend' => 'brave', 'url' => null, 'api_key_store' => true, 'api_key_file' => null];

        $found = SearchChain::fromSettings($search, new Transport(new SsrfGuard()), new Vault(Kit::folder('providers')))->search('x', 2);

        self::assertSame(['no key is stored for the search provider mine: add the provider with its key'], $found['errors']);
    }

    public function testTheKeysOfTheProvidersAreTheSecretsThatMessagesHideAndTheMissingOnesAreSkipped(): void
    {
        $folder = Kit::folder('providers');
        $vault = new Vault($folder);
        $vault->storeSearchKey('mine', self::KEY);
        Kit::write($folder . '/perplexity.key', "pplx-file-KEY-7\n");
        $web = [
            'search' => ['name' => 'mine', 'backend' => 'tavily', 'url' => null, 'api_key_store' => true, 'api_key_file' => null],
            'providers' => [
                'mine' => ['backend' => 'tavily', 'url' => null, 'api_key_store' => true, 'api_key_file' => null],
                'pplx' => ['backend' => 'perplexity', 'url' => null, 'api_key_store' => false, 'api_key_file' => $folder . '/perplexity.key'],
                'lan' => ['backend' => 'searxng', 'url' => 'http://127.0.0.1:8888', 'api_key_store' => false, 'api_key_file' => null],
                'gone' => ['backend' => 'exa', 'url' => null, 'api_key_store' => true, 'api_key_file' => null],
            ],
        ];

        self::assertSame([self::KEY, 'pplx-file-KEY-7'], SearchKeys::secrets($web, $vault));
    }

    public function testTheStatusOfAProviderSaysWhereItsKeyIsNotWhatItIs(): void
    {
        $folder = Kit::folder('providers');
        $vault = new Vault($folder);
        $vault->storeSearchKey('mine', self::KEY);
        Kit::write($folder . '/ollama.key', "ollama-file-key\n");
        $stored = ['backend' => 'tavily', 'api_key_store' => true, 'api_key_file' => null];

        self::assertSame('stored', SearchKeys::status('mine', $stored, $vault));
        self::assertSame('missing', SearchKeys::status('other', $stored, $vault));
        self::assertSame('file', SearchKeys::status('', ['backend' => 'ollama', 'api_key_store' => false, 'api_key_file' => $folder . '/ollama.key'], $vault));
        self::assertSame('missing', SearchKeys::status('', ['backend' => 'ollama', 'api_key_store' => false, 'api_key_file' => $folder . '/absent.key'], $vault));
        self::assertSame('none', SearchKeys::status('lan', ['backend' => 'searxng', 'api_key_store' => false, 'api_key_file' => null], $vault));
    }

    public function testASearchKeyIsNotAProfileKeyAndAProfileNameCannotHoldAColon(): void
    {
        $vault = new Vault(Kit::folder('providers'));
        $vault->storeSearchKey('brave', self::KEY);

        self::assertFalse(Vault::validProfile('web-search:brave'));
        self::assertSame([], $vault->profiles(), 'a search key is not listed as a profile');
        self::assertTrue($vault->hasSearchKey('brave'));
        self::assertSame(self::KEY, $vault->searchKey('brave'));
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('a profile name is 1 to 128 characters, none of them a control character or a colon');

        $vault->get('web-search:brave');
    }

    public function testASearchProviderNameIsCheckedBeforeItIsUsedAsAKeyName(): void
    {
        $vault = new Vault(Kit::folder('providers'));

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('a search provider name is 1 to 32 characters');

        $vault->storeSearchKey('Brave Search', self::KEY);
    }
}
