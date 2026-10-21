<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\Compaction;
use Phycode\Cli\ContextReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContextReportTest extends TestCase
{
    public function testTheSummaryOfAKnownHistoryHasItsRowsInOrder(): void
    {
        $state = [
            'used' => 1200,
            'limit' => 8000,
            'threshold' => 7800,
            'percent' => 15,
            'messages' => 5,
            'breakdown' => [
                ['category' => 'system', 'tokens' => 420],
                ['category' => 'tools', 'tokens' => 1100],
                ['category' => 'messages', 'tokens' => 900],
                ['category' => 'tool results', 'tokens' => 2300],
                ['category' => 'summaries', 'tokens' => 0],
            ],
            'autoCompact' => true,
            'estimated' => false,
        ];

        self::assertSame([
            'used' => '1.2k of 8k tokens, 15%',
            'autocompact' => 'at 7.8k, on',
            'conversation' => '5 messages',
            'system' => '420 tokens',
            'tools' => '1.1k tokens',
            'messages' => '900 tokens',
            'tool results' => '2.3k tokens',
            'summaries' => '0 tokens',
        ], ContextReport::summaryRows($state));
    }

    public function testAnEstimateSaysSoAndAnUnknownWindowHasNoThreshold(): void
    {
        $state = ['used' => 300, 'limit' => null, 'threshold' => null, 'percent' => null, 'messages' => 1, 'breakdown' => [], 'autoCompact' => true, 'estimated' => true];

        self::assertSame([
            'used' => '300 tokens, the window is unknown (estimated)',
            'autocompact' => 'none: the window is unknown',
            'conversation' => '1 message',
        ], ContextReport::summaryRows($state));
    }

    public function testThePromptCacheOfTheLastReplyIsShownWhenTheProviderReportsIt(): void
    {
        $state = ['used' => 1200, 'limit' => 8000, 'threshold' => 7800, 'percent' => 15, 'messages' => 5, 'breakdown' => [], 'autoCompact' => true, 'estimated' => false];

        self::assertArrayNotHasKey('prompt cache', ContextReport::summaryRows($state + ['cache' => null]));
        self::assertSame('83% of the prompt, 1.2k of 1.4k tokens', ContextReport::summaryRows($state + ['cache' => ['cached' => 1200, 'prompt' => 1440]])['prompt cache']);
        self::assertSame('0% of the prompt, 0 of 900 tokens (a miss: the start of the request changed, or the cache expired)', ContextReport::summaryRows($state + ['cache' => ['cached' => 0, 'prompt' => 900]])['prompt cache']);
        self::assertArrayNotHasKey('prompt cache', ContextReport::summaryRows($state + ['cache' => ['cached' => 0, 'prompt' => 0]]));
    }

    public function testASessionKeepsTheCacheOfItsLastReplyUntilTheConversationChanges(): void
    {
        $harness = new \Phycode\Tests\Support\SessionHarness(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'native', 'context_limit' => 8192]]]);
        try {
            $usage = new \Phycode\Llm\Canonical\Usage(promptTokens: 1000, completionTokens: 10, cachedTokens: 900);
            $reply = new \Phycode\Llm\LlmResponse('hello', '', [], \Phycode\Llm\Canonical\FinishReason::STOP, $usage);
            $session = $harness->session($harness->kernel(new \Phycode\Tests\Unit\Cli\CountingProvider(['local' => 'qwen'], ['native'], replies: [$reply]), new \Phycode\Tests\Support\RecordingUi()));
            self::assertNull($session->contextState()['cache']);

            $session->handle('question');

            self::assertSame(['cached' => 900, 'prompt' => 1000], $session->contextState()['cache']);
            $session->handle('/clear');
            self::assertNull($session->contextState()['cache'], 'a new conversation has no reply yet');
        } finally {
            $harness->end();
        }
    }

    public function testAThresholdThatIsOffSaysSoAndABufferThatLeavesNoRoomSaysThat(): void
    {
        $off = ['used' => 2, 'limit' => 4096, 'threshold' => 3896, 'percent' => 0, 'messages' => 3, 'breakdown' => [], 'autoCompact' => false, 'autoCompactSwitch' => false, 'estimated' => false];
        $noMax = ['used' => 2, 'limit' => 4096, 'threshold' => 3896, 'percent' => 0, 'messages' => 3, 'breakdown' => [], 'autoCompact' => false, 'autoCompactSwitch' => true, 'estimated' => false];
        $noRoom = ['used' => 2, 'limit' => 4096, 'threshold' => null, 'percent' => 0, 'messages' => 3, 'breakdown' => [], 'autoCompact' => true, 'estimated' => false];

        self::assertSame('at 3.9k, off (context.auto_compact is false)', ContextReport::summaryRows($off)['autocompact']);
        self::assertSame('at 3.9k, off until context.max is set', ContextReport::summaryRows($noMax)['autocompact']);
        self::assertSame('none: the buffer leaves no room in the window', ContextReport::summaryRows($noRoom)['autocompact']);
        self::assertSame('2 of 4.1k tokens, 0%', ContextReport::summaryRows($noRoom)['used']);
    }

    public static function tokenUnits(): iterable
    {
        yield 'none' => [0, '0'];
        yield 'under a thousand' => [812, '812'];
        yield 'just under a thousand' => [999, '999'];
        yield 'a thousand' => [1000, '1k'];
        yield 'one decimal' => [1200, '1.2k'];
        yield 'the threshold of 8000' => [7800, '7.8k'];
        yield 'a whole number of thousands' => [8000, '8k'];
        yield 'a window of 16384' => [16384, '16.4k'];
        yield 'a window of 131072' => [131072, '131.1k'];
    }

    #[DataProvider('tokenUnits')]
    public function testTokensAreWrittenInKUnits(int $tokens, string $text): void
    {
        self::assertSame($text, ContextReport::tokensText($tokens));
    }

    public function testTheBreakdownSortsEveryMessageByItsCategory(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'the system prompt'],
            ['role' => 'user', 'content' => str_repeat('a', 40)],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'skill', 'arguments' => str_repeat('b', 8)]]]],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => str_repeat('c', 80)],
            ['role' => 'assistant', 'content' => str_repeat('d', 12)],
            ['role' => 'user', 'content' => "<tool_response>\nread:\nxyz\n</tool_response>"],
            ['role' => 'user', 'content' => "<tool_response>\nskill:\n" . str_repeat('e', 8) . "\n</tool_response>"],
            ['role' => 'user', 'content' => Compaction::PREFIX . "\nthe summary"],
        ];

        $tokens = array_column(ContextReport::breakdown($messages, 7, 7), 'tokens', 'category');

        self::assertSame(['system' => 7, 'tools' => 7, 'messages' => 16, 'tool results' => 42, 'summaries' => 12], $tokens);
    }

    public function testTheBreakdownListsTheCategoriesInTheirOrder(): void
    {
        $categories = array_column(ContextReport::breakdown([], 0, 0), 'category');

        self::assertSame(ContextReport::CATEGORIES, $categories);
        self::assertSame(['system', 'tools', 'messages', 'tool results', 'summaries'], $categories);
    }
}
