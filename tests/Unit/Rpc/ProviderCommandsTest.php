<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Config\ProviderPresets;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\ClientProvider;
use Phycode\Llm\LlmResponse;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;

final class ProviderCommandsTest extends RpcTestCase
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

    public function testAddProviderWritesTheProfileAndAnswersWithAModelsEventThatListsIt(): void
    {
        $before = file_get_contents($this->harness->configFile);
        $providers = new AnyProfileProvider(['remote' => [new ModelInfo('mistral', 'remote', 'ollama')]]);

        $result = $this->play([self::addCommand()], $providers);

        self::assertSame(['ready', 'models', 'bye'], self::types($result['events']));
        $models = self::first($result['events'], 'models');
        self::assertSame(['local-model', 'mistral'], array_column($models['entries'], 'model'));
        self::assertSame(['local', 'remote'], array_column($models['providers'], 'profile'));
        $remote = $models['providers'][1];
        self::assertSame(['ollama', 'http://127.0.0.1:9', 1, null], [$remote['protocol'], $remote['endpoint'], $remote['count'], $remote['error']]);
        self::assertNotNull($remote['updatedAt']);
        self::assertSame(0, $result['exit']);

        self::assertSame(
            ['base_url' => 'http://127.0.0.1:9', 'protocol' => 'ollama', 'model' => 'mistral'],
            json_decode((string) file_get_contents($this->harness->configFile), true, 512, JSON_THROW_ON_ERROR)['profiles']['remote'],
        );
        self::assertSame($before, file_get_contents($this->harness->configFile . '.bak'), 'the file as it was is kept');
    }

    public function testTheNewProviderCanBeChosenInTheSameSession(): void
    {
        $result = $this->play([
            self::addCommand(),
            ['type' => 'set_model', 'profile' => 'remote', 'model' => 'mistral', 'thinking' => 'off', 'save' => false],
        ], new AnyProfileProvider());

        self::assertSame(
            ['profile' => 'remote', 'model' => 'mistral', 'thinking' => 'none', 'saved' => false],
            array_diff_key(self::first($result['events'], 'model_changed'), ['type' => true]),
        );
    }

    public function testAProviderWithTheAnthropicProtocolIsAskedAndListedWithItsModels(): void
    {
        $providers = new AnyProfileProvider(['claude' => [new ModelInfo('claude-haiku-5-5', 'claude', 'anthropic')]]);

        $result = $this->play([self::addCommand('claude', 'https://api.example.test', 'anthropic', 'CLAUDE_KEY', 'claude-haiku-5-5')], $providers);

        $models = self::first($result['events'], 'models');
        $claude = $models['providers'][1];
        self::assertSame(['claude', 'anthropic', null, 1], [$claude['profile'], $claude['protocol'], $claude['error'], $claude['count']]);
        self::assertSame('https://api.example.test', $claude['endpoint']);
        self::assertSame(['claude-haiku-5-5'], array_column(array_values(array_filter($models['entries'], static fn (array $entry): bool => $entry['profile'] === 'claude')), 'model'));
        self::assertSame(['local', 'claude'], $providers->asked, 'the new server is asked as soon as the provider is saved');
    }

    public function testTheModelsEventListsThePresetsWithTheirNamesAndNoKey(): void
    {
        $result = $this->play([['type' => 'list_models', 'refresh' => false]], new AnyProfileProvider());

        $presets = self::first($result['events'], 'models')['presets'];
        self::assertSame(array_column(ProviderPresets::all(), 'name'), array_column($presets, 'name'));
        self::assertSame(['name', 'protocol', 'baseUrl', 'apiKeyEnv', 'keyInEnv'], array_keys($presets[0]));
        self::assertContains('ollama-cloud', array_column($presets, 'name'));
    }

    public function testAPresetSaysWhetherItsKeyVariableIsSetAndNeverCarriesTheKey(): void
    {
        $before = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=sk-from-the-environment');
        try {
            $set = $this->play([['type' => 'list_models', 'refresh' => false]], new AnyProfileProvider());
            putenv('OPENAI_API_KEY');
            $unset = $this->play([['type' => 'list_models', 'refresh' => false]], new AnyProfileProvider());
        } finally {
            putenv($before === false ? 'OPENAI_API_KEY' : 'OPENAI_API_KEY=' . $before);
        }

        $flags = static fn (array $result): array => array_column(self::first($result['events'], 'models')['presets'], 'keyInEnv', 'name');
        self::assertTrue($flags($set)['openai']);
        self::assertFalse($flags($unset)['openai']);
        self::assertFalse($flags($set)['lmstudio'], 'a preset with no key variable has no key in the environment');
        self::assertStringNotContainsString('sk-from-the-environment', (string) json_encode($set['events'], JSON_THROW_ON_ERROR));
    }

    public function testSetProviderKeyStoresTheKeyAndAnswersWithAModelsEventThatAsksTheServerAgain(): void
    {
        $key = 'sk-test-SECRET-987654';
        $providers = new AnyProfileProvider();

        $result = $this->play([['type' => 'set_provider_key', 'profile' => 'local', 'api_key' => $key]], $providers);

        self::assertSame(['ready', 'models', 'bye'], self::types($result['events']));
        self::assertSame(['local'], $providers->asked, 'the server of the profile is asked for its models with the new key');
        $saved = json_decode((string) file_get_contents($this->harness->configFile), true, 512, JSON_THROW_ON_ERROR)['profiles']['local'];
        self::assertTrue($saved['api_key_store']);
        self::assertStringNotContainsString($key, (string) file_get_contents($this->harness->configFile));
        self::assertSame($key, (new \Phycode\Support\Vault(dirname($this->harness->configFile)))->get('local'));
        self::assertStringNotContainsString($key, (string) json_encode($result['events'], JSON_THROW_ON_ERROR));
    }

    public function testRemoveProviderTakesTheProfileAndItsKeyOutAndAnswersWithTheCatalogue(): void
    {
        $key = 'sk-test-REMOVE-424242';
        $result = $this->play([
            ['type' => 'add_provider', 'name' => 'remote', 'protocol' => 'ollama', 'base_url' => 'http://127.0.0.1:9', 'api_key_env' => null, 'model' => 'mistral', 'api_key' => $key],
            ['type' => 'remove_provider', 'profile' => 'remote'],
        ], new AnyProfileProvider());

        self::assertSame(['ready', 'models', 'models', 'bye'], self::types($result['events']));
        $last = self::of($result['events'], 'models')[1];
        self::assertSame(['local'], array_column($last['providers'], 'profile'));
        $saved = json_decode((string) file_get_contents($this->harness->configFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['local'], array_keys($saved['profiles']));
        self::assertNull((new \Phycode\Support\Vault(dirname($this->harness->configFile)))->get('remote'));
    }

    public function testTheProviderOfTheSessionAndAMissingOneAreNotRemoved(): void
    {
        $before = file_get_contents($this->harness->configFile);

        $result = $this->play([
            ['type' => 'remove_provider', 'profile' => 'local'],
            ['type' => 'remove_provider', 'profile' => 'nowhere'],
            ['type' => 'remove_provider'],
        ], new AnyProfileProvider());

        $messages = array_column(self::of($result['events'], 'notice'), 'message');
        self::assertStringContainsString('is the provider of this session', $messages[0]);
        self::assertStringContainsString('nowhere', $messages[1]);
        self::assertSame('remove_provider needs a profile', $messages[2]);
        self::assertSame([], self::of($result['events'], 'models'));
        self::assertSame($before, file_get_contents($this->harness->configFile));
    }

    public function testDetectEndpointsAnswersWithAListOfWhatRunsHere(): void
    {
        $result = $this->play([['type' => 'detect_endpoints']], new AnyProfileProvider());

        $endpoints = self::first($result['events'], 'endpoints');
        self::assertIsArray($endpoints['items']);
        foreach ($endpoints['items'] as $item) {
            self::assertSame(['name', 'protocol', 'baseUrl', 'server', 'count', 'models', 'needsKey', 'configured'], array_keys($item));
        }
    }

    public static function refusedKeys(): iterable
    {
        yield 'no key' => [['type' => 'set_provider_key', 'profile' => 'local'], 'set_provider_key needs a profile and an api_key'];
        yield 'a profile that is not text' => [['type' => 'set_provider_key', 'profile' => 3, 'api_key' => 'sk-x'], 'set_provider_key needs a profile and an api_key'];
        yield 'a profile that is not there' => [['type' => 'set_provider_key', 'profile' => 'nowhere', 'api_key' => 'sk-x'], 'nowhere'];
        yield 'a key of two lines' => [['type' => 'set_provider_key', 'profile' => 'local', 'api_key' => "sk-a\nsk-b"], 'api_key must be the key itself'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedKeys')]
    public function testAKeyThatCannotBeStoredIsANoticeAndChangesNothing(array $command, string $message): void
    {
        $before = file_get_contents($this->harness->configFile);

        $result = $this->play([$command], new AnyProfileProvider());

        $notice = self::first($result['events'], 'notice');
        self::assertSame('error', $notice['level']);
        self::assertStringContainsString($message, $notice['message']);
        self::assertStringNotContainsString('sk-', $notice['message']);
        self::assertSame([], self::of($result['events'], 'models'));
        self::assertSame($before, file_get_contents($this->harness->configFile));
        self::assertFileDoesNotExist($this->harness->configFile . '.bak');
    }

    public function testAProfileNameAlreadyInTheConfigIsRefused(): void
    {
        $before = file_get_contents($this->harness->configFile);

        $result = $this->play([self::addCommand('local')], new AnyProfileProvider());

        self::assertSame(['error'], array_column(self::of($result['events'], 'notice'), 'level'));
        self::assertSame("profile 'local' already exists in {$this->harness->configFile}", self::first($result['events'], 'notice')['message']);
        self::assertSame([], self::of($result['events'], 'models'));
        self::assertSame($before, file_get_contents($this->harness->configFile));
    }

    public function testAUserNameAndPasswordInTheUrlAreRefusedAndNeverEchoed(): void
    {
        $before = file_get_contents($this->harness->configFile);

        $result = $this->play([self::addCommand('remote', 'https://user:hunter2@models.example/v1')], new AnyProfileProvider());

        $notice = self::first($result['events'], 'notice');
        self::assertSame(['error', 'base_url must not contain a user name or a password'], [$notice['level'], $notice['message']]);
        self::assertSame([], self::of($result['events'], 'models'));
        self::assertStringNotContainsString('hunter2', (string) json_encode($result['events'], JSON_THROW_ON_ERROR));
        self::assertSame($before, file_get_contents($this->harness->configFile));
        self::assertFileDoesNotExist($this->harness->configFile . '.bak');
    }

    public function testAKeyGivenInsteadOfItsVariableNameIsRefusedAndNeverEchoed(): void
    {
        $key = 'sk-test-SECRET-123456';

        $result = $this->play([self::addCommand('remote', 'http://127.0.0.1:9', 'openai', $key)], new AnyProfileProvider());

        self::assertSame(['error'], array_column(self::of($result['events'], 'notice'), 'level'));
        self::assertStringContainsString('api_key_env must be the name of an environment variable', self::first($result['events'], 'notice')['message']);
        self::assertSame([], self::of($result['events'], 'models'));
        self::assertStringNotContainsString($key, (string) json_encode($result['events'], JSON_THROW_ON_ERROR));
        self::assertFileDoesNotExist($this->harness->configFile . '.bak');
    }

    public function testACommandWithAMissingOrWrongFieldIsANoticeAndChangesNothing(): void
    {
        $before = file_get_contents($this->harness->configFile);

        $result = $this->play([
            ['type' => 'add_provider', 'protocol' => 'ollama', 'base_url' => 'http://127.0.0.1:9', 'api_key_env' => null, 'model' => 'mistral'],
            ['type' => 'add_provider', 'name' => 'remote', 'protocol' => 'ollama', 'base_url' => 'http://127.0.0.1:9', 'api_key_env' => 7, 'model' => 'mistral'],
        ], new AnyProfileProvider());

        $notices = self::of($result['events'], 'notice');
        self::assertSame(['error', 'error'], array_column($notices, 'level'));
        self::assertSame('add_provider needs a name, a protocol, a base_url, an api_key_env (or null) and a model (or null)', $notices[0]['message']);
        self::assertSame($notices[0]['message'], $notices[1]['message']);
        self::assertSame([], self::of($result['events'], 'models'));
        self::assertSame($before, file_get_contents($this->harness->configFile));
    }

    public function testAProviderWithoutAModelIsSavedAndListedForTheModelToBeChosen(): void
    {
        $result = $this->play([self::addCommand('remote', 'http://127.0.0.1:9', 'ollama', null, null)], new AnyProfileProvider());

        self::assertSame([], self::of($result['events'], 'notice'));
        self::assertSame(['ready', 'models', 'bye'], self::types($result['events']));
        $saved = json_decode((string) file_get_contents($this->harness->configFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('model', $saved['profiles']['remote']);
    }

    public function testAProviderSavedWhileTheConfigCannotBeReadAgainIsReportedAndStillAnswered(): void
    {
        $result = $this->play([self::addCommand()], new AnyProfileProvider(), function (): void {
            file_put_contents($this->harness->configFile, json_encode(['default_profile' => 'local', 'permissions' => ['mode' => 'bogus'], 'profiles' => [
                'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
            ]], JSON_THROW_ON_ERROR));
        });

        self::assertSame(['ready', 'notice', 'models', 'bye'], self::types($result['events']));
        $notice = self::first($result['events'], 'notice');
        self::assertSame('error', $notice['level']);
        self::assertStringStartsWith('the provider is saved, but the configuration cannot be read again; restart phycode to use it: ', $notice['message']);
        self::assertArrayHasKey('remote', json_decode((string) file_get_contents($this->harness->configFile), true, 512, JSON_THROW_ON_ERROR)['profiles']);
    }

    public function testAddProviderReadDuringATurnIsRefusedAndChangesNothing(): void
    {
        $spy = new RequestSpy(['local' => 'qwen'], [], [$this->endingInput(), ScriptedClient::text('answer')]);
        $before = file_get_contents($this->harness->configFile);

        $result = $this->play([
            ['type' => 'input', 'text' => 'question'],
            self::addCommand(),
            ['type' => 'custom_model', 'profile' => 'local', 'model' => 'qwen-custom', 'action' => 'add'],
        ], $spy);

        $refusal = ['type' => 'notice', 'level' => 'warn', 'message' => 'a turn is running; try again when it finishes'];
        self::assertSame(['ready', 'turn_start', 'notice', 'notice', 'text', 'turn_end', 'bye'], self::types($result['events']));
        self::assertSame([$refusal, $refusal], array_values(self::of($result['events'], 'notice')));
        self::assertSame([], self::of($result['events'], 'models'));
        self::assertSame($before, file_get_contents($this->harness->configFile));
        self::assertFileDoesNotExist($this->state);
    }

    public function testACustomModelIsAddedAndRemovedInTheStateFileAndShownInTheModels(): void
    {
        $before = file_get_contents($this->harness->configFile);

        $added = $this->play([
            ['type' => 'custom_model', 'profile' => 'local', 'model' => ' qwen-custom ', 'action' => 'add'],
            ['type' => 'custom_model', 'profile' => 'local', 'model' => 'qwen-custom', 'action' => 'add'],
        ], new AnyProfileProvider());

        self::assertSame([['profile' => 'local', 'model' => 'qwen-custom']], $this->customInState());
        $models = self::of($added['events'], 'models');
        self::assertCount(2, $models);
        self::assertSame(['local-model', 'qwen-custom'], array_column($models[1]['entries'], 'model'));
        self::assertSame([false, true], array_column($models[1]['entries'], 'custom'));
        self::assertSame($before, file_get_contents($this->harness->configFile), 'the config is not written');

        $removed = $this->play([
            ['type' => 'custom_model', 'profile' => 'local', 'model' => 'qwen-custom', 'action' => 'remove'],
        ], new AnyProfileProvider());

        self::assertSame([], $this->customInState());
        self::assertSame(['local-model'], array_column(self::first($removed['events'], 'models')['entries'], 'model'));
    }

    public function testACustomModelForAnUnknownProfileOrAnInvalidIdIsANoticeAndChangesNothing(): void
    {
        $result = $this->play([
            ['type' => 'custom_model', 'profile' => 'nowhere', 'model' => 'x', 'action' => 'add'],
            ['type' => 'custom_model', 'profile' => 'local', 'model' => 'bad id', 'action' => 'add'],
            ['type' => 'custom_model', 'profile' => 'local', 'model' => 'x', 'action' => 'replace'],
            ['type' => 'custom_model', 'profile' => 'local', 'model' => 5, 'action' => 'add'],
        ], new AnyProfileProvider());

        $notices = self::of($result['events'], 'notice');
        self::assertSame(['error', 'error', 'error', 'error'], array_column($notices, 'level'));
        self::assertStringContainsString("unknown profile 'nowhere'", $notices[0]['message']);
        self::assertSame('a custom model id may use letters, digits and . _ : / @ + -, and is 1 to 160 characters long', $notices[1]['message']);
        self::assertSame('custom_model needs a profile, a model and an action (add or remove)', $notices[2]['message']);
        self::assertSame($notices[2]['message'], $notices[3]['message']);
        self::assertSame([], self::of($result['events'], 'models'));
        self::assertFileDoesNotExist($this->state);
    }

    private static function addCommand(
        string $name = 'remote',
        string $baseUrl = 'http://127.0.0.1:9',
        ?string $protocol = 'ollama',
        ?string $apiKeyEnv = null,
        ?string $model = 'mistral',
    ): array {
        return ['type' => 'add_provider', 'name' => $name, 'protocol' => $protocol, 'base_url' => $baseUrl, 'api_key_env' => $apiKeyEnv, 'model' => $model];
    }

    private function customInState(): array
    {
        return json_decode((string) file_get_contents($this->state), true, 512, JSON_THROW_ON_ERROR)['custom'] ?? [];
    }
}

final class AnyProfileProvider implements ClientProvider
{
    public array $asked = [];

    public function __construct(private readonly array $listings = [])
    {
    }

    public function forProfile(string $name, ?string $model = null, ?string $thinking = null): ChatClient
    {
        return new class ($this, $name) implements ChatClient {
            public function __construct(private readonly AnyProfileProvider $provider, private readonly string $name)
            {
            }

            public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
            {
                throw new \LogicException('these tests do not chat');
            }

            public function listModels(): array
            {
                return $this->provider->listing($this->name);
            }

            public function describe(string $model): ModelInfo
            {
                return new ModelInfo($model, $this->name, 'openai');
            }
        };
    }

    public function toolMode(string $name, ?string $model = null, ?string $thinking = null): string
    {
        return 'text';
    }

    public function listing(string $profile): array
    {
        $this->asked[] = $profile;

        return $this->listings[$profile] ?? [new ModelInfo("{$profile}-model", $profile, 'openai')];
    }
}
