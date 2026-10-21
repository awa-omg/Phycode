<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ollama;

use Phycode\Config\Profile;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\Ollama\OllamaClient;
use Phycode\Tests\Support\OllamaServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OllamaRequestTest extends TestCase
{
    private const MESSAGES = [
        ['role' => 'system', 'content' => 'You are concise.'],
        ['role' => 'user', 'content' => 'Say hello.'],
    ];

    private ?OllamaServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        putenv('PHYCODE_TEST_KEY');
    }

    public function testTheDefaultProfileSendsTheDocumentedFields(): void
    {
        $body = $this->send([]);

        self::assertEquals([
            'model' => 'qwen3:4b',
            'messages' => self::MESSAGES,
            'stream' => true,
            'think' => false,
            'options' => [
                'num_predict' => 2048,
                'num_ctx' => 16384,
                'temperature' => 0.6,
                'top_p' => 0.95,
                'top_k' => 20,
                'min_p' => 0.0,
                'presence_penalty' => 0.0,
            ],
            'keep_alive' => '5m',
        ], $body);
    }

    public function testTheRequestForTheRecordedPlainAnswerIsTheRecordedRequest(): void
    {
        $server = $this->serve();

        (new OllamaClient($server->profile('chat', ['model' => 'gemma4:e2b'])))->chat(
            [['role' => 'user', 'content' => 'di hola en una palabra']],
            [],
            new ChatOptions(),
        );

        $recorded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/ollama/live/requests/chat-text.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($recorded, $server->requests()[0]['json']);
    }

    public static function thinkingSettingsOfAModelWithoutLevels(): iterable
    {
        yield 'none' => ['none', false];
        yield 'off, the old name of none' => ['off', false];
        yield 'minimal' => ['minimal', true];
        yield 'low' => ['low', true];
        yield 'on, the old name of medium' => ['on', true];
        yield 'high' => ['high', true];
        yield 'xhigh' => ['xhigh', true];
        yield 'max' => ['max', true];
    }

    #[DataProvider('thinkingSettingsOfAModelWithoutLevels')]
    public function testAModelThatNamesNoLevelTakesTheSwitch(string $thinking, bool|string $expected): void
    {
        self::assertSame($expected, $this->send(['thinking' => $thinking])['think']);
    }

    public static function thinkingSettingsOfAModelThatNamesItsLevels(): iterable
    {
        yield 'none' => ['none', false];
        yield 'off, the old name of none' => ['off', false];
        yield 'minimal falls to low' => ['minimal', 'low'];
        yield 'low, which the model names' => ['low', 'low'];
        yield 'on is medium, which the model names' => ['on', 'medium'];
        yield 'medium' => ['medium', 'medium'];
        yield 'high, which the model names' => ['high', 'high'];
        yield 'xhigh falls to high' => ['xhigh', 'high'];
        yield 'max falls to high' => ['max', 'high'];
    }

    #[DataProvider('thinkingSettingsOfAModelThatNamesItsLevels')]
    public function testAModelThatNamesItsLevelsGetsTheNameEachLevelFallsTo(string $thinking, bool|string $expected): void
    {
        $body = $this->sendTo(
            ['thinking' => $thinking],
            ['/api/show' => [['body' => '{"capabilities":["completion","thinking"],"thinking":{"values":["low","medium","high"],"default":"medium"}}', 'type' => 'application/json']]],
        );

        self::assertSame($expected, $body['think']);
    }

    public function testAModelWhoseShowAnswerFailsGetsTheSwitch(): void
    {
        $body = $this->sendTo(
            ['thinking' => 'high'],
            ['/api/show' => [['status' => 500, 'body' => '{"error":"runner crashed"}', 'type' => 'application/json']]],
        );

        self::assertTrue($body['think'], 'the chat is still sent, with the switch alone');
    }

    public function testTheValuesOfAModelWithoutNamedLevelsAreTheSwitch(): void
    {
        $body = $this->sendTo(
            ['thinking' => 'medium'],
            ['/api/show' => [['body' => '{"capabilities":["completion","thinking"],"thinking":{"values":[false,true],"default":true}}', 'type' => 'application/json']]],
        );

        self::assertTrue($body['think'], 'a list of booleans names no level');
    }

    public function testTheContextLimitBecomesNumCtxAndTheOverridesReplaceTheSampling(): void
    {
        $server = $this->serve();
        $profile = $server->profile('chat', ['context_limit' => 8192]);

        (new OllamaClient($profile))->chat(self::MESSAGES, [], new ChatOptions(overrides: ['temperature' => 0.0]));

        $options = $server->requests()[0]['json']['options'];
        self::assertSame(8192, $options['num_ctx']);
        self::assertEquals(0.0, $options['temperature']);
        self::assertSame(0.95, $options['top_p'], 'the other sampling values stay');
    }

    public function testAProfileWithoutAContextLimitSendsNoNumCtx(): void
    {
        $server = $this->serve();
        $profile = new Profile(
            name: 'test',
            baseUrl: $server->url('chat'),
            model: 'qwen3:4b',
            toolMode: 'auto',
            contextLimit: 0,
            maxOutputTokens: 64,
            sampling: [],
            extraBody: [],
            apiKeyFile: null,
            apiKeyEnv: null,
            protocol: 'ollama',
        );

        (new OllamaClient($profile))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame(['num_predict' => 64], $server->requests()[0]['json']['options']);
    }

    public static function keepAliveValues(): iterable
    {
        yield 'a duration' => ['5m', '5m'];
        yield 'seconds as a duration' => ['30s', '30s'];
        yield 'an hour' => ['1h', '1h'];
        yield 'unload now' => ['0', 0];
        yield 'keep loaded' => ['-1', -1];
        yield 'a count of seconds' => ['300', 300];
        yield 'a fraction of seconds' => ['1.5', 1.5];
    }

    #[DataProvider('keepAliveValues')]
    public function testKeepAliveIsSentAsADurationOrAsANumberOfSeconds(string $configured, int|float|string $expected): void
    {
        self::assertSame($expected, $this->send(['keep_alive' => $configured])['keep_alive']);
    }

    public function testToolsGoOutWithTheirSchemaAndNoToolChoice(): void
    {
        $read = ['type' => 'function', 'function' => ['name' => 'read', 'description' => 'Reads a file.', 'parameters' => ['type' => 'object', 'required' => ['path'], 'properties' => ['path' => ['type' => 'string']]]]];
        $time = ['type' => 'function', 'function' => ['name' => 'time', 'description' => 'The time.', 'parameters' => ['type' => 'object', 'properties' => []]]];
        $server = $this->serve();

        (new OllamaClient($server->profile('chat')))->chat(self::MESSAGES, [$read, $time], new ChatOptions());

        $request = $server->requests()[0];
        self::assertSame($read, $request['json']['tools'][0]);
        self::assertSame('time', $request['json']['tools'][1]['function']['name']);
        self::assertStringContainsString('"properties":{}', $request['body'], 'a tool without parameters has an object, not []');
        self::assertArrayNotHasKey('tool_choice', $request['json']);
        self::assertArrayNotHasKey('parallel_tool_calls', $request['json']);
    }

    public function testExtraBodyGoesLastAndItsOptionsMergeIntoOurs(): void
    {
        $body = $this->send([
            'thinking' => 'on',
            'extra_body' => ['options' => ['repeat_penalty' => 1.1, 'top_k' => 40], 'keep_alive' => -1],
        ]);

        self::assertSame(1.1, $body['options']['repeat_penalty']);
        self::assertSame(40, $body['options']['top_k'], 'extra_body replaces a sampling value');
        self::assertSame(2048, $body['options']['num_predict'], 'the options it does not name are kept');
        self::assertSame(16384, $body['options']['num_ctx']);
        self::assertSame(-1, $body['keep_alive']);
        self::assertTrue($body['think'], 'the thinking switch is ours unless extra_body names it');
    }

    public function testTheAuthorizationHeaderIsSentOnlyWhenAKeyIsSet(): void
    {
        putenv('PHYCODE_TEST_KEY=secret-key-for-tests');
        $server = $this->serve();

        (new OllamaClient($server->profile('chat', ['api_key_env' => 'PHYCODE_TEST_KEY'])))->chat(self::MESSAGES, [], new ChatOptions());
        self::assertSame('Bearer secret-key-for-tests', $server->requests()[0]['authorization']);

        putenv('PHYCODE_TEST_KEY');
        (new OllamaClient($server->profile('chat', ['api_key_env' => 'PHYCODE_TEST_KEY'])))->chat(self::MESSAGES, [], new ChatOptions());
        self::assertNull($server->requests()[1]['authorization']);
    }

    public function testTheKeyIsNotSentToARedirectTarget(): void
    {
        putenv('PHYCODE_TEST_KEY=secret-key-for-tests');
        $this->serve(
            ['status' => 307, 'redirect' => 'elsewhere'],
            ['elsewhere' => ['/api/chat' => [['fixture' => 'live/chat-text.ndjson']]]],
        );

        try {
            (new OllamaClient($this->server->profile('chat', ['api_key_env' => 'PHYCODE_TEST_KEY'])))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('a redirect is not an answer');
        } catch (LlmException $error) {
            self::assertStringStartsWith('HTTP 307', $error->getMessage());
        }
        self::assertSame(['chat'], array_values(array_unique(array_column($this->server->requests(), 'scenario'))));
    }

    public function testTheRequestGoesToTheChatPathBelowTheBaseUrl(): void
    {
        $server = $this->serve();

        (new OllamaClient($server->profile('chat')))->chat(self::MESSAGES, [], new ChatOptions());

        $request = $server->requests()[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('/chat/api/chat', $request['path']);
    }

    private function send(array $fields): array
    {
        return $this->sendTo($fields, []);
    }

    private function sendTo(array $fields, array $paths): array
    {
        $this->server?->stop();
        $this->server = new OllamaServer(['chat' => ['/api/chat' => [['fixture' => 'live/chat-text.ndjson']]] + $paths]);
        (new OllamaClient($this->server->profile('chat', $fields)))->chat(self::MESSAGES, [], new ChatOptions());

        $chats = array_values(array_filter($this->server->requests(), static fn (array $request): bool => str_ends_with($request['path'], '/api/chat')));
        self::assertCount(1, $chats);

        return $chats[0]['json'];
    }

    private function serve(?array $steps = null, array $others = []): OllamaServer
    {
        $this->server?->stop();
        $chat = $steps === null ? [['fixture' => 'live/chat-text.ndjson']] : [$steps];

        return $this->server = new OllamaServer(['chat' => ['/api/chat' => $chat]] + $others);
    }
}
