<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\OpenAiClient;
use Phycode\Llm\RejectedParameter;
use Phycode\Tests\Support\LlamaServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RejectedParameterTest extends TestCase
{
    private const MESSAGES = [['role' => 'user', 'content' => 'hello']];

    private ?LlamaServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public static function messages(): iterable
    {
        yield 'openai unknown parameter' => ["HTTP 400: Unknown parameter: 'top_k'.", ['param' => 'top_k', 'use' => null]];
        yield 'openai unsupported parameter' => ["Unsupported parameter: 'presence_penalty' is not supported with this model.", ['param' => 'presence_penalty', 'use' => null]];
        yield 'openai wants another name for the limit' => [
            "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.",
            ['param' => 'max_tokens', 'use' => 'max_completion_tokens'],
        ];
        yield 'openai unsupported value' => ["Unsupported value: 'temperature' does not support 0.6 with this model. Only the default (1) value is supported.", ['param' => 'temperature', 'use' => null]];
        yield 'azure unrecognised argument' => ['Unrecognized request argument supplied: min_p', ['param' => 'min_p', 'use' => null]];
        yield 'groq property' => ["'messages.0.extra' : property 'top_k' is unsupported", ['param' => 'top_k', 'use' => null]];
        yield 'a nested field names its top-level field' => ["Unknown parameter: 'stream_options.include_usage'.", ['param' => 'stream_options', 'use' => null]];
        yield 'pydantic location' => ['{"detail":[{"type":"extra_forbidden","loc":["body","min_p"],"msg":"Extra inputs are not permitted"}]}', ['param' => 'min_p', 'use' => null]];
        yield 'a message that names no field' => ['HTTP 400: Invalid request', null];
        yield 'a model that is missing' => ['The model `gpt-9` does not exist or you do not have access to it.', null];
    }

    #[DataProvider('messages')]
    public function testTheFieldThatWasRefusedIsReadFromTheMessage(string $message, ?array $expected): void
    {
        self::assertSame($expected, RejectedParameter::in($message));
    }

    private function client(array $steps): OpenAiClient
    {
        $this->server = new LlamaServer(['hosted' => $steps]);

        return new OpenAiClient($this->server->profile('hosted'), [0.0, 0.0]);
    }

    private static function refusal(string $message): array
    {
        return ['status' => 400, 'body' => json_encode(['error' => ['message' => $message, 'type' => 'invalid_request_error']], JSON_THROW_ON_ERROR), 'type' => 'application/json'];
    }

    public function testAFieldThatTheServerDoesNotKnowIsLeftOutAndTheNextRequestsDoNotSendItEither(): void
    {
        $client = $this->client([self::refusal("Unknown parameter: 'top_k'."), ['fixture' => 'text.sse']]);
        $progress = [];
        $options = new ChatOptions(onEvent: static function (string $type, string $text) use (&$progress): void {
            $progress[] = [$type, $text];
        });

        $response = $client->chat(self::MESSAGES, [], $options);
        $client->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame('The three primary colours are red, blue, and yellow.', $response->content);
        $requests = $this->server->requests();
        self::assertCount(3, $requests);
        self::assertArrayHasKey('top_k', $requests[0]['json']);
        self::assertArrayNotHasKey('top_k', $requests[1]['json']);
        self::assertArrayHasKey('top_p', $requests[1]['json'], 'only the refused field goes');
        self::assertArrayNotHasKey('top_k', $requests[2]['json'], 'the client remembers it');
        $told = array_values(array_filter($progress, static fn (array $event): bool => $event[0] === 'progress'));
        self::assertSame([['progress', 'the server does not take top_k: the request was changed and sent again, and stays changed for this session']], $told);
    }

    public function testAServerThatWantsAnotherNameForTheLimitOfTheReplyGetsIt(): void
    {
        $client = $this->client([
            self::refusal("Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead."),
            ['fixture' => 'text.sse'],
        ]);

        $client->chat(self::MESSAGES, [], new ChatOptions());
        $client->chat(self::MESSAGES, [], new ChatOptions());

        $requests = $this->server->requests();
        self::assertArrayHasKey('max_tokens', $requests[0]['json']);
        foreach ([1, 2] as $i) {
            self::assertArrayNotHasKey('max_tokens', $requests[$i]['json']);
            self::assertSame(2048, $requests[$i]['json']['max_completion_tokens'], 'the limit keeps its value under the new name');
        }
    }

    public function testSeveralRefusalsAreFixedOneAfterTheOtherAndTheTelltaleIsOneSentence(): void
    {
        $client = $this->client([
            self::refusal("Unknown parameter: 'top_k'."),
            self::refusal("Unsupported value: 'temperature' does not support 0.6 with this model."),
            self::refusal("Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead."),
            ['fixture' => 'text.sse'],
        ]);
        $told = [];
        $options = new ChatOptions(onEvent: static function (string $type, string $text) use (&$told): void {
            if ($type === 'progress') {
                $told[] = $text;
            }
        });

        $client->chat(self::MESSAGES, [], $options);

        $last = $this->server->requests()[3]['json'];
        self::assertArrayNotHasKey('top_k', $last);
        self::assertArrayNotHasKey('temperature', $last);
        self::assertArrayNotHasKey('max_tokens', $last);
        self::assertArrayHasKey('max_completion_tokens', $last);
        self::assertSame('hello', $last['messages'][0]['content']);
        self::assertSame(['the server does not take top_k, temperature and wants max_completion_tokens for the limit of the reply: the request was changed and sent again, and stays changed for this session'], $told);
    }

    public function testTheFieldThatTheErrorNamesInItsParamIsTheOneThatGoesEvenWhenTheMessageDoesNot(): void
    {
        $body = ['status' => 400, 'body' => json_encode(['error' => ['message' => 'Invalid value for this model.', 'type' => 'invalid_request_error', 'param' => 'min_p', 'code' => 'invalid_value']], JSON_THROW_ON_ERROR), 'type' => 'application/json'];
        $client = $this->client([$body, ['fixture' => 'text.sse']]);

        $client->chat(self::MESSAGES, [], new ChatOptions());

        self::assertArrayNotHasKey('min_p', $this->server->requests()[1]['json']);
    }

    public function testAParamThatIsNeededIsNotDroppedEvenWhenTheErrorNamesIt(): void
    {
        $body = ['status' => 400, 'body' => json_encode(['error' => ['message' => 'Invalid messages.', 'param' => 'messages.0.content']], JSON_THROW_ON_ERROR), 'type' => 'application/json'];
        $client = $this->client([$body]);

        try {
            $client->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the refusal must be the answer');
        } catch (LlmException $error) {
            self::assertSame('HTTP 400: Invalid messages.', $error->getMessage());
        }
        self::assertCount(1, $this->server->requests());
    }

    public function testARefusalOfTheModelOrTheMessagesIsTheAnswerAndIsNotRetried(): void
    {
        $client = $this->client([self::refusal("Unknown parameter: 'model'.")]);

        try {
            $client->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the refusal must be the answer');
        } catch (LlmException $error) {
            self::assertSame("HTTP 400: Unknown parameter: 'model'.", $error->getMessage());
        }
        self::assertCount(1, $this->server->requests());
    }

    public function testARefusalThatNamesNoFieldOrAFieldThatWasNotSentIsTheAnswer(): void
    {
        $client = $this->client([self::refusal('Invalid request.')]);
        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('HTTP 400: Invalid request.');

        try {
            $client->chat(self::MESSAGES, [], new ChatOptions());
        } finally {
            self::assertCount(1, $this->server->requests());
        }
    }

    public function testTheClientGivesUpAfterEightFixes(): void
    {
        $fields = ['top_k', 'min_p', 'presence_penalty', 'top_p', 'temperature', 'stream_options', 'parallel_tool_calls', 'tool_choice', 'reasoning_effort'];
        $client = $this->client(array_map(static fn (string $field): array => self::refusal("Unknown parameter: '{$field}'."), $fields));

        try {
            $client->chat(self::MESSAGES, [['type' => 'function', 'function' => ['name' => 'x', 'parameters' => ['type' => 'object']]]], new ChatOptions());
            self::fail('the ninth refusal must be the answer');
        } catch (LlmException $error) {
            self::assertStringStartsWith('HTTP 400: Unknown parameter:', $error->getMessage());
        }
        self::assertCount(9, $this->server->requests(), 'one request and eight fixes');
    }
}
