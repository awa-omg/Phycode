<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Config\SearchPresets;
use Phycode\Support\Vault;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\SessionHarness;

final class SearchProviderCommandsTest extends RpcTestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new SessionHarness(self::CONFIG);
    }

    private function withSearch(array $search): void
    {
        file_put_contents($this->harness->configFile, json_encode(self::CONFIG + ['web' => ['search' => $search]], JSON_THROW_ON_ERROR));
    }

    private function search(): array
    {
        return json_decode((string) file_get_contents($this->harness->configFile), true, 512, JSON_THROW_ON_ERROR)['web']['search'] ?? [];
    }

    private function vault(): Vault
    {
        return new Vault(dirname($this->harness->configFile));
    }

    private static function errors(array $events): array
    {
        return array_column(array_values(array_filter(self::of($events, 'notice'), static fn (array $event): bool => $event['level'] === 'error')), 'message');
    }

    public function testTheListShowsEachProviderWithItsKeyStatusAndNeverItsKey(): void
    {
        $key = 'BSA-SECRET-LIST-42';
        $this->withSearch(['active' => 'main', 'providers' => [
            'main' => ['backend' => 'brave', 'api_key_store' => true],
            'lan' => ['backend' => 'searxng', 'url' => 'http://192.168.1.20:8888'],
        ]]);
        $this->vault()->storeSearchKey('main', $key);

        $result = $this->play([['type' => 'list_search_providers']], new RequestSpy(['local' => 'qwen']));

        $event = self::first($result['events'], 'search_providers');
        self::assertSame('main', $event['active']);
        self::assertNull($event['legacy']);
        self::assertSame(
            [
                ['name' => 'main', 'backend' => 'brave', 'url' => null, 'key' => 'stored', 'active' => true],
                ['name' => 'lan', 'backend' => 'searxng', 'url' => 'http://192.168.1.20:8888', 'key' => 'none', 'active' => false],
            ],
            $event['items'],
        );
        self::assertSame(array_column(SearchPresets::all(), 'backend'), array_column($event['presets'], 'backend'));
        self::assertSame(['backend', 'url', 'keyRequired', 'urlRequired', 'urlMeans'], array_keys($event['presets'][0]));
        self::assertStringNotContainsString($key, (string) json_encode($result['events'], JSON_THROW_ON_ERROR));
    }

    public function testAddStoresTheKeyInTheVaultAndKeepsTheFileAsItWas(): void
    {
        $key = 'exa-test-KEY-ADD-7319';
        $before = (string) file_get_contents($this->harness->configFile);

        $result = $this->play([
            ['type' => 'add_search_provider', 'name' => 'exa-main', 'backend' => 'exa', 'url' => null, 'api_key' => $key],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertSame([], self::errors($result['events']));
        self::assertSame(['exa-main'], array_column(self::first($result['events'], 'search_providers')['items'], 'name'));
        self::assertSame('exa-main', self::first($result['events'], 'search_providers')['active'], 'adding a provider chooses it');
        self::assertSame('exa-main', $this->search()['active']);
        self::assertSame(['backend' => 'exa', 'api_key_store' => true], $this->search()['providers']['exa-main']);
        self::assertSame($key, $this->vault()->searchKey('exa-main'));
        self::assertSame($before, file_get_contents($this->harness->configFile . '.bak'), 'the file as it was is kept');
        self::assertStringNotContainsString($key, (string) file_get_contents($this->harness->configFile));
        self::assertStringNotContainsString($key, (string) json_encode($result['events'], JSON_THROW_ON_ERROR));
    }

    public function testAProviderNameThatIsTakenOrAKeyThatIsMissingIsANoticeAndTheFileStays(): void
    {
        $this->withSearch(['providers' => ['main' => ['backend' => 'exa', 'api_key_store' => true]]]);
        $before = (string) file_get_contents($this->harness->configFile);

        $result = $this->play([
            ['type' => 'add_search_provider', 'name' => 'main', 'backend' => 'brave', 'url' => null, 'api_key' => 'k'],
            ['type' => 'add_search_provider', 'name' => 'tavily-x', 'backend' => 'tavily', 'url' => null, 'api_key' => null],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertSame([
            "search provider 'main' already exists in {$this->harness->configFile}",
            'tavily needs its API key',
        ], self::errors($result['events']));
        self::assertSame($before, file_get_contents($this->harness->configFile));
        self::assertFalse($this->vault()->hasSearchKey('tavily-x'));
    }

    public function testAnURLThatTheLoaderRefusesIsRefusedBeforeTheKeyIsStored(): void
    {
        $result = $this->play([
            ['type' => 'add_search_provider', 'name' => 'tv', 'backend' => 'tavily', 'url' => 'http://api.tavily.com/search', 'api_key' => 'tvly-x'],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertCount(1, self::errors($result['events']));
        self::assertStringContainsString('must be an https:// URL, because the key is sent to it', self::errors($result['events'])[0]);
        self::assertFalse($this->vault()->hasSearchKey('tv'), 'no key is stored for a provider that is not saved');
        self::assertArrayNotHasKey('tv', $this->search()['providers'] ?? []);
    }

    public function testUpdateChangesTheURLAndTheKeyAndAnEmptyValueKeepsWhatIsThere(): void
    {
        $this->withSearch(['providers' => ['main' => ['backend' => 'brave', 'api_key_store' => true]]]);
        $this->vault()->storeSearchKey('main', 'BSA-OLD-KEY');

        $result = $this->play([
            ['type' => 'update_search_provider', 'name' => 'main', 'url' => '', 'api_key' => ''],
            ['type' => 'update_search_provider', 'name' => 'main', 'url' => 'https://brave.example.test/res/v1/web/search', 'api_key' => 'BSA-NEW-KEY'],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertSame([], self::errors($result['events']));
        self::assertSame('https://brave.example.test/res/v1/web/search', $this->search()['providers']['main']['url']);
        self::assertSame('BSA-NEW-KEY', $this->vault()->searchKey('main'));
        self::assertStringNotContainsString('BSA-NEW-KEY', (string) json_encode($result['events'], JSON_THROW_ON_ERROR));
    }

    public function testTheProviderInUseCannotBeRemovedAndAnotherCan(): void
    {
        $this->withSearch(['active' => 'main', 'providers' => [
            'main' => ['backend' => 'exa', 'api_key_store' => true],
            'old' => ['backend' => 'tavily', 'api_key_store' => true],
        ]]);
        $this->vault()->storeSearchKey('main', 'exa-key-main');
        $this->vault()->storeSearchKey('old', 'tvly-key-old');

        $result = $this->play([
            ['type' => 'remove_search_provider', 'name' => 'main'],
            ['type' => 'remove_search_provider', 'name' => 'old'],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertSame(["search provider 'main' is the one in use: choose another one, or no search, first"], self::errors($result['events']));
        self::assertSame(['main'], array_keys($this->search()['providers']));
        self::assertFalse($this->vault()->hasSearchKey('old'), 'the key of a removed provider goes too');
        self::assertTrue($this->vault()->hasSearchKey('main'));
    }

    public function testSetSearchProviderChoosesOneAndNullTurnsTheSearchOff(): void
    {
        $this->withSearch(['active' => null, 'providers' => [
            'main' => ['backend' => 'exa', 'api_key_store' => true],
            'lan' => ['backend' => 'searxng', 'url' => 'http://192.168.1.20:8888'],
        ]]);
        $this->vault()->storeSearchKey('main', 'exa-key-set');

        $result = $this->play([
            ['type' => 'set_search_provider', 'name' => 'main'],
            ['type' => 'set_search_provider', 'name' => 'lan'],
            ['type' => 'set_search_provider', 'name' => null],
            ['type' => 'set_search_provider', 'name' => 'nope'],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['main', 'lan', null], array_column(self::of($result['events'], 'search_providers'), 'active'));
        self::assertNull($this->search()['active'], 'the last choice, no search, is in the file');
        self::assertSame(["no search provider named 'nope' is in {$this->harness->configFile}"], self::errors($result['events']));
    }

    public function testAKeyThatCannotBeSavedWithItsProviderIsTakenBackOutOfTheVault(): void
    {
        $backup = $this->harness->configFile . '.bak';
        symlink(dirname($this->harness->configFile) . '/elsewhere.bak', $backup);

        $result = $this->play([
            ['type' => 'add_search_provider', 'name' => 'exa-x', 'backend' => 'exa', 'url' => null, 'api_key' => 'exa-rollback-key'],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertSame(["cannot write {$backup}: it is a symbolic link"], self::errors($result['events']));
        self::assertFalse($this->vault()->hasSearchKey('exa-x'), 'the key of a provider that was not saved is taken back out');
        self::assertArrayNotHasKey('providers', json_decode((string) file_get_contents($this->harness->configFile), true, 512, JSON_THROW_ON_ERROR)['web']['search'] ?? []);
    }

    public function testAProviderWhoseKeyIsNotStoredCannotBeChosen(): void
    {
        $this->withSearch(['providers' => ['main' => ['backend' => 'exa', 'api_key_store' => true]]]);

        $result = $this->play([['type' => 'set_search_provider', 'name' => 'main']], new RequestSpy(['local' => 'qwen']));

        self::assertSame(["no key is stored for search provider 'main': add the provider with its key first"], self::errors($result['events']));
        self::assertArrayNotHasKey('active', $this->search());
    }

    public function testTheOlderFormIsShownUntilAProviderIsChosenAndThenItIsIgnoredWithAWarning(): void
    {
        $this->withSearch([
            'backend' => 'searxng',
            'url' => 'http://192.168.1.20:8888',
            'providers' => ['lan' => ['backend' => 'searxng', 'url' => 'http://192.168.1.21:8888']],
        ]);

        $result = $this->play([
            ['type' => 'list_search_providers'],
            ['type' => 'set_search_provider', 'name' => 'lan'],
        ], new RequestSpy(['local' => 'qwen']));

        $events = self::of($result['events'], 'search_providers');
        self::assertSame(['backend' => 'searxng', 'key' => 'none'], $events[0]['legacy']);
        self::assertNull($events[0]['active'], 'the older form decides the search, and no provider is named as active');
        self::assertNull($events[1]['legacy']);
        self::assertSame('lan', $events[1]['active']);
        self::assertContains(['type' => 'notice', 'level' => 'warn', 'message' => 'web.search.backend, url and api_key_file are ignored because web.search.active is set: take them out of the config'], $result['events']);
        self::assertSame('http://192.168.1.20:8888', $this->search()['url'] ?? null, 'the older keys are left in the file');
    }
}
