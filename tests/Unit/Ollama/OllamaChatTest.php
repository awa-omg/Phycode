<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ollama;

use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;
use Phycode\Llm\Ollama\OllamaClient;
use Phycode\Llm\ToolCall;
use Phycode\Tests\Support\OllamaServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OllamaChatTest extends TestCase
{
    private const MESSAGES = [
        ['role' => 'system', 'content' => 'You are concise.'],
        ['role' => 'user', 'content' => 'What colours?'],
    ];

    private ?OllamaServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testATextReplyIsReadFromTheStreamWithItsUsage(): void
    {
        $response = $this->replay('live/chat-text.ndjson');

        self::assertSame('**Hola**', $response->content, 'the answer comes in three lines: "**", "Hola", "**"');
        self::assertSame('', $response->reasoning);
        self::assertSame([], $response->toolCalls);
        self::assertSame(FinishReason::STOP, $response->finishReason);
        self::assertSame(14, $response->usage->promptTokens);
        self::assertSame(4, $response->usage->completionTokens);
        self::assertNull($response->usage->cachedTokens, 'the server sent no prompt_eval_cached_count');
        self::assertEqualsWithDelta(0.78, $response->usage->tokensPerSecond, 0.001, '4 tokens over 5.13 s');
    }

    public function testReasoningStreamsAsItsOwnEventAndTheAnswerIsKeptApart(): void
    {
        $events = [];
        $response = $this->replay('live/chat-thinking.ndjson', static function (string $type) use (&$events): void {
            $events[] = $type;
        });

        self::assertStringStartsWith('El resultado de $17 \\times 23$ es **391**.', $response->content);
        self::assertStringStartsWith('Thinking Process:', $response->reasoning);
        self::assertSame(['reasoning' => 410, 'text' => 121], array_count_values($events), 'one event for each line that has text');
        self::assertSame(410, array_search('text', $events, true), 'all the reasoning comes before the answer');
        self::assertSame(FinishReason::STOP, $response->finishReason);
        self::assertSame(32, $response->usage->promptTokens);
        self::assertSame(651, $response->usage->completionTokens, 'the reasoning tokens are counted too');
        self::assertEqualsWithDelta(5.35, $response->usage->tokensPerSecond, 0.001, '651 tokens over 121.7 s');
    }

    public function testTheRecordedToolCallKeepsItsIdAndItsArgumentsAsText(): void
    {
        $response = $this->replay('live/chat-tool.ndjson');

        self::assertSame('', $response->content);
        self::assertEquals([new ToolCall('call_meht2eww', 'get_weather', '{"city":"Madrid"}')], $response->toolCalls);
        self::assertSame(FinishReason::TOOL_CALLS, $response->finishReason, 'the server says stop, and the reply holds a call');
        self::assertSame(74, $response->usage->promptTokens, 'the tool definition is part of the prompt');
        self::assertSame(15, $response->usage->completionTokens);
        self::assertEqualsWithDelta(5.84, $response->usage->tokensPerSecond, 0.01, '15 tokens over 2.57 s');
    }

    public function testEachReplyReadsItsOwnCalls(): void
    {
        $this->serve(['/api/chat' => [['fixture' => 'live/chat-tool.ndjson']]]);
        $client = new OllamaClient($this->server->profile('chat'));

        $first = $client->chat(self::MESSAGES, [], new ChatOptions());
        $second = $client->chat(self::MESSAGES, [], new ChatOptions());

        self::assertEquals($first->toolCalls, $second->toolCalls);
        self::assertCount(1, $second->toolCalls, 'the calls of one reply are not added to the next');
    }

    public function testAReplyCutByTheTokenLimitHasFinishReasonLength(): void
    {
        $response = $this->replay('live/chat-length.ndjson');

        self::assertSame('## La Fotosíntesis: El Pro', $response->content);
        self::assertSame(FinishReason::LENGTH, $response->finishReason);
        self::assertSame(8, $response->usage->completionTokens, 'the count equals num_predict');
        self::assertEqualsWithDelta(3.78, $response->usage->tokensPerSecond, 0.01, '8 tokens over 2.11 s');
    }

    public function testTheSameLongPromptSentTwiceCountsTheWholePromptEachTime(): void
    {
        $this->serve(['/api/chat' => [['fixture' => 'live/chat-cached-first.ndjson'], ['fixture' => 'live/chat-cached-second.ndjson']]]);
        $client = new OllamaClient($this->server->profile('chat'));

        $first = $client->chat(self::MESSAGES, [], new ChatOptions());
        $second = $client->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame(479, $first->usage->promptTokens);
        self::assertSame(479, $second->usage->promptTokens, 'the second prompt came from the cache, and the count still holds all of it');
        self::assertNull($second->usage->cachedTokens, 'the server sent no prompt_eval_cached_count');
    }

    public function testAThinkingLevelTheModelDoesNotNameIsAcceptedAndStreamsReasoning(): void
    {
        $response = $this->replay('live/chat-think-high.ndjson');

        self::assertSame('**Hola**', $response->content);
        self::assertStringStartsWith('Thinking Process:', $response->reasoning);
        self::assertSame(FinishReason::STOP, $response->finishReason);
    }

    public function testAMidStreamErrorEndsTheReplyWithTheServerMessage(): void
    {
        $this->serve(['/api/chat' => [['fixture' => 'live/chat-error.ndjson']]]);

        try {
            $this->send();
            self::fail('the reply should have failed');
        } catch (LlmException $error) {
            self::assertSame('server error: an error was encountered while running the model: unexpected EOF', $error->getMessage());
        }
        self::assertCount(1, $this->server->requests(), 'a stream that has started is not sent again');
    }

    public function testTheLastLineIsReadEvenWithoutANewline(): void
    {
        $body = '{"message":{"role":"assistant","content":"Hi"},"done":false}' . "\n"
            . '{"message":{"role":"assistant","content":""},"done":true,"done_reason":"stop","prompt_eval_count":5,"eval_count":2,"eval_duration":1000000000}';
        $this->serve(['/api/chat' => [['body' => $body, 'type' => 'application/x-ndjson']]]);

        $response = $this->send();

        self::assertSame('Hi', $response->content);
        self::assertSame(FinishReason::STOP, $response->finishReason);
        self::assertSame(2, $response->usage->completionTokens);
    }

    public static function finishReasons(): iterable
    {
        yield 'stop' => ['stop', false, FinishReason::STOP];
        yield 'stop with a tool call' => ['stop', true, FinishReason::TOOL_CALLS];
        yield 'no reason' => [null, false, FinishReason::STOP];
        yield 'no reason with a tool call' => [null, true, FinishReason::TOOL_CALLS];
        yield 'length' => ['length', false, FinishReason::LENGTH];
        yield 'length with a tool call' => ['length', true, FinishReason::LENGTH];
        yield 'a reason this client does not know' => ['unload', false, FinishReason::ERROR];
        yield 'an unknown reason with a tool call' => ['unload', true, FinishReason::TOOL_CALLS];
    }

    #[DataProvider('finishReasons')]
    public function testTheDoneReasonAndTheToolCallsDecideTheFinishReason(?string $reason, bool $withCall, string $expected): void
    {
        $call = $withCall ? ',"tool_calls":[{"function":{"name":"time","arguments":{}}}]' : '';
        $done = $reason === null ? '' : ',"done_reason":"' . $reason . '"';
        $line = '{"message":{"role":"assistant","content":"x"' . $call . '},"done":true' . $done . ',"eval_count":3,"eval_duration":1000000000}';
        $this->serve(['/api/chat' => [['body' => $line . "\n", 'type' => 'application/x-ndjson']]]);

        self::assertSame($expected, $this->send()->finishReason);
    }

    private function replay(string $fixture, ?\Closure $onEvent = null): LlmResponse
    {
        $this->serve(['/api/chat' => [['fixture' => $fixture]]]);

        return $this->send(new ChatOptions(onEvent: $onEvent));
    }

    private function serve(array $endpoints): void
    {
        $this->server?->stop();
        $this->server = new OllamaServer(['chat' => $endpoints]);
    }

    private function send(ChatOptions $options = new ChatOptions()): LlmResponse
    {
        return (new OllamaClient($this->server->profile('chat')))->chat(self::MESSAGES, [], $options);
    }
}
