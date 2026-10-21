<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\ChatOptions;
use Phycode\Llm\EffortMapping;
use Phycode\Llm\OpenAiClient;
use Phycode\Tests\Support\LlamaServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenAiRequestTest extends TestCase
{
    private const MESSAGES = [
        ['role' => 'system', 'content' => 'You are concise.'],
        ['role' => 'user', 'content' => 'Say hello.'],
    ];

    private ?LlamaServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        putenv('PHYCODE_TEST_KEY');
        EffortMapping::setOpenAiHost(null);
        EffortMapping::setTestHost('openrouter.ai', null);
        EffortMapping::setTestHost('api.deepseek.com', null);
        EffortMapping::setTestHost('api.groq.com', null);
    }

    private function serve(array $scenarios): LlamaServer
    {
        $this->server?->stop();

        return $this->server = new LlamaServer($scenarios);
    }

    public function testADefaultProfileSendsOnlyTheStreamingFieldsSamplingAndThinkingOff(): void
    {
        $body = $this->send([]);

        self::assertEquals([
            'model' => 'qwen35-2b',
            'messages' => self::MESSAGES,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'max_tokens' => 2048,
            'temperature' => 0.6,
            'top_p' => 0.95,
            'top_k' => 20,
            'min_p' => 0.0,
            'presence_penalty' => 0.0,
            'chat_template_kwargs' => ['enable_thinking' => false],
        ], $body);
    }

    public function testTheTokenLimitIsSentUnderTheNameTheProfileChooses(): void
    {
        $body = $this->send(['max_output_tokens' => 512, 'token_param' => 'max_completion_tokens']);

        self::assertSame(512, $body['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $body);
    }

    public static function thinkingSettings(): iterable
    {
        yield 'none' => ['none', false];
        yield 'off, the old name of none' => ['off', false];
        yield 'minimal' => ['minimal', true];
        yield 'low' => ['low', true];
        yield 'medium' => ['medium', true];
        yield 'on, the old name of medium' => ['on', true];
        yield 'high' => ['high', true];
        yield 'xhigh' => ['xhigh', true];
        yield 'max' => ['max', true];
    }

    #[DataProvider('thinkingSettings')]
    public function testLlamaCppGetsTheSwitchAndNoReasoningEffortAtEveryLevel(string $thinking, bool $enabled): void
    {
        $body = $this->send(['thinking' => $thinking]);

        self::assertSame(['enable_thinking' => $enabled], $body['chat_template_kwargs']);
        self::assertArrayNotHasKey('reasoning_effort', $body);
    }

    public function testExtraBodyIsMergedLastAndItsOwnThinkingSwitchWins(): void
    {
        $body = $this->send([
            'thinking' => 'on',
            'extra_body' => ['top_k' => 40, 'chat_template_kwargs' => ['custom' => 'x'], 'seed' => 7],
        ]);

        self::assertSame(40, $body['top_k'], 'extra_body replaces a sampling value');
        self::assertSame(['enable_thinking' => true, 'custom' => 'x'], $body['chat_template_kwargs'], 'the thinking switch survives a custom kwarg');
        self::assertSame(7, $body['seed']);

        $explicit = $this->send(['thinking' => 'on', 'extra_body' => ['chat_template_kwargs' => ['enable_thinking' => false]]]);
        self::assertSame(['enable_thinking' => false], $explicit['chat_template_kwargs'], 'an explicit switch in extra_body wins');
    }

    public function testASamplingObjectReplacesTheDefaultsWithoutMerging(): void
    {
        $body = $this->send(['sampling' => ['temperature' => 0.2]]);

        self::assertSame(0.2, $body['temperature']);
        self::assertArrayNotHasKey('top_p', $body);
        self::assertArrayNotHasKey('top_k', $body);
    }

    public function testCallerOverridesReplaceSamplingButExtraBodyStillWins(): void
    {
        $server = $this->serve(['request' => [['fixture' => 'text.sse']]]);
        $profile = $server->profile('request', ['extra_body' => ['temperature' => 0.3]]);

        (new OpenAiClient($profile))->chat(self::MESSAGES, [], new ChatOptions(overrides: ['temperature' => 0.0]));

        self::assertSame(0.3, $server->requests()[0]['json']['temperature']);
    }

    public function testOverridesAloneReplaceTheProfileSampling(): void
    {
        $server = $this->serve(['request' => [['fixture' => 'text.sse']]]);

        (new OpenAiClient($server->profile('request')))->chat(self::MESSAGES, [], new ChatOptions(overrides: ['temperature' => 0.0]));

        self::assertEquals(0.0, $server->requests()[0]['json']['temperature']);
        self::assertSame(0.95, $server->requests()[0]['json']['top_p']);
    }

    public function testToolsAddTheToolFieldsAtTheEnd(): void
    {
        $tool = ['type' => 'function', 'function' => ['name' => 'read', 'description' => 'Reads a file.', 'parameters' => ['type' => 'object', 'properties' => []]]];
        $body = $this->send([], [$tool]);

        self::assertSame([$tool], $body['tools']);
        self::assertSame('auto', $body['tool_choice']);
        self::assertFalse($body['parallel_tool_calls']);
    }

    public function testWithoutToolsNoToolFieldIsSent(): void
    {
        $body = $this->send([]);

        self::assertArrayNotHasKey('tools', $body);
        self::assertArrayNotHasKey('tool_choice', $body);
        self::assertArrayNotHasKey('parallel_tool_calls', $body);
    }

    public function testTheReplayDataOfAnotherProtocolIsNotSentInTheMessages(): void
    {
        $server = $this->serve(['request' => [['fixture' => 'text.sse']]]);
        $messages = [
            ['role' => 'user', 'content' => 'Hi.'],
            ['role' => 'assistant', 'content' => 'Hello.', 'extra' => ['anthropic' => [['type' => 'thinking', 'thinking' => 'x', 'signature' => 'S']]]],
            ['role' => 'user', 'content' => 'Again.'],
        ];

        (new OpenAiClient($server->profile('request', [])))->chat($messages, [], new ChatOptions());

        self::assertSame(
            [['role' => 'user', 'content' => 'Hi.'], ['role' => 'assistant', 'content' => 'Hello.'], ['role' => 'user', 'content' => 'Again.']],
            $server->requests()[0]['json']['messages'],
        );
    }

    public function testTheAuthorizationHeaderIsSentOnlyWhenAKeyIsSet(): void
    {
        putenv('PHYCODE_TEST_KEY=secret-key-for-tests');
        $server = $this->serve(['request' => [['fixture' => 'text.sse']]]);

        (new OpenAiClient($server->profile('request', ['api_key_env' => 'PHYCODE_TEST_KEY'])))->chat(self::MESSAGES, [], new ChatOptions());
        self::assertSame('Bearer secret-key-for-tests', $server->requests()[0]['authorization']);

        putenv('PHYCODE_TEST_KEY');
        (new OpenAiClient($server->profile('request', ['api_key_env' => 'PHYCODE_TEST_KEY'])))->chat(self::MESSAGES, [], new ChatOptions());
        self::assertNull($server->requests()[1]['authorization']);
    }

    public function testTheRequestGoesToTheChatPathBelowTheBaseUrl(): void
    {
        $server = $this->serve(['request' => [['fixture' => 'text.sse']]]);

        (new OpenAiClient($server->profile('request')))->chat(self::MESSAGES, [], new ChatOptions());

        $request = $server->requests()[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('/request/v1/chat/completions', $request['path']);
    }

    public function testAGpt6ModelWithAReasoningLevelSendsTheLevelItTakesAndNoSamplingValues(): void
    {
        EffortMapping::setOpenAiHost('127.0.0.1');
        $server = $this->serve(['request' => [['fixture' => 'text.sse']]]);
        $profile = $server->profile('request', [
            'model' => 'gpt-6-luna',
            'thinking' => 'minimal',
            'sampling' => ['temperature' => 0.6, 'top_p' => 0.95, 'logprobs' => true, 'top_logprobs' => 2],
        ]);

        (new OpenAiClient($profile))->chat(self::MESSAGES, [], new ChatOptions());

        $body = $server->requests()[0]['json'];
        self::assertSame('low', $body['reasoning_effort'], 'Luna takes no minimal, so it is sent as low');
        self::assertSame(['model', 'messages', 'stream', 'stream_options', 'max_tokens', 'reasoning_effort'], array_keys($body));
    }

    public function testTheSamplingValuesStayWithNoneAndOnAModelWithoutTheRule(): void
    {
        EffortMapping::setOpenAiHost('127.0.0.1');
        $server = $this->serve(['request' => [['fixture' => 'text.sse']]]);
        $sampling = ['temperature' => 0.6, 'top_p' => 0.95, 'logprobs' => true];

        (new OpenAiClient($server->profile('request', ['model' => 'gpt-6-luna', 'thinking' => 'none', 'sampling' => $sampling])))
            ->chat(self::MESSAGES, [], new ChatOptions());
        (new OpenAiClient($server->profile('request', ['model' => 'gpt-5.1', 'thinking' => 'low', 'sampling' => $sampling])))
            ->chat(self::MESSAGES, [], new ChatOptions());

        $none = $server->requests()[0]['json'];
        self::assertSame('none', $none['reasoning_effort']);
        self::assertSame([0.6, 0.95, true], [$none['temperature'], $none['top_p'], $none['logprobs']], 'at none the sampling values stay');

        $low = $server->requests()[1]['json'];
        self::assertSame('low', $low['reasoning_effort']);
        self::assertSame([0.6, 0.95, true], [$low['temperature'], $low['top_p'], $low['logprobs']], 'gpt-5.1 is not a GPT-6 model, so nothing is taken out');
    }

    public function testAnOpenRouterProfileSendsTheEffortAsAnObjectAndNoPenaltiesWithReasoning(): void
    {
        $body = $this->send(['effort_format' => 'openrouter', 'thinking' => 'low', 'sampling' => ['temperature' => 0.6, 'presence_penalty' => 0.0, 'stop' => ['END']]]);

        self::assertSame(['effort' => 'low'], $body['reasoning']);
        self::assertSame(0.6, $body['temperature'], 'the sampling that reasoning accepts stays');
        self::assertArrayNotHasKey('presence_penalty', $body);
        self::assertArrayNotHasKey('stop', $body);
        self::assertArrayNotHasKey('chat_template_kwargs', $body);
    }

    public function testADeepSeekModelOfItsTableTurnsThinkingOffWithNoneAndSendsItsEffortValueOtherwise(): void
    {
        EffortMapping::setTestHost('api.deepseek.com', '127.0.0.1');
        $server = $this->serve(['request' => [['fixture' => 'text.sse']]]);
        $sampling = ['temperature' => 0.6, 'presence_penalty' => 0.0];

        (new OpenAiClient($server->profile('request', ['model' => 'deepseek-v4-pro', 'thinking' => 'none', 'sampling' => $sampling])))->chat(self::MESSAGES, [], new ChatOptions());
        (new OpenAiClient($server->profile('request', ['model' => 'deepseek-v4-pro', 'thinking' => 'max', 'sampling' => $sampling])))->chat(self::MESSAGES, [], new ChatOptions());

        $off = $server->requests()[0]['json'];
        self::assertSame(['type' => 'disabled'], $off['thinking']);
        self::assertArrayNotHasKey('reasoning_effort', $off);
        self::assertEquals(0.0, $off['presence_penalty'], 'without reasoning the sampling values stay');

        $on = $server->requests()[1]['json'];
        self::assertSame(['type' => 'enabled'], $on['thinking']);
        self::assertSame('max', $on['reasoning_effort']);
        self::assertArrayNotHasKey('presence_penalty', $on);
    }

    public function testAGroqModelSendsTheLevelOfItsTableAndNoneOfTheSamplingThatReasoningRefuses(): void
    {
        EffortMapping::setTestHost('api.groq.com', '127.0.0.1');
        $server = $this->serve(['request' => [['fixture' => 'text.sse']]]);

        (new OpenAiClient($server->profile('request', ['model' => 'openai/gpt-oss-120b', 'thinking' => 'minimal', 'sampling' => ['temperature' => 0.6, 'frequency_penalty' => 0.1]])))
            ->chat(self::MESSAGES, [], new ChatOptions());

        $body = $server->requests()[0]['json'];
        self::assertSame('low', $body['reasoning_effort'], 'gpt-oss takes low to high, so minimal goes as low');
        self::assertSame(0.6, $body['temperature']);
        self::assertArrayNotHasKey('frequency_penalty', $body);
    }

    public function testTheLevelsTheProfileDeclaresClampTheLevelOfAnOpenAiCompatibleServer(): void
    {
        $server = $this->serve(['request' => [['fixture' => 'text.sse']]]);

        (new OpenAiClient($server->profile('request', ['effort_format' => 'openai', 'effort_levels' => ['low', 'high'], 'thinking' => 'xhigh'])))
            ->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame('high', $server->requests()[0]['json']['reasoning_effort'], 'xhigh is above every level the server takes, so high');
    }

    public function testTheSwitchFormatSendsNoReasoningBackWithItsToolCalls(): void
    {
        $server = $this->serve(['request' => [['fixture' => 'text.sse']]]);
        $messages = [
            ['role' => 'user', 'content' => 'Hi.'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c', 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{}']]], 'extra' => ['openai-chat' => ['reasoning_details' => [['text' => 'x']]]]],
        ];

        (new OpenAiClient($server->profile('request', ['thinking' => 'none'])))->chat($messages, [], new ChatOptions());

        self::assertArrayNotHasKey('reasoning_details', $server->requests()[0]['json']['messages'][1], 'the local switch takes no reasoning back');
    }

    private function send(array $fields, array $tools = []): array
    {
        $server = $this->serve(['request' => [['fixture' => 'text.sse']]]);
        (new OpenAiClient($server->profile('request', $fields)))->chat(self::MESSAGES, $tools, new ChatOptions());

        $requests = $server->requests();
        self::assertCount(1, $requests);

        return $requests[0]['json'];
    }
}
