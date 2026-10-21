<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Anthropic;

use Phycode\Llm\Anthropic\AnthropicReply;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\SseParser;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnthropicReplyTest extends TestCase
{
    private static function read(string $fixture): array
    {
        $reply = new AnthropicReply();
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

    public function testARecordedTextReplyIsItsTextWithItsStopAndItsCounts(): void
    {
        [$reply, $emitted] = self::read('anthropic/text.sse');

        self::assertSame('Blue', $reply->text());
        self::assertSame([['text', 'Blue']], $emitted);
        self::assertSame('end_turn', $reply->stopReason());
        self::assertTrue($reply->ended());
        self::assertSame([], $reply->calls());
        self::assertSame([33, 2, 0], [$reply->usage()->promptTokens, $reply->usage()->completionTokens, $reply->usage()->cachedTokens]);
    }

    public function testARecordedToolCallIsItsStreamedInputAsTheArguments(): void
    {
        [$reply] = self::read('anthropic/tool.sse');

        self::assertSame('tool_use', $reply->stopReason());
        self::assertCount(1, $reply->calls());
        self::assertSame('get_weather', $reply->calls()[0]->name);
        self::assertSame('lwLvSFvyHEz41uuLwkWDriRJBWziM324', $reply->calls()[0]->id);
        self::assertSame('{"city":"Madrid"}', $reply->calls()[0]->arguments);
        self::assertSame('', $reply->text());
    }

    public function testARecordedReplyWithThinkingKeepsTheBlockForReplayAndEmitsItsReasoning(): void
    {
        [$reply, $emitted] = self::read('anthropic/thinking.sse');

        self::assertSame('391', $reply->text());
        self::assertNotSame('', $reply->thinking());
        self::assertSame(['thinking'], array_map(static fn (array $block): string => $block['type'], $reply->replay()));
        self::assertSame('', $reply->replay()[0]['signature'], 'llama-server sends an empty signature');
        self::assertSame('reasoning', $emitted[0][0]);
        self::assertSame(231, $reply->usage()->completionTokens);
    }

    public function testThinkingWithItsSignatureIsKeptWholeAndComesBeforeTheTextAndTheCall(): void
    {
        [$reply, $emitted] = self::read('anthropic/documented/thinking-signature.sse');

        self::assertSame('The user asks about the weather.', $reply->thinking());
        self::assertSame('Checking Madrid.', $reply->text());
        self::assertSame(
            [['type' => 'thinking', 'thinking' => 'The user asks about the weather.', 'signature' => 'EqQBCkYIARgCIkDdocSignature']],
            $reply->replay(),
        );
        self::assertSame(
            ['thinking', 'text', 'tool_use'],
            array_map(static fn (array $block): string => $block['type'], $reply->blocks()),
            'the blocks keep their order',
        );
        self::assertSame([['reasoning', 'The user asks about '], ['reasoning', 'the weather.'], ['text', 'Checking Madrid.']], $emitted);
        self::assertSame([['id' => 'toolu_01docWeather', 'name' => 'get_weather', 'arguments' => '{"city": "Madrid"}']], array_map(
            static fn ($call): array => ['id' => $call->id, 'name' => $call->name, 'arguments' => $call->arguments],
            $reply->calls(),
        ));
        self::assertSame('tool_use', $reply->stopReason());
    }

    public function testThePromptCountIsInputPlusCacheCreationPlusCacheReadAndTheCachedTokensAreReportedApart(): void
    {
        [$reply] = self::read('anthropic/documented/thinking-signature.sse');

        self::assertSame(460, $reply->usage()->promptTokens, '120 input + 40 cache creation + 300 cache read');
        self::assertSame(57, $reply->usage()->completionTokens);
        self::assertSame(300, $reply->usage()->cachedTokens);
        self::assertSame(40, $reply->usage()->cacheWriteTokens, 'the tokens written to the cache are billed higher, so they are kept apart for the cost');
        self::assertArrayNotHasKey('cacheWriteTokens', $reply->usage()->toArray(), 'the shape that the protocol with the terminal pins does not change');
    }

    public static function stopReasons(): array
    {
        return [
            'stop sequence' => ['anthropic/documented/stop-sequence.sse', 'stop_sequence'],
            'context window' => ['anthropic/documented/context-window.sse', 'model_context_window_exceeded'],
            'token limit' => ['anthropic/max-tokens.sse', 'max_tokens'],
            'refusal' => ['anthropic/documented/refusal.sse', 'refusal'],
        ];
    }

    #[DataProvider('stopReasons')]
    public function testTheStopReasonIsReadAsTheAPISendsIt(string $fixture, string $reason): void
    {
        [$reply] = self::read($fixture);

        self::assertSame($reason, $reply->stopReason());
        self::assertTrue($reply->ended());
    }

    public function testARefusalCarriesItsExplanation(): void
    {
        [$reply] = self::read('anthropic/documented/refusal.sse');

        self::assertSame('This request is declined by the safety policy.', $reply->refusal());
    }

    public function testAnErrorEventEndsTheReplyWithItsMessageAndKeepsWhatCameBefore(): void
    {
        [$reply, $emitted] = self::read('anthropic/documented/overloaded-mid-stream.sse');

        self::assertSame('Overloaded', $reply->error());
        self::assertSame('Partial ', $reply->text());
        self::assertSame([['text', 'Partial ']], $emitted);
        self::assertFalse($reply->ended());
    }

    public function testAStreamThatStopsBeforeMessageStopIsNotEnded(): void
    {
        [$reply] = self::read('anthropic/documented/no-message-stop.sse');

        self::assertFalse($reply->ended());
        self::assertNull($reply->stopReason());
        self::assertSame('The reply stops', $reply->text());
    }

    public function testPingsAndUnknownEventsChangeNothing(): void
    {
        $reply = new AnthropicReply();
        $options = new ChatOptions();

        self::assertTrue($reply->consume('{"type":"ping"}', $options));
        self::assertTrue($reply->consume('{"type":"something_new","x":1}', $options));
        self::assertTrue($reply->consume('not json', $options));
        self::assertSame('', $reply->text());
        self::assertNull($reply->stopReason());
    }

    public function testAPausedReplyKeepsItsServerBlocksWholeAndHasNoClientCall(): void
    {
        [$reply] = self::read('anthropic/documented/pause-turn.sse');

        self::assertSame('pause_turn', $reply->stopReason());
        self::assertSame([], $reply->calls(), 'a server tool use is not a call for the client');
        self::assertSame(['server_tool_use', 'web_search_tool_result'], array_map(static fn (array $block): string => $block['type'], $reply->replay()));
        self::assertSame(
            '{"type":"server_tool_use","id":"srvtoolu_01pause","name":"web_search","input":{"query":"weather Madrid"}}',
            json_encode($reply->replay()[0], JSON_THROW_ON_ERROR),
        );
    }

    public function testAToolInputThatStartedEmptyIsAnEmptyObjectOnTheWire(): void
    {
        $reply = new AnthropicReply();
        $options = new ChatOptions();
        $reply->consume('{"type":"message_start","message":{"usage":{"input_tokens":3,"output_tokens":0}}}', $options);
        $reply->consume('{"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_1","name":"list","input":{}}}', $options);
        $reply->consume('{"type":"content_block_stop","index":0}', $options);

        self::assertSame('{}', $reply->calls()[0]->arguments);
        self::assertSame('{"type":"tool_use","id":"toolu_1","name":"list","input":{}}', json_encode($reply->blocks()[0], JSON_THROW_ON_ERROR));
    }
}
