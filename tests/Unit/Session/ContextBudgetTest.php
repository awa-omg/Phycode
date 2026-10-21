<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Session;

use Phycode\Session\ContextBudget;
use PHPUnit\Framework\TestCase;

final class ContextBudgetTest extends TestCase
{
    private static function conversation(array $sizes): array
    {
        $messages = [['role' => 'system', 'content' => 's']];
        foreach ($sizes as $size) {
            $messages[] = ['role' => 'user', 'content' => str_repeat('u', $size)];
            $messages[] = ['role' => 'assistant', 'content' => str_repeat('a', $size)];
        }

        return $messages;
    }

    public function testTheEstimateIsACountOfTheCharactersAFourth(): void
    {
        self::assertSame(600, ContextBudget::estimate(self::conversation([400, 400, 400])), '2401 characters are 600 tokens');
    }

    public function testTurnStartsAreTheUsersOwnMessagesNotTheResultsOrNotesInsideATurn(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 's'],
            ['role' => 'user', 'content' => 'first'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'file'],
            ['role' => 'user', 'content' => '<tool_response>file</tool_response>'],
            ['role' => 'user', 'content' => 'keep going'],
            ['role' => 'assistant', 'content' => 'done'],
            ['role' => 'user', 'content' => 'second'],
            ['role' => 'assistant', 'content' => 'ok'],
        ];

        self::assertSame([1, 7], ContextBudget::turnStarts($messages));
    }

    public function testTheEstimateCountsTheTextAndTheToolCalls(): void
    {
        self::assertSame(2, ContextBudget::estimate([['role' => 'user', 'content' => 'abcdefgh']]));

        $call = ['role' => 'assistant', 'content' => '', 'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{"path":"a"}']],
        ]];
        self::assertSame(intdiv(mb_strlen('read') + mb_strlen('{"path":"a"}'), 4), ContextBudget::estimate([$call]));
    }
}
