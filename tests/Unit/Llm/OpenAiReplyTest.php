<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;
use Phycode\Llm\OpenAiClient;
use Phycode\Tests\Support\LlamaServer;
use PHPUnit\Framework\TestCase;

final class OpenAiReplyTest extends TestCase
{
    private ?LlamaServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testPlainTextCarriesUsageAndTimings(): void
    {
        $response = $this->replay('text.sse');

        self::assertSame('The three primary colours are red, blue, and yellow.', $response->content);
        self::assertSame([], $response->toolCalls);
        self::assertSame(FinishReason::STOP, $response->finishReason);
        self::assertSame(34, $response->usage->promptTokens);
        self::assertSame(13, $response->usage->completionTokens);
        self::assertSame(30, $response->usage->cachedTokens);
        self::assertEqualsWithDelta(17.77295933102581, $response->usage->tokensPerSecond, 0.000001);
    }

    public function testUsageComesFromTheTimingsWhenTheServerSendsNoUsage(): void
    {
        $response = $this->replay('timings-only.sse');

        self::assertSame(FinishReason::STOP, $response->finishReason);
        self::assertSame(34, $response->usage->promptTokens, 'prompt_n 4 plus the 30 cached tokens');
        self::assertSame(13, $response->usage->completionTokens, 'predicted_n');
        self::assertSame(30, $response->usage->cachedTokens, 'cache_n');
        self::assertEqualsWithDelta(19.018397129489927, $response->usage->tokensPerSecond, 0.000001);
    }

    public function testANativeToolCallIsReadFromTheStream(): void
    {
        $response = $this->replay('native-tool.sse', [$this->weather()]);

        self::assertSame(FinishReason::TOOL_CALLS, $response->finishReason);
        self::assertSame('', $response->content);
        self::assertCount(1, $response->toolCalls);
        self::assertSame('t029FHyybhFRAWj987zdz8mfOiCq4py1', $response->toolCalls[0]->id);
        self::assertSame('get_weather', $response->toolCalls[0]->name);
        self::assertSame('{"city":"Madrid"}', $response->toolCalls[0]->arguments);
        self::assertSame(329, $response->usage->promptTokens);
        self::assertSame(325, $response->usage->cachedTokens);
    }

    public function testReasoningIsKeptApartFromTheAnswerAndStreamedAsItsOwnEvent(): void
    {
        $events = ['text' => '', 'reasoning' => ''];
        $response = $this->replay('reasoning.sse', [], static function (string $type, string $text) use (&$events): void {
            $events[$type] .= $text;
        });

        self::assertSame('51', $response->content);
        self::assertStringContainsString('Thinking', $response->reasoning);
        self::assertSame($response->reasoning, $events['reasoning']);
        self::assertSame('51', $events['text']);
        self::assertSame(FinishReason::STOP, $response->finishReason);
    }

    public function testAReplyCutByTheTokenLimitHasFinishReasonLength(): void
    {
        $response = $this->replay('cut.sse');

        self::assertSame(FinishReason::LENGTH, $response->finishReason);
        self::assertSame('The three primary colours are red, blue', $response->content);
        self::assertSame(8, $response->usage->completionTokens);
    }

    public function testAToolCallCutByTheTokenLimitKeepsTheArgumentsItGot(): void
    {
        $response = $this->replay('native-tool-cut.sse', [$this->weather()]);

        self::assertSame(FinishReason::LENGTH, $response->finishReason);
        self::assertCount(1, $response->toolCalls);
        self::assertSame('get_weather', $response->toolCalls[0]->name);
        self::assertSame('{', $response->toolCalls[0]->arguments);
    }

    public function testTheTextProtocolReplyIsPlainContentForTheRunnerToParse(): void
    {
        $response = $this->replay('text-tool.sse');

        self::assertSame([], $response->toolCalls);
        self::assertSame(FinishReason::STOP, $response->finishReason);
        self::assertStringContainsString('<tool_call>', $response->content);
        self::assertStringContainsString('"path": "src/a.php"', $response->content);
    }

    public function testANumberTooLargeForAFloatIsNoSpeedAndTheReplyStillCompletes(): void
    {
        $response = $this->streamed([
            '{"choices":[{"delta":{"content":"hi"},"finish_reason":null}]}',
            '{"choices":[{"delta":{},"finish_reason":"stop"}],"timings":{"prompt_n":4,"cache_n":30,"predicted_n":2,"predicted_per_second":1e400}}',
        ]);

        self::assertSame('hi', $response->content);
        self::assertNull($response->usage->tokensPerSecond, 'JSON cannot carry INF, so the speed is unknown');
        self::assertSame(2, $response->usage->completionTokens);
    }

    public function testAnUnknownFinishReasonIsAnErrorThatNamesIt(): void
    {
        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('the reply ended with the finish reason content_filter, which this client does not know');

        $this->streamed([
            '{"choices":[{"delta":{"content":"I cannot"},"finish_reason":null}]}',
            '{"choices":[{"delta":{},"finish_reason":"content_filter"}]}',
        ]);
    }

    public function testAStreamThatEndsWithoutAFinishReasonIsAnError(): void
    {
        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('the reply ended without a finish reason');

        $this->streamed(['{"choices":[{"delta":{"content":"half a"},"finish_reason":null}]}']);
    }

    private function streamed(array $lines): LlmResponse
    {
        $this->server?->stop();
        $body = '';
        foreach ($lines as $line) {
            $body .= "data: {$line}\n\n";
        }
        $this->server = new LlamaServer(['reply' => [['body' => $body . "data: [DONE]\n\n", 'type' => 'text/event-stream']]]);

        return (new OpenAiClient($this->server->profile('reply')))->chat([['role' => 'user', 'content' => 'hello']], [], new ChatOptions());
    }

    private function weather(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'get_weather',
                'description' => 'Get the current weather for a city.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string'], 'unit' => ['type' => 'string', 'enum' => ['celsius', 'fahrenheit']]],
                    'required' => ['city'],
                ],
            ],
        ];
    }

    private function replay(string $fixture, array $tools = [], ?\Closure $onEvent = null): LlmResponse
    {
        $this->server?->stop();
        $this->server = new LlamaServer(['reply' => [['fixture' => $fixture]]]);
        $client = new OpenAiClient($this->server->profile('reply'));

        return $client->chat([['role' => 'user', 'content' => 'hello']], $tools, new ChatOptions($onEvent));
    }
}
