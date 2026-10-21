<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Anthropic;

use Phycode\Config\Config;
use Phycode\Llm\Anthropic\AnthropicClient;
use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\CapabilityCache;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Support\Cancellation;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnthropicClientTest extends TestCase
{
    private const MESSAGES = [['role' => 'user', 'content' => 'hello']];
    private const TOOLS = [[
        'type' => 'function',
        'function' => ['name' => 'get_weather', 'description' => 'Get the weather.', 'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]]],
    ]];

    private ?ProtocolServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        Cancellation::setPoll(null);
    }

    public function testARecordedTextReplyIsReturnedWithItsTextFinishAndUsage(): void
    {
        $server = $this->serve(['text' => [['fixture' => 'anthropic/text.sse']]]);
        $emitted = [];
        $options = new ChatOptions(static function (string $type, string $text) use (&$emitted): void {
            $emitted[] = [$type, $text];
        });

        $response = (new AnthropicClient($server->profile('text', ['protocol' => 'anthropic'])))->chat(self::MESSAGES, [], $options);

        self::assertSame('Blue', $response->content);
        self::assertSame(FinishReason::STOP, $response->finishReason);
        self::assertSame([['text', 'Blue']], $emitted);
        self::assertSame([33, 2], [$response->usage->promptTokens, $response->usage->completionTokens]);
        self::assertSame([], $response->extra);
    }

    public function testARecordedToolReplyIsReturnedWithItsCallAndTheToolCallsFinish(): void
    {
        $server = $this->serve(['tool' => [['fixture' => 'anthropic/tool.sse']]]);

        $response = (new AnthropicClient($server->profile('tool', ['protocol' => 'anthropic'])))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame(FinishReason::TOOL_CALLS, $response->finishReason);
        self::assertSame(['get_weather', '{"city":"Madrid"}'], [$response->toolCalls[0]->name, $response->toolCalls[0]->arguments]);
    }

    public function testTheThinkingBlockComesBackAsExtraWithItsSignature(): void
    {
        $server = $this->serve(['think' => [['fixture' => 'anthropic/documented/thinking-signature.sse']]]);

        $response = (new AnthropicClient($server->profile('think', ['protocol' => 'anthropic', 'thinking' => 'on'])))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame('The user asks about the weather.', $response->reasoning);
        self::assertSame(
            ['anthropic' => [['type' => 'thinking', 'thinking' => 'The user asks about the weather.', 'signature' => 'EqQBCkYIARgCIkDdocSignature']]],
            $response->extra,
        );
        self::assertSame(FinishReason::TOOL_CALLS, $response->finishReason);
    }

    public function testTheNextRequestReplaysTheThinkingBeforeTheTextAndTheCallWithItsSignature(): void
    {
        $server = $this->serve(['turn' => [
            ['fixture' => 'anthropic/documented/thinking-signature.sse'],
            ['fixture' => 'anthropic/tool-result.sse'],
        ]]);
        $client = new AnthropicClient($server->profile('turn', ['protocol' => 'anthropic', 'thinking' => 'on']));
        $first = $client->chat(self::MESSAGES, [], new ChatOptions());
        $conversation = [
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => $first->content, 'tool_calls' => [
                ['id' => $first->toolCalls[0]->id, 'type' => 'function', 'function' => ['name' => $first->toolCalls[0]->name, 'arguments' => $first->toolCalls[0]->arguments]],
            ], 'extra' => $first->extra],
            ['role' => 'tool', 'tool_call_id' => $first->toolCalls[0]->id, 'content' => '{"temp":21}'],
        ];

        $client->chat($conversation, self::TOOLS, new ChatOptions());

        $sent = $server->requests('POST')[1]['json']['messages'];
        self::assertSame(
            '[{"type":"thinking","thinking":"The user asks about the weather.","signature":"EqQBCkYIARgCIkDdocSignature"},{"type":"text","text":"Checking Madrid."},{"type":"tool_use","id":"toolu_01docWeather","name":"get_weather","input":{"city":"Madrid"}}]',
            json_encode($sent[1]['content'], JSON_THROW_ON_ERROR),
        );
        self::assertSame('tool_result', $sent[2]['content'][0]['type']);
        self::assertSame('toolu_01docWeather', $sent[2]['content'][0]['tool_use_id']);
    }

    public function testAPausedTurnIsContinuedWithTheContentSentBackUnchangedAndTheTextJoined(): void
    {
        $server = $this->serve(['pause' => [
            ['fixture' => 'anthropic/documented/pause-turn.sse'],
            ['fixture' => 'anthropic/documented/pause-turn-continued.sse'],
        ]]);

        $response = (new AnthropicClient($server->profile('pause', ['protocol' => 'anthropic'])))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertCount(2, $server->requests('POST'), 'one continuation');
        $sent = $server->requests('POST')[1]['json']['messages'];
        self::assertSame(
            '[{"type":"text","text":"Let me look that up."},'
            . '{"type":"server_tool_use","id":"srvtoolu_01pause","name":"web_search","input":{"query":"weather Madrid"}},'
            . '{"type":"web_search_tool_result","tool_use_id":"srvtoolu_01pause","content":[{"type":"web_search_result","url":"https://example.test/madrid","title":"Madrid weather","encrypted_content":"ENC-madrid","page_age":"1 hour"}]}]',
            json_encode($sent[1]['content'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        self::assertSame('Let me look that up.It is 21 degrees in Madrid.', $response->content);
        self::assertSame(FinishReason::STOP, $response->finishReason);
        self::assertSame(400, $response->usage->promptTokens, 'the prompt of the last request is the context');
        self::assertSame(39, $response->usage->completionTokens, 'the completion tokens of both replies are added up');
        self::assertSame(
            '{"anthropic":[{"type":"server_tool_use","id":"srvtoolu_01pause","name":"web_search","input":{"query":"weather Madrid"}},'
            . '{"type":"web_search_tool_result","tool_use_id":"srvtoolu_01pause","content":[{"type":"web_search_result","url":"https://example.test/madrid","title":"Madrid weather","encrypted_content":"ENC-madrid","page_age":"1 hour"}]}]}',
            json_encode($response->extra, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'the server blocks of the paused reply are kept for the next request',
        );
    }

    public function testAPauseThatGoesOnAfterThreeContinuationsIsAnError(): void
    {
        $server = $this->serve(['pause' => [['fixture' => 'anthropic/documented/pause-turn.sse']]]);

        try {
            (new AnthropicClient($server->profile('pause', ['protocol' => 'anthropic'])))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the turn should have failed');
        } catch (LlmException $error) {
            self::assertSame('the model paused the turn more than 3 times', $error->getMessage());
        }
        self::assertCount(4, $server->requests('POST'), 'the first request and three continuations');
    }

    public function testARefusalEndsTheTurnWithItsExplanation(): void
    {
        $server = $this->serve(['refusal' => [['fixture' => 'anthropic/documented/refusal.sse']]]);

        try {
            (new AnthropicClient($server->profile('refusal', ['protocol' => 'anthropic'])))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('a refusal should have failed the turn');
        } catch (LlmException $error) {
            self::assertSame('the model refused to answer: This request is declined by the safety policy.', $error->getMessage());
        }
    }

    public static function finishReasons(): array
    {
        return [
            'stop sequence' => ['anthropic/documented/stop-sequence.sse', FinishReason::STOP],
            'context window exceeded' => ['anthropic/documented/context-window.sse', FinishReason::LENGTH],
            'token limit' => ['anthropic/max-tokens.sse', FinishReason::LENGTH],
        ];
    }

    #[DataProvider('finishReasons')]
    public function testTheStopReasonsAreMappedToTheCanonicalFinish(string $fixture, string $finish): void
    {
        $server = $this->serve(['stop' => [['fixture' => $fixture]]]);

        $response = (new AnthropicClient($server->profile('stop', ['protocol' => 'anthropic'])))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame($finish, $response->finishReason);
    }

    public static function retriedStatuses(): array
    {
        return ['too many requests' => [429], 'bad gateway' => [502], 'unavailable' => [503], 'gateway timeout' => [504], 'overloaded' => [529]];
    }

    #[DataProvider('retriedStatuses')]
    public function testABusyOrOverloadedServerIsRetriedBeforeTheFirstByte(int $status): void
    {
        $server = $this->serve(['busy' => [
            ['status' => $status, 'body' => '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', 'type' => 'application/json'],
            ['status' => $status, 'body' => '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', 'type' => 'application/json'],
            ['fixture' => 'anthropic/text.sse'],
        ]]);

        $response = (new AnthropicClient($server->profile('busy', ['protocol' => 'anthropic']), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame('Blue', $response->content);
        self::assertCount(3, $server->requests('POST'));
        self::assertSame($server->requests('POST')[0]['json'], $server->requests('POST')[2]['json'], 'the retries send the same request');
    }

    public function testWhenTheRetriesRunOutTheStatusIsReportedWithAnthropicsMessage(): void
    {
        $server = $this->serve(['busy' => [['status' => 529, 'body' => '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', 'type' => 'application/json']]]);

        try {
            (new AnthropicClient($server->profile('busy', ['protocol' => 'anthropic']), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the reply should have failed');
        } catch (LlmException $error) {
            self::assertSame('HTTP 529: Overloaded', $error->getMessage());
        }
        self::assertCount(3, $server->requests('POST'), 'one attempt and two retries');
    }

    public static function refusedStatuses(): array
    {
        return [
            'bad request' => [400, 'max_tokens: must be greater than 0'],
            'unauthorized' => [401, 'invalid x-api-key'],
            'not found' => [404, 'model: claude-x not found'],
        ];
    }

    #[DataProvider('refusedStatuses')]
    public function testAnErrorStatusIsReportedAtOnceWithItsMessage(int $status, string $message): void
    {
        $server = $this->serve(['bad' => [['status' => $status, 'body' => json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => $message]], JSON_THROW_ON_ERROR), 'type' => 'application/json']]]);

        try {
            (new AnthropicClient($server->profile('bad', ['protocol' => 'anthropic'])))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the reply should have failed');
        } catch (LlmException $error) {
            self::assertSame("HTTP {$status}: {$message}", $error->getMessage());
        }
        self::assertCount(1, $server->requests('POST'));
    }

    public function testAnErrorEventAfterTheStartEndsTheReplyWithItsMessageAndIsNotRetried(): void
    {
        $server = $this->serve(['overload' => [['fixture' => 'anthropic/documented/overloaded-mid-stream.sse']]]);

        try {
            (new AnthropicClient($server->profile('overload', ['protocol' => 'anthropic']), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the stream should have failed');
        } catch (LlmException $error) {
            self::assertSame('server error: Overloaded', $error->getMessage());
        }
        self::assertCount(1, $server->requests('POST'), 'a stream that began is not sent again');
    }

    public function testAStreamThatEndsBeforeMessageStopIsAnError(): void
    {
        $server = $this->serve(['cut' => [['fixture' => 'anthropic/documented/no-message-stop.sse']]]);

        try {
            (new AnthropicClient($server->profile('cut', ['protocol' => 'anthropic'])))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the stream should have failed');
        } catch (LlmException $error) {
            self::assertSame('server error: the reply ended before message_stop', $error->getMessage());
        }
    }

    public function testAnUnreachableServerIsRetriedAndThenReported(): void
    {
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'http://127.0.0.1:' . $this->closedPort(), 'model' => 'claude-haiku-5-5', 'protocol' => 'anthropic']]], null, [], '/nonexistent')->profile('test');

        $started = microtime(true);
        try {
            (new AnthropicClient($profile))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the connection should have failed');
        } catch (LlmException $error) {
            self::assertStringStartsWith('connection to the model server failed: ', $error->getMessage());
        }
        self::assertGreaterThanOrEqual(1.95, microtime(true) - $started, 'both backoffs were waited');
    }

    public function testACancellationDuringABackoffEndsTheWaitAtOnce(): void
    {
        $server = $this->serve(['busy' => [['status' => 529]]]);
        $cancel = new Cancellation();
        $started = microtime(true);
        Cancellation::setPoll(static function () use ($cancel, $started): void {
            if (microtime(true) - $started >= 0.1) {
                $cancel->cancel('interrupted by the user');
            }
        });

        try {
            (new AnthropicClient($server->profile('busy', ['protocol' => 'anthropic']), [2.0]))->chat(self::MESSAGES, [], new ChatOptions(null, $cancel));
            self::fail('the reply should have been cancelled');
        } catch (LlmException $error) {
            self::assertSame('cancelled: interrupted by the user', $error->getMessage());
        }

        self::assertLessThan(1.5, microtime(true) - $started, 'the 2 s backoff was not waited out');
        self::assertCount(1, $server->requests('POST'), 'the retry was never sent');
    }

    public function testAnInterruptEndsARequestTheServerLeavesUnanswered(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($socket, $message);
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'http://' . stream_socket_get_name($socket, false), 'model' => 'claude-haiku-5-5', 'protocol' => 'anthropic']]], null, [], '/nonexistent')->profile('test');
        $cancel = new Cancellation();
        $started = microtime(true);
        Cancellation::setPoll(static function () use ($cancel, $started): void {
            if (microtime(true) - $started >= 0.5) {
                $cancel->cancel('interrupted by the user');
            }
        });

        try {
            (new AnthropicClient($profile))->chat(self::MESSAGES, [], new ChatOptions(null, $cancel));
            self::fail('the request should have been cancelled');
        } catch (LlmException $error) {
            self::assertSame('cancelled: interrupted by the user', $error->getMessage());
        } finally {
            fclose($socket);
        }
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testOneClientReadsTheCapabilitiesOnceForAllItsReplies(): void
    {
        $this->serveModelAndMessages('claude-sonnet-5', [['fixture' => 'anthropic/model-sonnet-5.json']]);
        $client = new AnthropicClient($this->server->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-sonnet-5', 'thinking' => 'low']), [0.0, 0.0]);

        $client->chat(self::MESSAGES, [], new ChatOptions());
        $client->chat(self::MESSAGES, [], new ChatOptions());

        self::assertCount(1, $this->server->requests('GET'));
        self::assertCount(2, $this->server->requests('POST'));
    }

    public function testTheCapabilitiesAreKeptInTheCacheSoTheNextProcessDoesNotAskForThem(): void
    {
        $this->serveModelAndMessages('claude-sonnet-5', [['fixture' => 'anthropic/model-sonnet-5.json']]);
        $cacheFile = Kit::folder('anthropic-capabilities') . '/capabilities.json';
        $profile = $this->server->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-sonnet-5', 'thinking' => 'low']);

        (new AnthropicClient($profile, [0.0, 0.0], new CapabilityCache($cacheFile)))->chat(self::MESSAGES, [], new ChatOptions());
        (new AnthropicClient($profile, [0.0, 0.0], new CapabilityCache($cacheFile)))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertCount(1, $this->server->requests('GET'), 'the second process reads the cache');
        self::assertCount(2, $this->server->requests('POST'));
        self::assertSame(
            ['effort' => ['low', 'medium', 'high', 'xhigh', 'max'], 'thinking' => ['adaptive', 'disabled']],
            (new CapabilityCache($cacheFile))->capabilities(CapabilityCache::key($profile)),
        );
    }

    public function testAFailedReadIsNotKeptAndTheReplyStillGoes(): void
    {
        $this->serveModelAndMessages('claude-sonnet-5', [['status' => 500, 'body' => '{"type":"error","error":{"type":"api_error","message":"down"}}', 'type' => 'application/json']]);
        $cacheFile = Kit::folder('anthropic-capabilities') . '/capabilities.json';
        $profile = $this->server->profile('chat', ['protocol' => 'anthropic', 'model' => 'claude-sonnet-5', 'thinking' => 'none']);

        $response = (new AnthropicClient($profile, [0.0, 0.0], new CapabilityCache($cacheFile)))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame('Blue', $response->content);
        self::assertNull((new CapabilityCache($cacheFile))->capabilities(CapabilityCache::key($profile)));
    }

    private function serveModelAndMessages(string $model, array $capabilities): void
    {
        $this->server?->stop();
        $this->server = new ProtocolServer(['chat' => [
            "/v1/models/{$model}" => $capabilities,
            '/v1/messages' => [['fixture' => 'anthropic/text.sse']],
        ]]);
    }

    private function serve(array $scenarios): ProtocolServer
    {
        $this->server?->stop();

        return $this->server = new ProtocolServer(array_map(
            static fn (array $steps): array => ['/v1/messages' => $steps],
            $scenarios,
        ));
    }

    private function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($server, $message);
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }
}
