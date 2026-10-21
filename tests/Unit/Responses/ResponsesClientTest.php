<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Responses;

use Phycode\Config\Config;
use Phycode\Config\Profile;
use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\Responses\ResponsesClient;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponsesClientTest extends TestCase
{
    private const MESSAGES = [['role' => 'user', 'content' => 'hello']];

    private ?ProtocolServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testARecordedTextReplyIsReturnedWithItsTextFinishAndUsage(): void
    {
        $server = $this->serve(['text' => [['fixture' => 'responses/text.sse']]]);
        $emitted = [];
        $options = new ChatOptions(static function (string $type, string $text) use (&$emitted): void {
            $emitted[] = [$type, $text];
        });

        $response = (new ResponsesClient($server->profile('text', ['protocol' => 'openai-responses'])))->chat(self::MESSAGES, [], $options);

        self::assertSame('Blue', $response->content);
        self::assertSame(FinishReason::STOP, $response->finishReason);
        self::assertSame([['text', 'Blue']], $emitted);
        self::assertSame([33, 2, 29], [$response->usage->promptTokens, $response->usage->completionTokens, $response->usage->cachedTokens]);
        self::assertSame([], $response->extra);
    }

    public function testARecordedToolReplyIsReturnedWithItsCallAndTheToolCallsFinish(): void
    {
        $server = $this->serve(['tool' => [['fixture' => 'responses/tool.sse']]]);

        $response = (new ResponsesClient($server->profile('tool', ['protocol' => 'openai-responses'])))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame(FinishReason::TOOL_CALLS, $response->finishReason);
        self::assertSame(
            ['call_BtZ9WQ5RFHGMYHQSqbrwDV1EW9QTBfyH', 'get_weather', '{"city":"Madrid"}'],
            [$response->toolCalls[0]->id, $response->toolCalls[0]->name, $response->toolCalls[0]->arguments],
        );
    }

    public function testATruncatedReplyIsALengthEvenThoughTheServerSaysCompleted(): void
    {
        $server = $this->serve(['cut' => [['fixture' => 'responses/truncated.sse']]]);
        $profile = new Profile(
            name: 'test',
            baseUrl: $server->url('cut'),
            model: 'qwen35-2b',
            toolMode: 'auto',
            contextLimit: 16384,
            maxOutputTokens: 8,
            sampling: [],
            extraBody: [],
            apiKeyFile: null,
            apiKeyEnv: null,
            protocol: 'openai-responses',
        );

        $response = (new ResponsesClient($profile))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame(FinishReason::LENGTH, $response->finishReason);
        self::assertSame('One, Two, Three, Four,', $response->content);
    }

    public function testAReplyThatStopsBelowTheLimitIsAStop(): void
    {
        $server = $this->serve(['text' => [['fixture' => 'responses/truncated.sse']]]);
        $profile = $server->profile('text', ['protocol' => 'openai-responses', 'max_output_tokens' => 16]);

        $response = (new ResponsesClient($profile))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame(FinishReason::STOP, $response->finishReason, 'eight tokens of a sixteen-token limit is not a cut');
    }

    public function testAnIncompleteReplyForTheTokenLimitIsALength(): void
    {
        $server = $this->serve(['limit' => [['fixture' => 'responses/documented/incomplete-max-output-tokens.sse']]]);

        $response = (new ResponsesClient($server->profile('limit', ['protocol' => 'openai-responses'])))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame(FinishReason::LENGTH, $response->finishReason);
        self::assertSame('The answer is twelve, because', $response->content);
    }

    public static function failedReplies(): array
    {
        return [
            'content filter' => ['responses/documented/incomplete-content-filter.sse', 'the reply was stopped by a content filter'],
            'failed response' => ['responses/documented/failed.sse', 'server error: The model failed to generate a response.'],
            'error event' => ['responses/documented/error.sse', 'server error: Rate limit reached for the project.'],
            'no final event' => ['responses/documented/no-completed.sse', 'the reply ended before response.completed'],
        ];
    }

    #[DataProvider('failedReplies')]
    public function testAReplyThatEndsWithoutAUsableFinishIsAnErrorWithItsMessageAndIsNotSentAgain(string $fixture, string $message): void
    {
        $server = $this->serve(['bad' => [['fixture' => $fixture]]]);

        try {
            (new ResponsesClient($server->profile('bad', ['protocol' => 'openai-responses']), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the reply should have failed');
        } catch (LlmException $error) {
            self::assertSame($message, $error->getMessage());
        }
        self::assertCount(1, $server->requests(), 'a stream that began is not sent again');
    }

    public function testAMessageThatDidNotStreamIsReturnedFromItsDoneEvent(): void
    {
        $server = $this->serve(['done' => [['fixture' => 'responses/documented/message-without-deltas.sse']]]);

        $response = (new ResponsesClient($server->profile('done', ['protocol' => 'openai-responses'])))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame('Twelve.', $response->content);
        self::assertSame(FinishReason::STOP, $response->finishReason);
    }

    public function testTheReasoningItemComesBackInExtraAndTheNextRequestReplaysItBeforeTheCall(): void
    {
        $server = $this->serve(['turn' => [
            ['fixture' => 'responses/documented/reasoning-encrypted.sse'],
            ['fixture' => 'responses/tool-result.sse'],
        ]]);
        $client = new ResponsesClient($server->profile('turn', ['protocol' => 'openai-responses', 'thinking' => 'on']));
        $first = $client->chat(self::MESSAGES, [], new ChatOptions());
        $conversation = [
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => $first->content, 'tool_calls' => [
                ['id' => $first->toolCalls[0]->id, 'type' => 'function', 'function' => ['name' => $first->toolCalls[0]->name, 'arguments' => $first->toolCalls[0]->arguments]],
            ], 'extra' => $first->extra],
            ['role' => 'tool', 'tool_call_id' => $first->toolCalls[0]->id, 'content' => '{"temp":21}'],
        ];

        $client->chat($conversation, [], new ChatOptions());

        $input = $server->requests()[1]['json']['input'];
        self::assertSame(['message', 'reasoning', 'function_call', 'function_call_output'], array_column($input, 'type'));
        self::assertSame('gAAAAABdocEncryptedReasoning', $input[1]['encrypted_content']);
        self::assertSame('call_doc1', $input[2]['call_id']);
    }

    public function testABusyServerIsAskedAgainBeforeTheFirstByte(): void
    {
        $server = $this->serve(['busy' => [
            ['status' => 503, 'body' => '{"error":{"message":"Loading model"}}', 'type' => 'application/json'],
            ['status' => 429, 'body' => '{"error":{"message":"Too many requests"}}', 'type' => 'application/json'],
            ['fixture' => 'responses/text.sse'],
        ]]);

        $response = (new ResponsesClient($server->profile('busy', ['protocol' => 'openai-responses']), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame('Blue', $response->content);
        self::assertCount(3, $server->requests());
        self::assertSame($server->requests()[0]['json'], $server->requests()[2]['json'], 'the retries send the same request');
    }

    public function testWhenTheRetriesRunOutTheStatusIsReportedWithItsMessage(): void
    {
        $server = $this->serve(['busy' => [['status' => 503, 'body' => '{"error":{"message":"Loading model"}}', 'type' => 'application/json']]]);

        try {
            (new ResponsesClient($server->profile('busy', ['protocol' => 'openai-responses']), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the reply should have failed');
        } catch (LlmException $error) {
            self::assertSame('HTTP 503: Loading model', $error->getMessage());
        }
        self::assertCount(3, $server->requests(), 'one attempt and two retries');
    }

    public function testAnErrorStatusIsReportedAtOnceWithItsMessage(): void
    {
        $server = $this->serve(['bad' => [['status' => 400, 'body' => '{"error":{"message":"Unsupported parameter: previous_response_id"}}', 'type' => 'application/json']]]);

        try {
            (new ResponsesClient($server->profile('bad', ['protocol' => 'openai-responses']), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the reply should have failed');
        } catch (LlmException $error) {
            self::assertSame('HTTP 400: Unsupported parameter: previous_response_id', $error->getMessage());
        }
        self::assertCount(1, $server->requests());
    }

    public function testAnUnreachableServerIsReported(): void
    {
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'http://127.0.0.1:' . $this->closedPort(), 'model' => 'gpt-5', 'protocol' => 'openai-responses']]], null, [], '/nonexistent')->profile('test');

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('connection to the model server failed: ');
        (new ResponsesClient($profile, [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());
    }

    private function serve(array $scenarios): ProtocolServer
    {
        $this->server?->stop();

        return $this->server = new ProtocolServer(array_map(
            static fn (array $steps): array => ['/v1/responses' => $steps],
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
