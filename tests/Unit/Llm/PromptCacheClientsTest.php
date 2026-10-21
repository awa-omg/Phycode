<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\Anthropic\AnthropicClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\EffortMapping;
use Phycode\Llm\OpenAiClient;
use Phycode\Llm\Responses\ResponsesClient;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\TestCase;

final class PromptCacheClientsTest extends TestCase
{
    private const MESSAGES = [
        ['role' => 'system', 'content' => 'You are concise.'],
        ['role' => 'user', 'content' => 'Say hello.'],
    ];
    private const TOOLS = [[
        'type' => 'function',
        'function' => ['name' => 'get_weather', 'description' => 'Get the weather.', 'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]]],
    ]];

    private ?ProtocolServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        EffortMapping::setOpenAiHost(null);
        EffortMapping::setTestHost('api.x.ai', null);
        EffortMapping::setTestHost('openrouter.ai', null);
    }

    private function serve(array $endpoints): ProtocolServer
    {
        $this->server?->stop();

        return $this->server = new ProtocolServer(['chat' => $endpoints]);
    }

    private function anthropic(array $fields = [], array $steps = [['fixture' => 'anthropic/text.sse']]): AnthropicClient
    {
        return new AnthropicClient($this->serve(['/v1/messages' => $steps])->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-sonnet-5-5'] + $fields));
    }

    public function testAnthropicMarksTheLastToolTheSystemPromptAndTheEndOfTheMessages(): void
    {
        $client = $this->anthropic();

        $client->chat(self::MESSAGES, self::TOOLS, new ChatOptions());

        $body = $this->server->requests('POST')[0]['json'];
        self::assertSame([['type' => 'text', 'text' => 'You are concise.', 'cache_control' => ['type' => 'ephemeral']]], $body['system']);
        self::assertSame(['type' => 'ephemeral'], $body['tools'][0]['cache_control']);
        self::assertSame(['type' => 'ephemeral'], $body['messages'][0]['content'][0]['cache_control']);
    }

    public function testTheHourIsAskedForWhenTheProfileSaysSo(): void
    {
        $client = $this->anthropic(['prompt_cache_ttl' => '1h']);

        $client->chat(self::MESSAGES, [], new ChatOptions());

        $body = $this->server->requests('POST')[0]['json'];
        self::assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $body['system'][0]['cache_control']);
        self::assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $body['messages'][0]['content'][0]['cache_control']);
    }

    public function testNothingIsMarkedWhenTheProfileTurnsItOff(): void
    {
        $client = $this->anthropic(['prompt_cache' => false]);

        $client->chat(self::MESSAGES, self::TOOLS, new ChatOptions());

        $body = $this->server->requests('POST')[0]['json'];
        self::assertSame('You are concise.', $body['system']);
        self::assertStringNotContainsString('cache_control', json_encode($body));
    }

    public function testTheMarksFollowTheConversationAndThePrefixOnlyGrows(): void
    {
        $client = $this->anthropic([], [['fixture' => 'anthropic/text.sse'], ['fixture' => 'anthropic/text.sse']]);
        $first = [...self::MESSAGES];
        $second = [...$first, ['role' => 'assistant', 'content' => 'Hello.'], ['role' => 'user', 'content' => 'And again?']];

        $client->chat($first, self::TOOLS, new ChatOptions());
        $client->chat($second, self::TOOLS, new ChatOptions());

        [$one, $two] = array_map(static fn (array $request): array => $request['json'], $this->server->requests('POST'));
        self::assertSame($one['tools'], $two['tools'], 'the tools do not change from one request to the next');
        self::assertSame($one['system'], $two['system'], 'nor does the system prompt');
        $strip = static function (array $messages): array {
            foreach ($messages as &$message) {
                foreach ($message['content'] as &$block) {
                    unset($block['cache_control']);
                }
            }

            return $messages;
        };
        self::assertSame($strip($one['messages']), array_slice($strip($two['messages']), 0, count($one['messages'])));
        self::assertSame(['type' => 'ephemeral'], $two['messages'][array_key_last($two['messages'])]['content'][0]['cache_control']);
        self::assertArrayNotHasKey('cache_control', $two['messages'][0]['content'][0]);
    }

    public function testAGatewayThatRefusesCacheControlGetsFewerMarksThenNoneAndTheSessionRemembers(): void
    {
        $refusal = ['status' => 400, 'type' => 'application/json', 'body' => json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'system.0.cache_control: Extra inputs are not permitted']])];
        $other = ['status' => 400, 'type' => 'application/json', 'body' => json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'messages.0.content.0.cache_control: Extra inputs are not permitted']])];
        $client = $this->anthropic([], [$refusal, $other, ['fixture' => 'anthropic/text.sse'], ['fixture' => 'anthropic/text.sse']]);
        $notices = [];
        $options = new ChatOptions(static function (string $type, string $text) use (&$notices): void {
            $notices[] = [$type, $text];
        });

        $response = $client->chat(self::MESSAGES, self::TOOLS, $options);

        self::assertSame('Blue', $response->content);
        $bodies = array_map(static fn (array $request): string => json_encode($request['json']), $this->server->requests('POST'));
        self::assertCount(3, $bodies);
        self::assertSame(3, substr_count($bodies[0], 'cache_control'));
        self::assertSame(1, substr_count($bodies[1], 'cache_control'), 'second try: only the last message');
        self::assertStringContainsString('"system":"You are concise."', $bodies[1]);
        self::assertSame(0, substr_count($bodies[2], 'cache_control'), 'third try: none');
        self::assertSame(['notice', 'the server refused cache_control; prompt caching is kept on the last message only for this session'], $notices[0]);
        self::assertSame(['notice', 'the server refused cache_control; prompt caching is off for this session'], $notices[1]);

        $client->chat(self::MESSAGES, self::TOOLS, new ChatOptions());
        self::assertSame(0, substr_count(json_encode($this->server->requests('POST')[3]['json']), 'cache_control'), 'the next request starts without marks');
    }

    public function testAnErrorThatIsNotAboutCacheControlIsStillAnError(): void
    {
        $client = $this->anthropic([], [['status' => 400, 'type' => 'application/json', 'body' => json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens: must be positive']])]]);

        $this->expectException(\Phycode\Llm\LlmException::class);
        $this->expectExceptionMessage('max_tokens: must be positive');
        $client->chat(self::MESSAGES, [], new ChatOptions());
        self::assertCount(1, $this->server->requests('POST'), 'and it is not asked again');
    }

    public function testOpenAiGetsTheKeyAndTheRetentionAtItsOwnHostOnly(): void
    {
        EffortMapping::setOpenAiHost('127.0.0.1');
        $server = $this->serve(['/v1/chat/completions' => [['fixture' => 'llama/text.sse'], ['fixture' => 'llama/text.sse']]]);
        $client = new OpenAiClient($server->profile('chat', ['prompt_cache_retention' => '24h']));

        $client->chat(self::MESSAGES, [], new ChatOptions(cacheKey: 'phycode-abc'));
        $client->chat(self::MESSAGES, [], new ChatOptions());

        [$first, $second] = array_map(static fn (array $request): array => $request['json'], $server->requests('POST'));
        self::assertSame(['phycode-abc', '24h'], [$first['prompt_cache_key'], $first['prompt_cache_retention']]);
        self::assertArrayNotHasKey('prompt_cache_key', $second, 'no key, no field');
        self::assertSame('24h', $second['prompt_cache_retention']);
    }

    public function testALocalServerAndAProfileWithTheCacheOffGetNoRoutingFields(): void
    {
        $server = $this->serve(['/v1/chat/completions' => [['fixture' => 'llama/text.sse']]]);

        (new OpenAiClient($server->profile('chat', [])))->chat(self::MESSAGES, [], new ChatOptions(cacheKey: 'phycode-abc'));

        $request = $server->requests('POST')[0];
        self::assertStringNotContainsString('prompt_cache', json_encode($request['json']));
        self::assertNull($request['headers']['x-grok-conv-id'] ?? null);
        self::assertNull($request['headers']['x-session-id'] ?? null);

        EffortMapping::setOpenAiHost('127.0.0.1');
        $off = $this->serve(['/v1/chat/completions' => [['fixture' => 'llama/text.sse']]]);
        (new OpenAiClient($off->profile('chat', ['prompt_cache' => false])))->chat(self::MESSAGES, [], new ChatOptions(cacheKey: 'phycode-abc'));
        self::assertStringNotContainsString('prompt_cache', json_encode($off->requests('POST')[0]['json']));
    }

    public function testXaiGetsTheConversationHeaderAndOpenRouterTheSessionHeader(): void
    {
        EffortMapping::setTestHost('api.x.ai', '127.0.0.1');
        $server = $this->serve(['/v1/chat/completions' => [['fixture' => 'llama/text.sse']]]);
        (new OpenAiClient($server->profile('chat', [])))->chat(self::MESSAGES, [], new ChatOptions(cacheKey: 'phycode-xyz'));
        self::assertSame('phycode-xyz', $server->requests('POST')[0]['headers']['x-grok-conv-id']);
        self::assertStringNotContainsString('prompt_cache_key', json_encode($server->requests('POST')[0]['json']), 'chat completions take the header');

        EffortMapping::setTestHost('api.x.ai', null);
        EffortMapping::setTestHost('openrouter.ai', '127.0.0.1');
        $router = $this->serve(['/v1/chat/completions' => [['fixture' => 'llama/text.sse']]]);
        (new OpenAiClient($router->profile('chat', [])))->chat(self::MESSAGES, [], new ChatOptions(cacheKey: 'phycode-xyz'));
        self::assertSame('phycode-xyz', $router->requests('POST')[0]['headers']['x-session-id']);
    }

    public function testAnAnthropicModelOnOpenRouterIsMarkedInChat(): void
    {
        EffortMapping::setTestHost('openrouter.ai', '127.0.0.1');
        $server = $this->serve(['/v1/chat/completions' => [['fixture' => 'llama/text.sse']]]);

        (new OpenAiClient($server->profile('chat', ['model' => 'anthropic/claude-sonnet-5-5'])))->chat(self::MESSAGES, [], new ChatOptions());

        $messages = $server->requests('POST')[0]['json']['messages'];
        self::assertSame([['type' => 'text', 'text' => 'You are concise.', 'cache_control' => ['type' => 'ephemeral']]], $messages[0]['content']);
        self::assertSame([['type' => 'text', 'text' => 'Say hello.', 'cache_control' => ['type' => 'ephemeral']]], $messages[1]['content']);

        $plain = $this->serve(['/v1/chat/completions' => [['fixture' => 'llama/text.sse']]]);
        (new OpenAiClient($plain->profile('chat', ['model' => 'openai/gpt-5.5'])))->chat(self::MESSAGES, [], new ChatOptions());
        self::assertSame(self::MESSAGES, $plain->requests('POST')[0]['json']['messages']);
    }

    public function testTheResponsesApiGetsTheKeyAtOpenAiAndAtXai(): void
    {
        EffortMapping::setOpenAiHost('127.0.0.1');
        $server = $this->serve(['/v1/responses' => [['fixture' => 'responses/text.sse']]]);
        (new ResponsesClient($server->profile('chat', ['protocol' => 'openai-responses'])))->chat(self::MESSAGES, [], new ChatOptions(cacheKey: 'phycode-r1'));
        self::assertSame('phycode-r1', $server->requests('POST')[0]['json']['prompt_cache_key']);

        EffortMapping::setOpenAiHost(null);
        EffortMapping::setTestHost('api.x.ai', '127.0.0.1');
        $xai = $this->serve(['/v1/responses' => [['fixture' => 'responses/text.sse']]]);
        (new ResponsesClient($xai->profile('chat', ['protocol' => 'openai-responses'])))->chat(self::MESSAGES, [], new ChatOptions(cacheKey: 'phycode-r2'));
        self::assertSame('phycode-r2', $xai->requests('POST')[0]['json']['prompt_cache_key']);
        self::assertSame('phycode-r2', $xai->requests('POST')[0]['headers']['x-grok-conv-id'] ?? 'phycode-r2', 'the header is harmless beside the field');
    }

    public function testDeepSeekReportsTheCachedTokensUnderItsOwnName(): void
    {
        $stream = "data: {\"choices\":[{\"delta\":{\"content\":\"hi\"},\"finish_reason\":null}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":100,\"completion_tokens\":2,\"prompt_cache_hit_tokens\":80,\"prompt_cache_miss_tokens\":20}}\n\n"
            . "data: [DONE]\n\n";
        $server = $this->serve(['/v1/chat/completions' => [['status' => 200, 'type' => 'text/event-stream', 'body' => $stream]]]);

        $response = (new OpenAiClient($server->profile('chat', [])))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame([100, 2, 80], [$response->usage->promptTokens, $response->usage->completionTokens, $response->usage->cachedTokens]);
    }
}
