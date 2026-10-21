<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Config\Config;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\ClientProvider;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;
use Phycode\Llm\ModelCatalog;
use Phycode\Support\PhycodeException;
use Phycode\Support\StateStore;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelCatalogTest extends TestCase
{
    public function testEveryModelOfEveryProfileIsListedInConfigOrder(): void
    {
        $providers = new ListingProviders([
            'local' => [new ModelInfo('qwen', 'local', 'openai', 32768, true, ['off', 'on'], true), new ModelInfo('mistral', 'local', 'openai')],
            'cloud' => [new ModelInfo('gpt', 'cloud', 'openai', 400000, false, ['low', 'high'])],
        ]);
        $catalog = new ModelCatalog($providers, self::config(['local' => ['model' => 'qwen'], 'cloud' => ['model' => 'gpt']]));

        self::assertSame([
            ['profile' => 'local', 'model' => 'qwen', 'protocol' => 'openai', 'contextWindow' => 32768, 'tools' => true, 'thinking' => ['off', 'on'], 'thinkingDefault' => 'none', 'loaded' => true, 'error' => null, 'custom' => false, 'kind' => null, 'effortLevels' => []],
            ['profile' => 'local', 'model' => 'mistral', 'protocol' => 'openai', 'contextWindow' => null, 'tools' => null, 'thinking' => [], 'thinkingDefault' => 'none', 'loaded' => null, 'error' => null, 'custom' => false, 'kind' => null, 'effortLevels' => []],
            ['profile' => 'cloud', 'model' => 'gpt', 'protocol' => 'openai', 'contextWindow' => 400000, 'tools' => false, 'thinking' => ['low', 'high'], 'thinkingDefault' => 'none', 'loaded' => null, 'error' => null, 'custom' => false, 'kind' => null, 'effortLevels' => []],
        ], $catalog->entries());
    }

    public function testTheEffortLevelsOfAModelAreListedWithIt(): void
    {
        $providers = new ListingProviders([
            'cloud' => [new ModelInfo('claude-opus-5-5', 'cloud', 'anthropic', effortLevels: ['low', 'medium', 'high', 'xhigh', 'max'])],
        ]);
        $catalog = new ModelCatalog($providers, self::config(['cloud' => ['model' => 'claude-opus-5-5', 'protocol' => 'anthropic']]));

        self::assertSame(['low', 'medium', 'high', 'xhigh', 'max'], $catalog->entries()[0]['effortLevels']);
    }

    public static function failures(): iterable
    {
        yield 'server not running' => [new LlmException('connection to http://127.0.0.1:9 failed: refused')];
        yield 'unreadable api key file' => [new PhycodeException('profile broken: cannot read api_key_file /missing/key')];
    }

    #[DataProvider('failures')]
    public function testAFailingProfileShowsItsErrorAndTheOthersStillShow(PhycodeException $failure): void
    {
        $providers = new ListingProviders([
            'local' => [new ModelInfo('qwen', 'local', 'openai')],
            'broken' => $failure,
            'cloud' => [new ModelInfo('gpt', 'cloud', 'openai')],
        ]);
        $catalog = new ModelCatalog($providers, self::config([
            'local' => ['model' => 'qwen'],
            'broken' => ['model' => 'llama3'],
            'cloud' => ['model' => 'gpt'],
        ]));

        $entries = $catalog->entries();

        self::assertSame(['local', 'broken', 'cloud'], array_column($entries, 'profile'));
        self::assertSame('llama3', $entries[1]['model'], 'a failing profile keeps its configured model');
        self::assertSame($failure->getMessage(), $entries[1]['error']);
        self::assertNull($entries[0]['error']);
        self::assertNull($entries[2]['error']);
    }

    public function testAProfileTheFactoryRefusesStillGetsAnEntry(): void
    {
        $providers = new class implements ClientProvider {
            public function forProfile(string $name, ?string $model = null, ?string $thinking = null): ChatClient
            {
                if ($name === 'broken') {
                    throw new LlmException('the factory refused profile broken');
                }

                return new ListingClient([new ModelInfo('qwen', 'local', 'openai')]);
            }

            public function toolMode(string $name, ?string $model = null, ?string $thinking = null): string
            {
                return 'none';
            }
        };
        $catalog = new ModelCatalog($providers, self::config([
            'local' => ['model' => 'qwen'],
            'broken' => ['model' => 'llama3', 'protocol' => 'ollama'],
        ]));

        $entries = $catalog->entries();

        self::assertSame(['local', 'broken'], array_column($entries, 'profile'));
        self::assertSame('ollama', $entries[1]['protocol']);
        self::assertSame('the factory refused profile broken', $entries[1]['error']);
    }

    public function testTheAnthropicAndResponsesProfilesAreAskedAndListedLikeTheOthers(): void
    {
        $providers = new ListingProviders([
            'local' => [new ModelInfo('qwen', 'local', 'openai')],
            'claude' => [new ModelInfo('claude-haiku-5-5', 'claude', 'anthropic', 200000)],
            'responses' => [new ModelInfo('gpt-5', 'responses', 'openai-responses')],
        ]);
        $catalog = new ModelCatalog($providers, self::config([
            'local' => ['model' => 'qwen'],
            'claude' => ['model' => 'claude-haiku-5-5', 'protocol' => 'anthropic'],
            'responses' => ['model' => 'gpt-5', 'protocol' => 'openai-responses'],
        ]));

        $providerRows = $catalog->providers();
        $entries = $catalog->entries();

        self::assertSame(['local', 'claude', 'responses'], $providers->asked);
        self::assertSame([null, null], [$providerRows[1]['error'], $providerRows[2]['error']]);
        self::assertSame(['qwen', 'claude-haiku-5-5', 'gpt-5'], array_column($entries, 'model'));
        self::assertSame(['anthropic', 'openai-responses'], [$entries[1]['protocol'], $entries[2]['protocol']]);
        self::assertSame(200000, $entries[1]['contextWindow']);
    }

    public function testTheListIsKeptUntilARefreshIsAsked(): void
    {
        $providers = new ListingProviders(['local' => [new ModelInfo('qwen', 'local', 'openai')]]);
        $catalog = new ModelCatalog($providers, self::config(['local' => ['model' => 'qwen']]));
        self::assertSame([], $providers->asked, 'the constructor asks no server');

        $first = $catalog->entries();
        $providers->answers['local'] = [new ModelInfo('mistral', 'local', 'openai')];

        self::assertSame($first, $catalog->entries());
        self::assertSame(['local'], $providers->asked, 'the second call is answered from memory');

        self::assertSame('mistral', $catalog->entries(true)[0]['model']);
        self::assertSame(['local', 'local'], $providers->asked);
    }

    public function testEachEntryIsAPlainArrayThatEncodesAsJson(): void
    {
        $providers = new ListingProviders([
            'local' => [new ModelInfo('qwen', 'local', 'openai', 8192, null, ['on'], false, 'v1/models')],
            'broken' => new LlmException('down'),
        ]);
        $catalog = new ModelCatalog($providers, self::config([
            'local' => ['model' => 'qwen'],
            'broken' => ['model' => 'llama3'],
        ]));

        $entries = $catalog->entries();

        foreach ($entries as $entry) {
            self::assertSame(['profile', 'model', 'protocol', 'contextWindow', 'tools', 'thinking', 'thinkingDefault', 'loaded', 'error', 'custom', 'kind', 'effortLevels'], array_keys($entry));
            self::assertIsList($entry['thinking']);
        }
        self::assertSame(['on'], $entries[0]['thinking']);
        self::assertJson(json_encode($entries, JSON_THROW_ON_ERROR));
    }

    public function testAServerThatListsNoModelsGetsOneEntryWithItsConfiguredModel(): void
    {
        $providers = new ListingProviders([
            'local' => [new ModelInfo('qwen', 'local', 'openai')],
            'empty' => [],
        ]);
        $catalog = new ModelCatalog($providers, self::config([
            'local' => ['model' => 'qwen'],
            'empty' => ['model' => 'llama3', 'protocol' => 'ollama'],
        ]));

        $entries = $catalog->entries();

        self::assertSame(['local', 'empty'], array_column($entries, 'profile'));
        self::assertSame([
            'profile' => 'empty',
            'model' => 'llama3',
            'protocol' => 'ollama',
            'contextWindow' => null,
            'tools' => null,
            'thinking' => [],
            'thinkingDefault' => 'none',
            'loaded' => null,
            'error' => 'the server lists no models',
            'custom' => false,
            'kind' => null,
            'effortLevels' => [],
        ], $entries[1]);
        self::assertNull($entries[0]['error']);
    }

    public function testEveryEntryCarriesTheThinkingLevelOfItsProfileAndAFailingOneToo(): void
    {
        $providers = new ListingProviders([
            'local' => [new ModelInfo('qwen', 'local', 'openai', thinkingLevels: ['off', 'on'])],
            'broken' => new LlmException('down'),
            'cloud' => [new ModelInfo('gpt', 'cloud', 'openai', thinkingLevels: ['low', 'high'])],
        ]);
        $catalog = new ModelCatalog($providers, self::config([
            'local' => ['model' => 'qwen', 'thinking' => 'on'],
            'broken' => ['model' => 'llama3', 'thinking' => 'high'],
            'cloud' => ['model' => 'gpt'],
        ]));

        $entries = $catalog->entries();

        self::assertSame('medium', $entries[0]['thinkingDefault'], 'the level the profile is configured with: on is medium');
        self::assertSame('high', $entries[1]['thinkingDefault'], 'an entry with an error keeps the level too');
        self::assertSame('none', $entries[2]['thinkingDefault'], 'none when the config sets no level');
    }

    public function testTheCustomIdsOfAProfileComeAfterItsListedModelsWithoutDuplicates(): void
    {
        $state = new StateStore(Kit::folder('catalog-state') . '/state.json');
        $state->addCustomModel('local', 'mistral');
        $state->addCustomModel('local', 'custom-only');
        $state->addCustomModel('local', 'custom-only');
        $providers = new ListingProviders(['local' => [
            new ModelInfo('qwen', 'local', 'openai', 8192, null, ['on'], true),
            new ModelInfo('mistral', 'local', 'openai'),
        ]]);
        $catalog = new ModelCatalog($providers, self::config(['local' => ['model' => 'qwen']]), $state);

        $entries = $catalog->entries();

        self::assertSame(['qwen', 'mistral', 'custom-only'], array_column($entries, 'model'));
        self::assertSame([false, false, true], array_column($entries, 'custom'), 'an id the server lists stays the listed entry');
        self::assertSame([
            'profile' => 'local',
            'model' => 'custom-only',
            'protocol' => 'openai',
            'contextWindow' => null,
            'tools' => null,
            'thinking' => [],
            'thinkingDefault' => 'none',
            'loaded' => null,
            'error' => null,
            'custom' => true,
            'kind' => null,
            'effortLevels' => [],
        ], $entries[2]);
        self::assertSame(3, $catalog->providers()[0]['count']);
    }

    public function testACustomIdAddedOrRemovedLaterShowsAtOnceWithoutAskingTheServers(): void
    {
        $state = new StateStore(Kit::folder('catalog-state') . '/state.json');
        $providers = new ListingProviders(['local' => [new ModelInfo('qwen', 'local', 'openai')]]);
        $catalog = new ModelCatalog($providers, self::config(['local' => ['model' => 'qwen']]), $state);
        $catalog->entries();

        $state->addCustomModel('local', 'mistral');
        self::assertSame(['qwen', 'mistral'], array_column($catalog->entries(), 'model'));

        $state->removeCustomModel('local', 'mistral');
        self::assertSame(['qwen'], array_column($catalog->entries(), 'model'));
        self::assertSame(['local'], $providers->asked, 'the server was asked once');
    }

    public function testACustomIdOfAFailingProfileStaysUsableAfterTheErrorEntry(): void
    {
        $state = new StateStore(Kit::folder('catalog-state') . '/state.json');
        $state->addCustomModel('broken', 'offline-model');
        $providers = new ListingProviders(['broken' => new LlmException('connection refused')]);
        $catalog = new ModelCatalog($providers, self::config(['broken' => ['model' => 'llama3']]), $state);

        $entries = $catalog->entries();

        self::assertSame(['llama3', 'offline-model'], array_column($entries, 'model'));
        self::assertSame(['connection refused', null], array_column($entries, 'error'));
        self::assertSame([false, true], array_column($entries, 'custom'));
        self::assertSame(['count' => 1, 'error' => 'connection refused'], array_intersect_key($catalog->providers()[0], ['count' => true, 'error' => true]));
    }

    public function testEveryProfileHasAProviderEntryWithItsEndpointCountErrorAndTimeOfTheLastListing(): void
    {
        $providers = new ListingProviders([
            'local' => [new ModelInfo('qwen', 'local', 'openai'), new ModelInfo('mistral', 'local', 'openai')],
            'broken' => new LlmException('connection to http://127.0.0.1:9 failed: refused'),
            'empty' => [],
            'socket' => [new ModelInfo('tiny', 'socket', 'openai')],
        ]);
        $catalog = new ModelCatalog($providers, self::config([
            'local' => ['model' => 'qwen', 'base_url' => 'http://127.0.0.1:8082/v1'],
            'broken' => ['model' => 'llama3', 'base_url' => 'http://127.0.0.1:9'],
            'empty' => ['model' => 'x', 'base_url' => 'https://api.example.test:443'],
            'socket' => ['model' => 'tiny', 'base_url' => 'unix:///tmp/phycode-test.sock'],
        ]));
        $started = time();

        $rows = $catalog->providers();

        self::assertSame(['local', 'broken', 'empty', 'socket'], array_column($rows, 'profile'));
        self::assertSame(['http://127.0.0.1:8082', 'http://127.0.0.1:9', 'https://api.example.test', 'unix:/tmp/phycode-test.sock'], array_column($rows, 'endpoint'), 'the default port of the scheme is left out');
        self::assertSame([2, 0, 0, 1], array_column($rows, 'count'));
        self::assertSame([null, 'connection to http://127.0.0.1:9 failed: refused', 'the server lists no models', null], array_column($rows, 'error'));
        self::assertSame(['openai', 'openai', 'openai', 'openai'], array_column($rows, 'protocol'));
        self::assertSame([false, false, false, false], array_column($rows, 'refreshing'));
        $times = array_column($rows, 'updatedAt');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $times[0]);
        self::assertGreaterThanOrEqual($started, strtotime((string) $times[0]));
        self::assertNull($times[1], 'a failed listing has no time');
        self::assertNotNull($times[2], 'a server that answers with no models has been listed');
        self::assertNotNull($times[3]);
    }

    public function testTheEndpointNeverShowsUserInformationOrAQuery(): void
    {
        $providers = new ListingProviders(['local' => [], 'cloud' => []]);
        $catalog = new ModelCatalog($providers, self::config([
            'local' => ['model' => 'qwen', 'base_url' => 'http://admin:hunter2@127.0.0.1:8082/v1?key=secret'],
            'cloud' => ['model' => 'gpt', 'base_url' => 'https://user:pw@models.example/path'],
        ]));

        $rows = $catalog->providers();

        self::assertSame(['http://127.0.0.1:8082', 'https://models.example'], array_column($rows, 'endpoint'));
        self::assertStringNotContainsString('hunter2', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function testAFailedRefreshKeepsTheTimeOfTheLastSuccessfulListing(): void
    {
        $providers = new ListingProviders(['local' => [new ModelInfo('qwen', 'local', 'openai')]]);
        $catalog = new ModelCatalog($providers, self::config(['local' => ['model' => 'qwen']]));
        $first = $catalog->providers()[0]['updatedAt'];
        self::assertNotNull($first);

        $providers->answers['local'] = new LlmException('connection refused');
        $failed = $catalog->providers(true)[0];

        self::assertSame(['connection refused', 0], [$failed['error'], $failed['count']]);
        self::assertSame($first, $failed['updatedAt']);
    }

    public function testTheRefreshingFlagIsTrueWhileAProfileIsFetchedAndFalseOtherwise(): void
    {
        $providers = new WatchingProviders();
        $catalog = new ModelCatalog($providers, self::config(['local' => ['model' => 'qwen'], 'cloud' => ['model' => 'gpt']]));
        $providers->catalog = $catalog;

        $catalog->entries();
        self::assertSame(['local' => true, 'cloud' => false], $providers->seen['local']);
        self::assertSame(['local' => false, 'cloud' => true], $providers->seen['cloud']);
        self::assertSame([false, false], array_column($catalog->providers(), 'refreshing'));

        $providers->seen = [];
        $catalog->entries(true);
        self::assertSame(['local' => true, 'cloud' => false], $providers->seen['local']);
        self::assertSame(['local' => false, 'cloud' => true], $providers->seen['cloud']);
        self::assertSame([false, false], array_column($catalog->providers(), 'refreshing'));
    }

    private static function config(array $profiles): Config
    {
        $global = ['profiles' => []];
        foreach ($profiles as $name => $profile) {
            $global['profiles'][$name] = ['base_url' => 'http://127.0.0.1:8082', ...$profile];
        }

        return Config::fromArrays($global, null, [], '/nonexistent');
    }
}

final class ListingProviders implements ClientProvider
{
    public array $asked = [];

    public function __construct(public array $answers)
    {
    }

    public function forProfile(string $name, ?string $model = null, ?string $thinking = null): ChatClient
    {
        $this->asked[] = $name;

        return new ListingClient($this->answers[$name]);
    }

    public function toolMode(string $name, ?string $model = null, ?string $thinking = null): string
    {
        return 'none';
    }
}

final class ListingClient implements ChatClient
{
    public function __construct(private readonly array|PhycodeException $answer)
    {
    }

    public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
    {
        throw new \LogicException('the catalog never chats');
    }

    public function listModels(): array
    {
        if ($this->answer instanceof PhycodeException) {
            throw $this->answer;
        }

        return $this->answer;
    }

    public function describe(string $model): ModelInfo
    {
        throw new \LogicException('the catalog never describes a model');
    }
}

final class WatchingProviders implements ClientProvider
{
    public ?ModelCatalog $catalog = null;

    public array $seen = [];

    public function forProfile(string $name, ?string $model = null, ?string $thinking = null): ChatClient
    {
        return new class ($this, $name) implements ChatClient {
            public function __construct(private readonly WatchingProviders $providers, private readonly string $name)
            {
            }

            public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
            {
                throw new \LogicException('the catalog never chats');
            }

            public function listModels(): array
            {
                $this->providers->seen[$this->name] = array_column($this->providers->catalog?->providers() ?? [], 'refreshing', 'profile');

                return [new ModelInfo('model-' . $this->name, $this->name, 'openai')];
            }

            public function describe(string $model): ModelInfo
            {
                throw new \LogicException('the catalog never describes a model');
            }
        };
    }

    public function toolMode(string $name, ?string $model = null, ?string $thinking = null): string
    {
        return 'none';
    }
}
