<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Responses;

use Phycode\Llm\ChatOptions;
use Phycode\Llm\Responses\ResponsesReply;
use Phycode\Llm\SseParser;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponsesReplyTest extends TestCase
{
    private static function read(string $fixture): array
    {
        $reply = new ResponsesReply();
        $parser = new SseParser();
        $emitted = [];
        $options = new ChatOptions(static function (string $type, string $text) use (&$emitted): void {
            $emitted[] = [$type, $text];
        });
        foreach ([...$parser->feed(ProtocolServer::fixture($fixture)), ...$parser->flush()] as $payload) {
            if (!$reply->consume($payload, $options)) {
                break;
            }
        }

        return [$reply, $emitted];
    }

    public function testARecordedTextReplyIsItsTextWithItsStatusAndCounts(): void
    {
        [$reply, $emitted] = self::read('responses/text.sse');

        self::assertSame('Blue', $reply->text());
        self::assertSame([['text', 'Blue']], $emitted);
        self::assertSame('completed', $reply->status());
        self::assertNull($reply->reason());
        self::assertSame([], $reply->calls());
        self::assertSame([], $reply->replay());
        self::assertSame([33, 2, 29], [$reply->usage()->promptTokens, $reply->usage()->completionTokens, $reply->usage()->cachedTokens]);
    }

    public function testARecordedToolCallIsCompleteAtItsDoneEventWithTheStreamedArguments(): void
    {
        [$reply, $emitted] = self::read('responses/tool.sse');

        self::assertSame('completed', $reply->status());
        self::assertSame([], $emitted, 'a call emits no text');
        self::assertSame(
            [['id' => 'call_BtZ9WQ5RFHGMYHQSqbrwDV1EW9QTBfyH', 'name' => 'get_weather', 'arguments' => '{"city":"Madrid"}']],
            array_map(static fn ($call): array => ['id' => $call->id, 'name' => $call->name, 'arguments' => $call->arguments], $reply->calls()),
        );
    }

    public function testARecordedReasoningReplyKeepsItsReasoningItemForReplay(): void
    {
        [$reply, $emitted] = self::read('responses/reasoning.sse');

        self::assertSame('391', $reply->text());
        self::assertStringStartsWith('Thinking Process:', $reply->reasoning());
        self::assertSame('reasoning', $emitted[0][0]);
        self::assertSame('text', $emitted[array_key_last($emitted)][0]);
        self::assertSame(['reasoning'], array_map(static fn (array $item): string => $item['type'], $reply->replay()));
        self::assertSame('rs_1O41gYh6gmuVWPMks17f9PgUnet5KyQb', $reply->replay()[0]['id']);
        self::assertSame('', $reply->replay()[0]['encrypted_content'], 'llama-server sends an empty encrypted_content');
        self::assertSame(200, $reply->usage()->completionTokens, 'the reasoning tokens are output tokens');
    }

    public function testATruncatedReplyIsCompletedAsTheServerSaysIt(): void
    {
        [$reply] = self::read('responses/truncated.sse');

        self::assertSame('completed', $reply->status(), 'llama-server reports completed after the token limit');
        self::assertSame('One, Two, Three, Four,', $reply->text());
        self::assertSame(8, $reply->usage()->completionTokens, 'the count that the client compares with the limit');
    }

    public static function incompleteReplies(): array
    {
        return [
            'token limit' => ['responses/documented/incomplete-max-output-tokens.sse', 'max_output_tokens', 'The answer is twelve, because'],
            'content filter' => ['responses/documented/incomplete-content-filter.sse', 'content_filter', ''],
        ];
    }

    #[DataProvider('incompleteReplies')]
    public function testAnIncompleteReplyKeepsItsReasonAndItsText(string $fixture, string $reason, string $text): void
    {
        [$reply] = self::read($fixture);

        self::assertSame('incomplete', $reply->status());
        self::assertSame($reason, $reply->reason());
        self::assertSame($text, $reply->text());
    }

    public function testTheCountsOfAnIncompleteReplyAreKept(): void
    {
        [$reply] = self::read('responses/documented/incomplete-max-output-tokens.sse');

        self::assertSame([30, 16, 0], [$reply->usage()->promptTokens, $reply->usage()->completionTokens, $reply->usage()->cachedTokens]);
    }

    public function testAFailedResponseEndsTheReadWithItsMessageAndNoStatus(): void
    {
        [$reply] = self::read('responses/documented/failed.sse');

        self::assertSame('The model failed to generate a response.', $reply->error());
        self::assertNull($reply->status());
    }

    public function testAnErrorEventEndsTheReadWithItsMessageAndNoStatus(): void
    {
        [$reply] = self::read('responses/documented/error.sse');

        self::assertSame('Rate limit reached for the project.', $reply->error());
        self::assertNull($reply->status());
    }

    public function testAStreamWithoutAFinalEventHasNoStatusAndNoError(): void
    {
        [$reply] = self::read('responses/documented/no-completed.sse');

        self::assertNull($reply->status());
        self::assertNull($reply->error());
        self::assertSame('The reply stops', $reply->text());
    }

    public function testATextItemThatDidNotStreamIsReadFromItsDoneEvent(): void
    {
        [$reply, $emitted] = self::read('responses/documented/message-without-deltas.sse');

        self::assertSame('Twelve.', $reply->text());
        self::assertSame([['text', 'Twelve.']], $emitted);
        self::assertSame([12, 2], [$reply->usage()->promptTokens, $reply->usage()->completionTokens]);
    }

    public function testAReasoningItemKeepsItsEncryptedContentAndACallIsReadWithItsArguments(): void
    {
        [$reply, $emitted] = self::read('responses/documented/reasoning-encrypted.sse');

        self::assertSame('Madrid needs a weather lookup.', $reply->reasoning());
        self::assertSame([['reasoning', 'Madrid needs a weather lookup.']], $emitted);
        self::assertSame('gAAAAABdocEncryptedReasoning', $reply->replay()[0]['encrypted_content']);
        self::assertSame(
            [['id' => 'call_doc1', 'name' => 'get_weather', 'arguments' => '{"city":"Madrid"}']],
            array_map(static fn ($call): array => ['id' => $call->id, 'name' => $call->name, 'arguments' => $call->arguments], $reply->calls()),
        );
        self::assertSame([80, 40, 64], [$reply->usage()->promptTokens, $reply->usage()->completionTokens, $reply->usage()->cachedTokens]);
    }

    public function testEventsThatAreNotJsonOrNotKnownChangeNothing(): void
    {
        $reply = new ResponsesReply();
        $options = new ChatOptions();

        self::assertTrue($reply->consume('{"type":"response.queued"}', $options));
        self::assertTrue($reply->consume('{"type":"response.something_new","x":1}', $options));
        self::assertTrue($reply->consume('not json', $options));
        self::assertSame('', $reply->text());
        self::assertNull($reply->status());
    }
}
