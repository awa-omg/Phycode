<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Anthropic;

use Phycode\Llm\Anthropic\AnthropicClient;
use Phycode\Llm\ChatOptions;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnthropicRequestTest extends TestCase
{
    private const WEATHER = [[
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
            'description' => 'Get the current weather for a city.',
            'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string', 'description' => 'The city name']], 'required' => ['city']],
        ],
    ]];

    private ?ProtocolServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        putenv('PHYCODE_TEST_ANTHROPIC_KEY');
    }

    public function testTheKeyTheVersionAndTheBetasAreSentAsTheirHeaders(): void
    {
        putenv('PHYCODE_TEST_ANTHROPIC_KEY=sk-ant-test');
        $server = $this->serve('anthropic/text.sse');
        $client = new AnthropicClient($server->profile('chat', [
            'protocol' => 'anthropic',
            'api_key_env' => 'PHYCODE_TEST_ANTHROPIC_KEY',
            'anthropic_version' => '2024-10-22',
            'anthropic_beta' => ['prompt-caching-2024-07-31', 'output-128k-2025-02-19'],
        ]));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        $headers = $server->requests('POST')[0]['headers'];
        self::assertSame(['sk-ant-test', '2024-10-22', 'prompt-caching-2024-07-31,output-128k-2025-02-19'], [$headers['x-api-key'], $headers['anthropic-version'], $headers['anthropic-beta']]);
        self::assertSame(['application/json', 'text/event-stream'], [$headers['content-type'], $headers['accept']]);
        self::assertNull($headers['authorization'], 'Anthropic takes the key in x-api-key, not in a bearer header');
    }

    public function testWithoutBetasThereIsNoBetaHeaderAndTheVersionIsTheDefault(): void
    {
        $server = $this->serve('anthropic/text.sse');
        $client = new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic']));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        $headers = $server->requests('POST')[0]['headers'];
        self::assertSame(['2023-06-01', null, null], [$headers['anthropic-version'], $headers['anthropic-beta'], $headers['x-api-key']]);
    }

    public function testTheBodyHasTheModelTheLimitTheSystemTextTheMessagesAndTheToolsAndThinkingOff(): void
    {
        $server = $this->serve('anthropic/text.sse');
        $client = new AnthropicClient($server->profile('chat', [
            'protocol' => 'anthropic',
            'prompt_cache' => false,
            'model' => 'claude-haiku-5-5',
            'max_output_tokens' => 512,
            'sampling' => ['temperature' => 0.2, 'top_p' => 0.9, 'top_k' => 40, 'min_p' => 0.1, 'presence_penalty' => 0.5],
        ]));

        $client->chat(
            [['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'Hi?']],
            self::WEATHER,
            new ChatOptions(overrides: ['temperature' => 0.0]),
        );

        $body = $server->requests('POST')[0]['json'];
        self::assertSame(['model', 'max_tokens', 'messages', 'stream', 'system', 'tools', 'thinking', 'temperature', 'top_p', 'top_k'], array_keys($body));
        self::assertSame(['type' => 'disabled'], $body['thinking'], 'none is no reasoning: Haiku 5.5 thinks by default, so it is turned off');
        self::assertSame(['claude-haiku-5-5', 512, true, 'Be brief.'], [$body['model'], $body['max_tokens'], $body['stream'], $body['system']]);
        self::assertSame([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi?']]]], $body['messages']);
        self::assertEquals(0.0, $body['temperature'], 'the override wins over the profile (JSON writes 0.0 as 0)');
        self::assertSame([0.9, 40], [$body['top_p'], $body['top_k']]);
        self::assertSame('get_weather', $body['tools'][0]['name']);
        self::assertSame('object', $body['tools'][0]['input_schema']['type']);
    }

    public function testSamplingValuesThatAnthropicDoesNotKnowAreNotSent(): void
    {
        $server = $this->serve('anthropic/text.sse');
        $client = new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'sampling' => ['min_p' => 0.1, 'presence_penalty' => 0.5]]));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        self::assertArrayNotHasKey('min_p', $server->requests('POST')[0]['json']);
        self::assertArrayNotHasKey('presence_penalty', $server->requests('POST')[0]['json']);
    }

    public function testThinkingOnIsMediumEffortWithAdaptiveThinkingAndNoSamplingValues(): void
    {
        $server = $this->serve('anthropic/text.sse');
        $client = new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'thinking' => 'on']));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions(overrides: ['temperature' => 0.0]));

        $body = $server->requests('POST')[0]['json'];
        self::assertSame(['type' => 'adaptive'], $body['thinking']);
        self::assertSame(['effort' => 'medium'], $body['output_config'], 'on is medium, the level it stood for');
        self::assertArrayNotHasKey('temperature', $body, 'a changed temperature is refused with thinking');
        self::assertArrayNotHasKey('top_k', $body);
    }

    public static function levelsOfClaudeModels(): iterable
    {
        yield 'haiku 5.5 at none turns thinking off' => ['claude-haiku-5-5', 'none', ['type' => 'disabled'], null];
        yield 'haiku 5.5 with the old off' => ['claude-haiku-5-5', 'off', ['type' => 'disabled'], null];
        yield 'haiku 5.5 at minimal is the lowest effort' => ['claude-haiku-5-5', 'minimal', ['type' => 'adaptive'], 'low'];
        yield 'haiku 5.5 at low' => ['claude-haiku-5-5', 'low', ['type' => 'adaptive'], 'low'];
        yield 'haiku 5.5 with the old on is medium' => ['claude-haiku-5-5', 'on', ['type' => 'adaptive'], 'medium'];
        yield 'haiku 5.5 at high' => ['claude-haiku-5-5', 'high', ['type' => 'adaptive'], 'high'];
        yield 'haiku 5.5 at xhigh' => ['claude-haiku-5-5', 'xhigh', ['type' => 'adaptive'], 'xhigh'];
        yield 'haiku 5.5 at max' => ['claude-haiku-5-5', 'max', ['type' => 'adaptive'], 'max'];
        yield 'sonnet 4.6 has no xhigh, so it falls to high' => ['claude-sonnet-4-6', 'xhigh', ['type' => 'adaptive'], 'high'];
        yield 'sonnet 4.6 at max' => ['claude-sonnet-4-6', 'max', ['type' => 'adaptive'], 'max'];
        yield 'opus 4.5 thinks on a budget, not adaptively: max takes the high budget, which half of max_tokens 2048 keeps at 1024' => ['claude-opus-4-5-20251101', 'max', ['type' => 'enabled', 'budget_tokens' => 1024], 'high'];
        yield 'opus 5.5 cannot turn thinking off, so none is the lowest effort' => ['claude-opus-5-5', 'none', ['type' => 'adaptive'], 'low'];
        yield 'sonnet 5.5 turns up-front thinking off with between_tools' => ['claude-sonnet-5-5', 'none', ['type' => 'between_tools'], null];
        yield 'a model the table does not list takes the level as it is' => ['some-future-model', 'xhigh', ['type' => 'adaptive'], 'xhigh'];
    }

    #[DataProvider('levelsOfClaudeModels')]
    public function testEachLevelIsSentAsTheThinkingAndTheEffortThatTheModelTakes(string $model, string $thinking, array $expectedThinking, ?string $effort): void
    {
        $server = $this->serve('anthropic/text.sse');
        $client = new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'model' => $model, 'thinking' => $thinking]));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        $body = $server->requests('POST')[0]['json'];
        self::assertSame($expectedThinking, $body['thinking']);
        if ($effort === null) {
            self::assertArrayNotHasKey('output_config', $body);
        } else {
            self::assertSame(['effort' => $effort], $body['output_config']);
        }
        self::assertSame($expectedThinking['type'] === 'disabled', array_key_exists('temperature', $body), 'the sampling values go only with thinking off');
    }

    public function testAThinkingLevelAlsoSendsTheEffort(): void
    {
        $server = $this->serve('anthropic/text.sse');
        $client = new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'thinking' => 'high']));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        self::assertSame(['type' => 'adaptive'], $server->requests('POST')[0]['json']['thinking']);
        self::assertSame(['effort' => 'high'], $server->requests('POST')[0]['json']['output_config']);
    }

    public function testTheExtraBodyComesLastAndCanSetAnyKey(): void
    {
        $server = $this->serve('anthropic/text.sse');
        $client = new AnthropicClient($server->profile('chat', [
            'protocol' => 'anthropic',
            'max_output_tokens' => 512,
            'extra_body' => ['max_tokens' => 99, 'metadata' => ['user_id' => 'u1']],
        ]));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        self::assertSame(99, $server->requests('POST')[0]['json']['max_tokens']);
        self::assertSame(['user_id' => 'u1'], $server->requests('POST')[0]['json']['metadata']);
    }

    public function testTheRequestOfTheRecordedTextReplyIsTheOneTheClientBuilds(): void
    {
        $server = $this->serve('anthropic/text.sse');
        $client = new AnthropicClient($server->profile('chat', [
            'protocol' => 'anthropic',
            'prompt_cache' => false,
            'model' => 'qwen35-2b',
            'max_output_tokens' => 256,
            'extra_body' => ['chat_template_kwargs' => ['enable_thinking' => false]],
        ]));

        $client->chat(
            [['role' => 'system', 'content' => 'Answer with one word.'], ['role' => 'user', 'content' => 'What colour is a clear daytime sky? One word.']],
            [],
            new ChatOptions(),
        );

        $recorded = json_decode(ProtocolServer::fixture('anthropic/text.request.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($recorded + ['thinking' => ['type' => 'disabled']], $server->requests('POST')[0]['json']);
    }

    public function testTheRequestOfTheRecordedToolRoundIsTheOneTheClientBuilds(): void
    {
        $server = $this->serve('anthropic/tool-result.sse');
        $client = new AnthropicClient($server->profile('chat', [
            'protocol' => 'anthropic',
            'prompt_cache' => false,
            'model' => 'qwen35-2b',
            'max_output_tokens' => 256,
            'extra_body' => ['chat_template_kwargs' => ['enable_thinking' => false]],
        ]));
        $messages = [
            ['role' => 'system', 'content' => 'Use the provided tool when it helps. Do not answer from memory.'],
            ['role' => 'user', 'content' => 'What is the weather in Madrid right now? Use the get_weather tool.'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'YlVY31afpQfKkl5HMD8in75VFMkYwtZQ', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Madrid"}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'YlVY31afpQfKkl5HMD8in75VFMkYwtZQ', 'content' => '{"city":"Madrid","condition":"clear","temperature_c":21}'],
        ];

        $client->chat($messages, self::WEATHER, new ChatOptions());

        $recorded = json_decode(ProtocolServer::fixture('anthropic/tool-result.request.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($recorded + ['thinking' => ['type' => 'disabled']], $server->requests('POST')[0]['json']);
    }

    public function testTheRequestOfTheRecordedThinkingReplyIsTheOneTheClientBuilds(): void
    {
        $server = $this->serve('anthropic/thinking.sse');
        $client = new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'prompt_cache' => false, 'model' => 'qwen35-2b', 'thinking' => 'on', 'max_output_tokens' => 1024]));

        $client->chat(
            [['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'What is 17 times 23? Reply with the number only.']],
            [],
            new ChatOptions(),
        );

        $recorded = json_decode(ProtocolServer::fixture('anthropic/thinking.request.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($recorded + ['output_config' => ['effort' => 'medium']], $server->requests('POST')[0]['json']);
    }

    public function testTheReportedTypesOfSonnet5DecideThatNoneIsDisabled(): void
    {
        $server = $this->serveCapabilities('claude-sonnet-5', [['fixture' => 'anthropic/model-sonnet-5.json']]);

        (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-sonnet-5', 'thinking' => 'none'])))
            ->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        self::assertSame(['type' => 'disabled'], $server->requests('POST')[0]['json']['thinking']);
        self::assertSame(['GET', 'POST'], array_column($server->requests(), 'method'), 'the capabilities are read before the reply is asked');
    }

    public function testAModelThatReportsNoDisabledIsSentBetweenToolsWhateverTheTableSays(): void
    {
        $server = $this->serveCapabilities('claude-sonnet-5', [['fixture' => 'anthropic/model-sonnet-5-no-disabled.json']]);

        (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-sonnet-5', 'thinking' => 'none'])))
            ->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        self::assertSame(['type' => 'between_tools'], $server->requests('POST')[0]['json']['thinking']);
    }

    public function testMythosPreviewCannotTurnThinkingOffSoNoneIsAdaptiveAtItsLowestLevel(): void
    {
        $server = $this->serveCapabilities('claude-mythos-preview', [['fixture' => 'anthropic/model-mythos-preview.json']]);

        (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-mythos-preview', 'thinking' => 'none'])))
            ->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        $body = $server->requests('POST')[0]['json'];
        self::assertSame(['type' => 'adaptive'], $body['thinking']);
        self::assertSame(['effort' => 'low'], $body['output_config']);
    }

    public function testOpus45ThinksOnABudgetWithoutAdaptiveThinking(): void
    {
        $server = $this->serveCapabilities('claude-opus-4-5-20251101', [['fixture' => 'anthropic/model-opus-4-5.json']]);

        (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-opus-4-5-20251101', 'thinking' => 'low'])))
            ->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        $body = $server->requests('POST')[0]['json'];
        self::assertSame(['type' => 'enabled', 'budget_tokens' => 1024], $body['thinking']);
        self::assertSame(['effort' => 'low'], $body['output_config'], 'Opus 4.5 takes effort next to the budget');
        self::assertArrayNotHasKey('adaptive', $body['thinking']);
    }

    public function testWithoutCapabilitiesTheTableApplies(): void
    {
        $server = $this->serveCapabilities('claude-sonnet-5', [['body' => '{"id":"claude-sonnet-5"}', 'type' => 'application/json']]);

        (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-sonnet-5', 'thinking' => 'none'])))
            ->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        self::assertSame(['type' => 'disabled'], $server->requests('POST')[0]['json']['thinking'], 'the table says Sonnet 5 takes disabled');
    }

    public function testAFailedReadOfTheCapabilitiesTheTableApplies(): void
    {
        $server = $this->serveCapabilities('claude-opus-4-5-20251101', [['status' => 500, 'body' => '{"type":"error","error":{"type":"api_error","message":"down"}}', 'type' => 'application/json']]);

        (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-opus-4-5-20251101', 'thinking' => 'low'])))
            ->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        self::assertSame(['type' => 'enabled', 'budget_tokens' => 1024], $server->requests('POST')[0]['json']['thinking']);
    }

    public function testTheBudgetOfTheRequestIsAtMostHalfOfMaxTokens(): void
    {
        $server = $this->serveCapabilities('claude-opus-4-5-20251101', [['fixture' => 'anthropic/model-opus-4-5.json']]);

        (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-opus-4-5-20251101', 'thinking' => 'high', 'max_output_tokens' => 4096])))
            ->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        self::assertSame(['type' => 'enabled', 'budget_tokens' => 2048], $server->requests('POST')[0]['json']['thinking'], 'high asks for 8192; max_tokens 4096 keeps it at half');
    }

    public function testABudgetThatMaxTokensCannotHoldTurnsThinkingOffAndSaysSo(): void
    {
        $server = $this->serveCapabilities('claude-opus-4-5-20251101', [['fixture' => 'anthropic/model-opus-4-5.json']]);
        $progress = [];
        $options = new ChatOptions(onEvent: static function (string $type, string $text) use (&$progress): void {
            if ($type === 'progress') {
                $progress[] = $text;
            }
        });

        (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-opus-4-5-20251101', 'thinking' => 'high', 'max_output_tokens' => 1024])))
            ->chat([['role' => 'user', 'content' => 'hi']], [], $options);

        self::assertSame(['type' => 'disabled'], $server->requests('POST')[0]['json']['thinking']);
        self::assertSame(['thinking off: max_tokens is too small for a thinking budget'], $progress);
    }

    public function testNoneTurnsThinkingOffWithoutANoticeBecauseItIsWhatWasAsked(): void
    {
        $server = $this->serveCapabilities('claude-opus-4-5-20251101', [['fixture' => 'anthropic/model-opus-4-5.json']]);
        $progress = [];
        $options = new ChatOptions(onEvent: static function (string $type, string $text) use (&$progress): void {
            if ($type === 'progress') {
                $progress[] = $text;
            }
        });

        (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-opus-4-5-20251101', 'thinking' => 'none', 'max_output_tokens' => 1024])))
            ->chat([['role' => 'user', 'content' => 'hi']], [], $options);

        self::assertSame(['type' => 'disabled'], $server->requests('POST')[0]['json']['thinking']);
        self::assertSame([], $progress);
    }

    private function serveCapabilities(string $model, array $capabilities): ProtocolServer
    {
        $this->server?->stop();

        return $this->server = new ProtocolServer(['chat' => [
            "/v1/models/{$model}" => $capabilities,
            '/v1/messages' => [['fixture' => 'anthropic/text.sse']],
        ]]);
    }

    private function serve(string $fixture): ProtocolServer
    {
        $this->server?->stop();

        return $this->server = new ProtocolServer(['chat' => ['/v1/messages' => [['fixture' => $fixture]]]]);
    }
}
