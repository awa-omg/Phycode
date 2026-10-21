<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ollama;

use Phycode\Llm\ChatOptions;
use Phycode\Llm\Ollama\OllamaReply;
use Phycode\Tests\Support\OllamaServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OllamaReplyTest extends TestCase
{
    public function testTextAndReasoningAreKeptApartAndEmittedInOrder(): void
    {
        $events = [];
        $options = new ChatOptions(onEvent: static function (string $type, string $text) use (&$events): void {
            $events[] = [$type, $text];
        });
        $reply = new OllamaReply();

        $reply->consume('{"message":{"role":"assistant","content":"","thinking":"Hmm. "},"done":false}', $options);
        $reply->consume('{"message":{"role":"assistant","content":"Hi"},"done":false}', $options);
        $reply->consume('{"message":{"role":"assistant","content":""},"done":true,"done_reason":"stop"}', $options);

        self::assertSame('Hi', $reply->content());
        self::assertSame('Hmm. ', $reply->reasoning());
        self::assertSame([['reasoning', 'Hmm. '], ['text', 'Hi']], $events);
        self::assertTrue($reply->done());
    }

    public function testNotDoneUntilTheFinalLine(): void
    {
        $reply = new OllamaReply();

        $reply->consume('{"message":{"role":"assistant","content":"x"},"done":false}', new ChatOptions());

        self::assertFalse($reply->done());
        self::assertNull($reply->doneReason());
    }

    public function testCallsAreNumberedFromOneWithinEachReplyAcrossLines(): void
    {
        $reply = new OllamaReply();
        $options = new ChatOptions();
        $reply->consume('{"message":{"role":"assistant","content":"","tool_calls":[{"function":{"name":"read","arguments":{"path":"a"}}},{"function":{"name":"read","arguments":{"path":"b"}}}]},"done":false}', $options);
        $reply->consume('{"message":{"role":"assistant","content":"","tool_calls":[{"function":{"name":"time","arguments":{}}}]},"done":false}', $options);

        $calls = $reply->calls();

        self::assertSame(['call_1', 'call_2', 'call_3'], array_map(static fn ($call): string => $call->id, $calls));
        self::assertSame(['read', 'read', 'time'], array_map(static fn ($call): string => $call->name, $calls));
        self::assertSame(['{"path":"a"}', '{"path":"b"}', '{}'], array_map(static fn ($call): string => $call->arguments, $calls));

        $next = new OllamaReply();
        $next->consume('{"message":{"role":"assistant","content":"","tool_calls":[{"function":{"name":"time","arguments":{}}}]},"done":true,"done_reason":"stop"}', $options);
        self::assertSame('call_1', $next->calls()[0]->id, 'a new reply starts again at call_1');
    }

    public function testArgumentsKeepTheirJsonStructureIncludingEmptyObjects(): void
    {
        $reply = new OllamaReply();
        $reply->consume('{"message":{"role":"assistant","content":"","tool_calls":[{"function":{"name":"edit","arguments":{"options":{},"tags":[],"path":"a/b"}}}]},"done":false}', new ChatOptions());

        self::assertSame('{"options":{},"tags":[],"path":"a/b"}', $reply->calls()[0]->arguments);
    }

    public function testAStringArgumentIsTakenAsItIs(): void
    {
        $reply = new OllamaReply();
        $reply->consume('{"message":{"role":"assistant","content":"","tool_calls":[{"function":{"name":"time","arguments":"{\"zone\":\"UTC\"}"}}]},"done":false}', new ChatOptions());

        self::assertSame('{"zone":"UTC"}', $reply->calls()[0]->arguments);
    }

    public function testACallWithoutANameIsSkipped(): void
    {
        $reply = new OllamaReply();
        $reply->consume('{"message":{"role":"assistant","content":"","tool_calls":[{"function":{"arguments":{}}},{"function":{"name":"time"}}]},"done":false}', new ChatOptions());

        self::assertCount(1, $reply->calls());
        self::assertSame('time', $reply->calls()[0]->name);
        self::assertSame('{}', $reply->calls()[0]->arguments, 'missing arguments are {}');
    }

    public function testUsageAndTokensPerSecondComeFromTheFinalLine(): void
    {
        $reply = new OllamaReply();
        $reply->consume('{"message":{"role":"assistant","content":""},"done":true,"done_reason":"stop","prompt_eval_count":26,"prompt_eval_cached_count":20,"eval_count":12,"eval_duration":1500000000}', new ChatOptions());

        $usage = $reply->usage();

        self::assertSame(26, $usage->promptTokens, 'the cached tokens are inside the count, and are not added to it');
        self::assertSame(12, $usage->completionTokens);
        self::assertSame(20, $usage->cachedTokens);
        self::assertSame(8.0, $usage->tokensPerSecond);
    }

    public function testTheCountOfARepeatedPromptHoldsAllOfItWhenNoCachedCountIsSent(): void
    {
        $reply = new OllamaReply();
        foreach (array_filter(explode("\n", OllamaServer::fixture('live/chat-cached-second.ndjson'))) as $line) {
            $reply->consume($line, new ChatOptions());
        }

        self::assertTrue($reply->done());
        self::assertSame(479, $reply->usage()->promptTokens, 'served from the cache, and the count still holds all 479 tokens');
        self::assertNull($reply->usage()->cachedTokens);
    }

    public function testACallKeepsTheIdTheServerSendsAndACallWithoutOneIsNumbered(): void
    {
        $reply = new OllamaReply();

        $reply->consume('{"message":{"role":"assistant","content":"","tool_calls":[{"id":"call_meht2eww","function":{"index":0,"name":"get_weather","arguments":{"city":"Madrid"}}},{"function":{"name":"time","arguments":{}}}]},"done":false}', new ChatOptions());

        self::assertSame(['call_meht2eww', 'call_2'], array_map(static fn ($call): string => $call->id, $reply->calls()));
    }

    public static function speedsWithoutTheNumbers(): iterable
    {
        yield 'no duration' => ['"eval_count":12'];
        yield 'zero duration' => ['"eval_count":12,"eval_duration":0'];
        yield 'no count' => ['"eval_duration":1500000000'];
        yield 'zero count' => ['"eval_count":0,"eval_duration":1500000000'];
    }

    #[DataProvider('speedsWithoutTheNumbers')]
    public function testTokensPerSecondIsNullUnlessBothNumbersArePositive(string $counts): void
    {
        $reply = new OllamaReply();
        $reply->consume('{"message":{"role":"assistant","content":""},"done":true,"done_reason":"stop",' . $counts . '}', new ChatOptions());

        self::assertNull($reply->usage()->tokensPerSecond);
    }

    public function testAnErrorLineEndsTheReplyAndKeepsTheMessage(): void
    {
        $reply = new OllamaReply();

        self::assertFalse($reply->consume('{"error":"an error was encountered while running the model"}', new ChatOptions()));

        self::assertSame('an error was encountered while running the model', $reply->error());
        self::assertFalse($reply->done());
    }

    public function testALineThatIsNotJsonOrNotAnObjectIsSkipped(): void
    {
        $reply = new OllamaReply();

        self::assertTrue($reply->consume('not json', new ChatOptions()));
        self::assertTrue($reply->consume('[1,2]', new ChatOptions()));
        self::assertTrue($reply->consume('null', new ChatOptions()));

        self::assertSame('', $reply->content());
        self::assertNull($reply->error());
    }
}
