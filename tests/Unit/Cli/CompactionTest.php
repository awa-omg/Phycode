<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\Compaction;
use PHPUnit\Framework\TestCase;

final class CompactionTest extends TestCase
{
    private static function conversation(): array
    {
        $text = static fn (string $label): string => $label . ' ' . str_repeat('x', 100 - strlen($label) - 1);

        return [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => $text('u1')],
            ['role' => 'assistant', 'content' => $text('a1')],
            ['role' => 'user', 'content' => $text('u2')],
            ['role' => 'assistant', 'content' => $text('a2')],
            ['role' => 'user', 'content' => $text('u3')],
            ['role' => 'assistant', 'content' => $text('a3')],
        ];
    }

    public function testTheTailIsTheOldestTurnsThatFitInTheKeptTokens(): void
    {
        self::assertSame(5, Compaction::cut(self::conversation(), 60), 'one turn of 50 tokens fits, two do not');
        self::assertSame(3, Compaction::cut(self::conversation(), 110), 'two turns of 50 tokens fit');
    }

    public function testTheLastTurnStaysEvenWhenItAloneIsOverTheKeptTokens(): void
    {
        self::assertSame(5, Compaction::cut(self::conversation(), 10));
    }

    public function testNothingIsCompactedWhenEveryTurnFits(): void
    {
        self::assertNull(Compaction::cut(self::conversation(), 1000));
    }

    public function testWithNoKnownWindowTheLastTwoTurnsStay(): void
    {
        self::assertSame(3, Compaction::cut(self::conversation(), null));
        self::assertNull(Compaction::cut(array_slice(self::conversation(), 0, 5), null), 'two turns: nothing older');
    }

    public function testAConversationWithoutTurnsHasNothingToCompact(): void
    {
        self::assertNull(Compaction::cut([['role' => 'system', 'content' => 'sys']], 10));
        self::assertNull(Compaction::cut([], null));
    }

    public function testTheSummaryReplacesTheOlderTurnsAndKeepsTheRest(): void
    {
        $compacted = Compaction::apply(self::conversation(), 5, 'the summary');

        self::assertSame(['system', 'user', 'user', 'assistant'], array_column($compacted, 'role'));
        self::assertSame(Compaction::PREFIX . "\nthe summary", $compacted[1]['content']);
        self::assertStringStartsWith('u3 ', $compacted[2]['content']);
    }

    public function testTheRequestCarriesTheOlderTurnsAsPlainTextAndTheFixedSections(): void
    {
        $request = Compaction::request(array_slice(self::conversation(), 1, 4), null);

        self::assertSame(['system', 'user'], array_column($request, 'role'));
        self::assertSame(['Goal', 'Progress', 'Decisions', 'Files touched', 'Pending'], Compaction::SECTIONS);
        self::assertStringContainsString('Goal, Progress, Decisions, Files touched, Pending', (string) $request[0]['content']);
        self::assertStringStartsWith("Summarise the conversation below in those sections.", (string) $request[1]['content']);
        self::assertStringContainsString("\nuser: u1 ", (string) $request[1]['content']);
        self::assertStringNotContainsString('u3', (string) $request[1]['content'], 'the kept turn is not in the request');
        self::assertStringNotContainsString('Focus the summary', (string) $request[1]['content']);
    }

    public function testTheInstructionsFocusTheSummary(): void
    {
        $request = Compaction::request([['role' => 'user', 'content' => 'hello']], '  keep the file names  ');

        self::assertStringContainsString("Focus the summary on this: keep the file names\n", (string) $request[1]['content']);
    }

    public function testAToolCallOfAnOlderTurnIsWrittenWithItsArguments(): void
    {
        $request = Compaction::request([
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'edit', 'arguments' => '{"path":"a.php"}']]]],
        ], null);

        self::assertStringContainsString('assistant:  [called edit {"path":"a.php"}]', (string) $request[1]['content']);
    }

    public function testTheThresholdIsTheWindowLessTwoHundredTokensOrTwoPercent(): void
    {
        self::assertSame(7800, Compaction::threshold(8000, null), 'the example of the plan: 8000 compacts at 7800');
        self::assertSame(312, Compaction::threshold(512, null), 'the buffer is at least 200 tokens');
        self::assertSame(128451, Compaction::threshold(131072, null), 'two percent of a larger window');
    }

    public function testTheConfiguredBufferReplacesTheAutomaticOne(): void
    {
        self::assertSame(7500, Compaction::threshold(8000, 500));
        self::assertSame(8000, Compaction::threshold(8000, 0));
    }

    public function testABufferThatLeavesNoRoomGivesNoThreshold(): void
    {
        self::assertNull(Compaction::threshold(200, null));
        self::assertNull(Compaction::threshold(8000, 8000));
        self::assertNull(Compaction::threshold(8000, 9000));
    }

    public function testASummaryAloneIsNotSummarisedAgain(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => Compaction::PREFIX . "\nthe summary"],
            ['role' => 'user', 'content' => str_repeat('u', 100)],
            ['role' => 'assistant', 'content' => str_repeat('a', 100)],
        ];

        self::assertNull(Compaction::cut($messages, 60), 'the summary is the only older message');
        self::assertNull(Compaction::cut($messages, 1000), 'every turn fits: nothing is older');
        self::assertNull(Compaction::cut($messages, 10), 'no turn fits: the last one stays, and the summary is the only older message');
        self::assertTrue(Compaction::isSummary($messages[1]));
        self::assertFalse(Compaction::isSummary($messages[2]));
    }

    public function testASummaryIsSummarisedWithTheTurnsAfterIt(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => Compaction::PREFIX . "\nthe summary"],
            ['role' => 'user', 'content' => str_repeat('u', 100)],
            ['role' => 'assistant', 'content' => str_repeat('a', 100)],
            ['role' => 'user', 'content' => str_repeat('v', 100)],
            ['role' => 'assistant', 'content' => str_repeat('b', 100)],
        ];

        self::assertSame(4, Compaction::cut($messages, 60), 'the summary and the first turn are summarised; the last turn is kept');
    }
}
