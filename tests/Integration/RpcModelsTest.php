<?php

declare(strict_types=1);

namespace Phycode\Tests\Integration;

use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\OllamaServer;
use Phycode\Tests\Support\RpcProcess;
use PHPUnit\Framework\TestCase;

final class RpcModelsTest extends TestCase
{
    private string $home;
    private string $cwd;
    private ?OllamaServer $server = null;
    private ?RpcProcess $rpc = null;

    protected function setUp(): void
    {
        $this->home = Kit::folder('rpc-models-home');
        $this->cwd = Kit::folder('rpc-models-cwd');
    }

    protected function tearDown(): void
    {
        $this->rpc?->close();
        $this->server?->stop();
    }

    public function testTheModelListAFavoriteAndASwitchWithThinkingOnReachTheNextRequest(): void
    {
        $this->server = new OllamaServer(['local' => [
            '/api/tags' => [['type' => 'application/json', 'body' => json_encode(['models' => [
                ['name' => 'qwen3:4b', 'capabilities' => ['completion', 'tools']],
                ['name' => 'llama3.2:3b', 'capabilities' => ['completion']],
            ]], JSON_THROW_ON_ERROR)]],
            '/api/chat' => [['fixture' => 'live/chat-text.ndjson']],
        ]]);
        $configFile = $this->home . '/config/phycode/config.json';
        Kit::write($configFile, json_encode(['default_profile' => 'local', 'profiles' => ['local' => [
            'base_url' => $this->server->url('local'),
            'model' => 'qwen3:4b',
            'protocol' => 'ollama',
            'tool_mode' => 'text',
        ]]], JSON_THROW_ON_ERROR));
        $config = (string) file_get_contents($configFile);

        $this->rpc = RpcProcess::start($this->home, $this->cwd);
        $ready = $this->rpc->until('ready', 20.0);
        self::assertNotNull($ready, 'the backend did not start');
        self::assertSame(['model' => 'qwen3:4b', 'thinking' => 'none'], ['model' => $ready[0]['app']['model'], 'thinking' => $ready[0]['app']['thinking']]);

        $this->rpc->send(['type' => 'list_models', 'refresh' => true]);
        $models = $this->last($this->rpc->until('models', 20.0));
        self::assertSame(['qwen3:4b', 'llama3.2:3b'], array_column($models['entries'], 'model'));
        self::assertSame(['profile' => 'local', 'model' => 'qwen3:4b', 'thinking' => 'none'], $models['current']);

        $this->rpc->send(['type' => 'favorite', 'profile' => 'local', 'model' => 'llama3.2:3b', 'on' => true]);
        $favorited = $this->last($this->rpc->until('models', 20.0));
        self::assertSame([['profile' => 'local', 'model' => 'llama3.2:3b']], $favorited['favorites']);
        self::assertFileExists($this->home . '/.local/state/phycode/state.json', 'favorites go to the state folder of this home');

        $this->rpc->send(['type' => 'set_model', 'profile' => 'local', 'model' => 'llama3.2:3b', 'thinking' => 'on', 'save' => false]);
        self::assertSame(
            ['type' => 'model_changed', 'profile' => 'local', 'model' => 'llama3.2:3b', 'thinking' => 'medium', 'saved' => false],
            $this->last($this->rpc->until('model_changed', 20.0)),
        );

        $this->rpc->send(['type' => 'input', 'text' => 'name the three primary colours']);
        $events = $this->rpc->until('turn_end', 20.0);
        self::assertNotNull($events, 'the turn did not end');
        $turn = $this->last($events);
        self::assertSame('completed', $turn['status']);
        self::assertSame([14, 4], [$turn['usage']['promptTokens'], $turn['usage']['completionTokens']]);

        $this->quit();

        self::assertSame($config, file_get_contents($configFile), 'set_model without save leaves the config alone');
        $chats = array_values(array_filter(
            $this->server->requests(),
            static fn (array $request): bool => str_ends_with($request['path'], '/api/chat'),
        ));
        self::assertCount(1, $chats);
        self::assertSame(['llama3.2:3b', true], [$chats[0]['json']['model'], $chats[0]['json']['think']]);
    }

    public function testAProviderAddedInTheSessionIsListedAtOnceAndTheConfigKeepsItsBackup(): void
    {
        $this->server = new OllamaServer([
            'local' => ['/api/tags' => [['type' => 'application/json', 'body' => json_encode(['models' => [['name' => 'qwen3:4b']]], JSON_THROW_ON_ERROR)]]],
            'remote' => ['/api/tags' => [['type' => 'application/json', 'body' => json_encode(['models' => [['name' => 'llama3.2:3b'], ['name' => 'mistral:7b']]], JSON_THROW_ON_ERROR)]]],
        ]);
        $configFile = $this->writeConfig();
        $config = (string) file_get_contents($configFile);

        $this->rpc = RpcProcess::start($this->home, $this->cwd);
        self::assertNotNull($this->rpc->until('ready', 20.0), 'the backend did not start');

        $this->rpc->send(['type' => 'add_provider', 'name' => 'remote', 'protocol' => 'ollama', 'base_url' => $this->server->url('remote'), 'api_key_env' => null, 'model' => 'llama3.2:3b']);
        $models = $this->last($this->rpc->until('models', 20.0));
        $port = parse_url($this->server->url('remote'), PHP_URL_PORT);
        self::assertSame(['local', 'remote'], array_column($models['providers'], 'profile'));
        self::assertSame(['http://127.0.0.1:' . $port, 2, null], [$models['providers'][1]['endpoint'], $models['providers'][1]['count'], $models['providers'][1]['error']]);
        self::assertSame(['qwen3:4b', 'llama3.2:3b', 'mistral:7b'], array_column($models['entries'], 'model'));

        $this->quit();

        self::assertSame($config, file_get_contents($configFile . '.bak'), 'the file as it was is kept');
        $expected = json_decode($config, true, 512, JSON_THROW_ON_ERROR);
        $expected['profiles']['remote'] = ['base_url' => $this->server->url('remote'), 'protocol' => 'ollama', 'model' => 'llama3.2:3b'];
        self::assertSame($expected, json_decode((string) file_get_contents($configFile), true, 512, JSON_THROW_ON_ERROR), 'every other key keeps its value and its place');
    }

    public function testACustomModelIsKeptInTheStateOfThisHomeAndListedAtOnce(): void
    {
        $this->server = new OllamaServer(['local' => ['/api/tags' => [['type' => 'application/json', 'body' => json_encode(['models' => [['name' => 'qwen3:4b']]], JSON_THROW_ON_ERROR)]]]]);
        $configFile = $this->writeConfig();
        $config = (string) file_get_contents($configFile);
        $state = $this->home . '/.local/state/phycode/state.json';

        $this->rpc = RpcProcess::start($this->home, $this->cwd);
        self::assertNotNull($this->rpc->until('ready', 20.0), 'the backend did not start');

        $this->rpc->send(['type' => 'custom_model', 'profile' => 'local', 'model' => 'qwen3:8b', 'action' => 'add']);
        $added = $this->last($this->rpc->until('models', 20.0));
        self::assertSame([['qwen3:4b', false], ['qwen3:8b', true]], array_map(static fn (array $entry): array => [$entry['model'], $entry['custom']], $added['entries']));
        self::assertSame([['profile' => 'local', 'model' => 'qwen3:8b']], json_decode((string) file_get_contents($state), true, 512, JSON_THROW_ON_ERROR)['custom']);

        $this->rpc->send(['type' => 'custom_model', 'profile' => 'local', 'model' => 'qwen3:8b', 'action' => 'remove']);
        $removed = $this->last($this->rpc->until('models', 20.0));
        self::assertSame(['qwen3:4b'], array_column($removed['entries'], 'model'));
        self::assertSame([], json_decode((string) file_get_contents($state), true, 512, JSON_THROW_ON_ERROR)['custom']);

        $this->quit();

        self::assertSame($config, file_get_contents($configFile), 'custom model ids are not written to the config');
    }

    private function writeConfig(): string
    {
        $configFile = $this->home . '/config/phycode/config.json';
        Kit::write($configFile, json_encode(['default_profile' => 'local', 'profiles' => ['local' => [
            'base_url' => $this->server->url('local'),
            'model' => 'qwen3:4b',
            'protocol' => 'ollama',
            'tool_mode' => 'text',
        ]]], JSON_THROW_ON_ERROR));

        return $configFile;
    }

    private function quit(): void
    {
        $this->rpc->send(['type' => 'quit']);
        $events = $this->rpc->until('bye', 20.0);
        self::assertNotNull($events, 'the backend did not end');
        self::assertSame(['type' => 'bye', 'reason' => 'quit'], $this->last($events));
        self::assertSame(0, $this->rpc->close());
        $this->rpc = null;
    }

    private function last(array $events): array
    {
        return $events[array_key_last($events)];
    }
}
