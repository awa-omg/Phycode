<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Responses;

use Phycode\Llm\ChatOptions;
use Phycode\Llm\EffortMapping;
use Phycode\Llm\Responses\ResponsesClient;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponsesRequestTest extends TestCase
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
        putenv('PHYCODE_TEST_RESPONSES_KEY');
        EffortMapping::setOpenAiHost(null);
    }

    public function testTheKeyIsSentAsABearerHeaderAndTheTypesAreJson(): void
    {
        putenv('PHYCODE_TEST_RESPONSES_KEY=sk-responses-test');
        $server = $this->serve('responses/text.sse');
        $client = new ResponsesClient($server->profile('chat', [
            'protocol' => 'openai-responses',
            'api_key_env' => 'PHYCODE_TEST_RESPONSES_KEY',
        ]));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        $headers = $server->requests()[0]['headers'];
        self::assertSame('Bearer sk-responses-test', $headers['authorization']);
        self::assertSame(['application/json', 'text/event-stream'], [$headers['content-type'], $headers['accept']]);
        self::assertNull($headers['x-api-key'], 'the Responses API takes the key in a bearer header');
    }

    public function testTheRequestIsStatelessByDefaultAndTheInstructionsAreTheSystemText(): void
    {
        $server = $this->serve('responses/text.sse');
        $client = new ResponsesClient($server->profile('chat', ['protocol' => 'openai-responses']));

        $client->chat([['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'Hi?']], [], new ChatOptions());

        $body = $server->requests()[0]['json'];
        self::assertFalse($body['store'], 'store is false unless responses_store is set');
        self::assertArrayNotHasKey('previous_response_id', $body);
        self::assertSame('Be brief.', $body['instructions']);
        self::assertSame([['type' => 'message', 'role' => 'user', 'content' => 'Hi?']], $body['input']);
        self::assertArrayNotHasKey('tools', $body, 'no tools are sent when there are none');
    }

    public function testResponsesStoreTurnsStoringOn(): void
    {
        $server = $this->serve('responses/text.sse');
        $client = new ResponsesClient($server->profile('chat', ['protocol' => 'openai-responses', 'responses_store' => true]));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        self::assertTrue($server->requests()[0]['json']['store']);
    }

    public function testTheRequestOfTheRecordedTextReplyIsTheOneTheClientBuilds(): void
    {
        $server = $this->serve('responses/text.sse');
        $client = new ResponsesClient($server->profile('chat', [
            'protocol' => 'openai-responses',
            'model' => 'qwen35-2b',
            'max_output_tokens' => 256,
            'extra_body' => ['chat_template_kwargs' => ['enable_thinking' => false]],
        ]));

        $client->chat(
            [['role' => 'system', 'content' => 'Answer with one word.'], ['role' => 'user', 'content' => 'What colour is a clear daytime sky? One word.']],
            [],
            new ChatOptions(),
        );

        $recorded = json_decode(ProtocolServer::fixture('responses/text.request.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($recorded + ['reasoning' => ['effort' => 'none']], $server->requests()[0]['json']);
    }

    public function testTheRequestOfTheRecordedToolCallIsTheOneTheClientBuilds(): void
    {
        $server = $this->serve('responses/tool.sse');
        $client = new ResponsesClient($server->profile('chat', [
            'protocol' => 'openai-responses',
            'model' => 'qwen35-2b',
            'max_output_tokens' => 256,
            'extra_body' => ['chat_template_kwargs' => ['enable_thinking' => false]],
        ]));

        $client->chat(
            [['role' => 'system', 'content' => 'Use the provided tool when it helps. Do not answer from memory.'], ['role' => 'user', 'content' => 'What is the weather in Madrid right now? Use the get_weather tool.']],
            self::WEATHER,
            new ChatOptions(),
        );

        $recorded = json_decode(ProtocolServer::fixture('responses/tool.request.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($recorded + ['reasoning' => ['effort' => 'none']], $server->requests()[0]['json']);
    }

    public function testTheRequestOfTheRecordedToolRoundIsTheOneTheClientBuilds(): void
    {
        $server = $this->serve('responses/tool-result.sse');
        $client = new ResponsesClient($server->profile('chat', [
            'protocol' => 'openai-responses',
            'model' => 'qwen35-2b',
            'max_output_tokens' => 256,
            'extra_body' => ['chat_template_kwargs' => ['enable_thinking' => false]],
        ]));
        $messages = [
            ['role' => 'system', 'content' => 'Use the provided tool when it helps. Do not answer from memory.'],
            ['role' => 'user', 'content' => 'What is the weather in Madrid right now? Use the get_weather tool.'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'call_88vosgBwA9BzFs1eO4rNDaarUtEccidq', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Madrid"}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_88vosgBwA9BzFs1eO4rNDaarUtEccidq', 'content' => '{"city":"Madrid","condition":"clear","temperature_c":21}'],
        ];

        $client->chat($messages, self::WEATHER, new ChatOptions());

        $recorded = json_decode(ProtocolServer::fixture('responses/tool-result.request.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($recorded + ['reasoning' => ['effort' => 'none']], $server->requests()[0]['json']);
    }

    public function testTheRequestOfTheRecordedReasoningReplyIsTheOneTheClientBuilds(): void
    {
        $server = $this->serve('responses/reasoning.sse');
        $client = new ResponsesClient($server->profile('chat', ['protocol' => 'openai-responses', 'thinking' => 'low', 'model' => 'qwen35-2b', 'max_output_tokens' => 1024]));

        $client->chat(
            [['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'What is 17 times 23? Reply with the number only.']],
            [],
            new ChatOptions(),
        );

        self::assertEquals(json_decode(ProtocolServer::fixture('responses/reasoning.request.json'), true, 512, JSON_THROW_ON_ERROR), $server->requests()[0]['json']);
    }

    public static function levelsOfResponses(): iterable
    {
        yield 'none is no reasoning' => ['none', 'none'];
        yield 'off, the old name of none' => ['off', 'none'];
        yield 'minimal' => ['minimal', 'minimal'];
        yield 'low' => ['low', 'low'];
        yield 'medium' => ['medium', 'medium'];
        yield 'on, the old name of medium' => ['on', 'medium'];
        yield 'high' => ['high', 'high'];
        yield 'xhigh' => ['xhigh', 'xhigh'];
        yield 'max' => ['max', 'max'];
    }

    #[DataProvider('levelsOfResponses')]
    public function testEachLevelIsSentAsTheReasoningEffort(string $thinking, string $effort): void
    {
        $server = $this->serve('responses/text.sse');
        $client = new ResponsesClient($server->profile('chat', ['protocol' => 'openai-responses', 'thinking' => $thinking]));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        $body = $server->requests()[0]['json'];
        self::assertSame(['effort' => $effort], $body['reasoning']);
        self::assertSame($effort !== 'none', array_key_exists('include', $body), 'the encrypted reasoning comes back only with reasoning');
        self::assertSame($effort === 'none', array_key_exists('temperature', $body), 'the sampling values go only without reasoning');
    }

    public function testThinkingOnAsksForMediumEffortAndTheEncryptedReasoningAndSendsNoSampling(): void
    {
        $server = $this->serve('responses/text.sse');
        $client = new ResponsesClient($server->profile('chat', ['protocol' => 'openai-responses', 'thinking' => 'on']));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions(overrides: ['temperature' => 0.0]));

        $body = $server->requests()[0]['json'];
        self::assertSame(['effort' => 'medium'], $body['reasoning']);
        self::assertSame(['reasoning.encrypted_content'], $body['include']);
        self::assertArrayNotHasKey('temperature', $body, 'the reasoning models refuse a changed temperature');
        self::assertArrayNotHasKey('top_p', $body);
    }

    public function testToolsAreFunctionsWithAutoChoiceAndNoParallelCalls(): void
    {
        $server = $this->serve('responses/tool.sse');
        $client = new ResponsesClient($server->profile('chat', ['protocol' => 'openai-responses']));

        $client->chat([['role' => 'user', 'content' => 'hi']], self::WEATHER, new ChatOptions());

        $body = $server->requests()[0]['json'];
        self::assertSame('auto', $body['tool_choice']);
        self::assertFalse($body['parallel_tool_calls']);
        self::assertSame('function', $body['tools'][0]['type']);
        self::assertSame('get_weather', $body['tools'][0]['name']);
        self::assertArrayNotHasKey('function', $body['tools'][0], 'the Responses form is flat');
    }

    public function testTheExtraBodyComesLastAndCanSetAnyKey(): void
    {
        $server = $this->serve('responses/text.sse');
        $client = new ResponsesClient($server->profile('chat', [
            'protocol' => 'openai-responses',
            'max_output_tokens' => 512,
            'extra_body' => ['max_output_tokens' => 99, 'metadata' => ['user_id' => 'u1']],
        ]));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        self::assertSame(99, $server->requests()[0]['json']['max_output_tokens']);
        self::assertSame(['user_id' => 'u1'], $server->requests()[0]['json']['metadata']);
    }

    public function testAGpt6ModelThatHasNoNoneIsSentLowAndTheLogprobsLeaveInclude(): void
    {
        EffortMapping::setOpenAiHost('127.0.0.1');
        $server = $this->serve('responses/text.sse');
        $client = new ResponsesClient($server->profile('chat', [
            'protocol' => 'openai-responses',
            'model' => 'gpt-6-astra',
            'thinking' => 'none',
            'extra_body' => ['include' => ['message.output_text.logprobs', 'reasoning.encrypted_content']],
        ]));

        $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        $body = $server->requests()[0]['json'];
        self::assertSame(['effort' => 'low'], $body['reasoning'], 'Astra takes no none, so none is sent as low');
        self::assertSame(['reasoning.encrypted_content'], $body['include'], 'with a reasoning level, the logprobs leave include');
    }

    private function serve(string $fixture): ProtocolServer
    {
        $this->server?->stop();

        return $this->server = new ProtocolServer(['chat' => ['/v1/responses' => [['fixture' => $fixture]]]]);
    }
}
