<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Agent;

use Phycode\Agent\Goal;
use Phycode\Agent\GoalJudge;
use Phycode\Support\PhycodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoalTest extends TestCase
{
    public static function arguments(): iterable
    {
        yield 'only the condition' => ['the tests pass', 'the tests pass', null, 8];
        yield 'a command in double quotes' => ['--check "vendor/bin/phpunit --stop-on-failure" the tests pass', 'the tests pass', 'vendor/bin/phpunit --stop-on-failure', 8];
        yield 'a command in single quotes' => ["--check 'make test' green", 'green', 'make test', 8];
        yield 'a one word command' => ['--check ./run-tests ok', 'ok', './run-tests', 8];
        yield 'max first' => ['--max 3 --check "make" ok', 'ok', 'make', 3];
        yield 'max after' => ['--check "make" --max 12 ok', 'ok', 'make', 12];
        yield 'equals form' => ['--max=5 ok', 'ok', null, 5];
        yield 'a condition that mentions the flags' => ['the --check flag works', 'the --check flag works', null, 8];
    }

    #[DataProvider('arguments')]
    public function testTheArgumentIsSplitIntoConditionCheckAndTurns(string $argument, string $condition, ?string $check, int $turns): void
    {
        $goal = Goal::parse($argument);

        self::assertSame([$condition, $check, $turns], [$goal->condition, $goal->check, $goal->turns]);
    }

    public static function bad(): iterable
    {
        yield 'nothing' => ['', 'usage: /goal'];
        yield 'only a flag' => ['--max 3', 'usage: /goal'];
        yield 'empty command' => ['--check "" ok', '--check needs a command'];
        yield 'zero turns' => ['--max 0 ok', '--max is a number of turns from 1 to 50'];
        yield 'too many turns' => ['--max 51 ok', '--max is a number of turns from 1 to 50'];
        yield 'not a number' => ['--max many ok', '--max is a number of turns from 1 to 50'];
    }

    #[DataProvider('bad')]
    public function testABadArgumentIsRefusedWithTheUsage(string $argument, string $message): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage($message);
        Goal::parse($argument);
    }

    public function testThePromptsNameTheGoalAndTheCommandThatMeasuresIt(): void
    {
        $plain = Goal::parse('the parser handles tabs');
        $checked = Goal::parse('--check "make test" the parser handles tabs');

        self::assertStringContainsString('Goal: the parser handles tabs', $plain->firstPrompt());
        self::assertStringContainsString('how you know the goal is met', $plain->firstPrompt());
        self::assertStringContainsString('exits with 0: make test', $checked->firstPrompt());
        self::assertStringStartsWith('The goal is not met yet: no test ran', $plain->nextPrompt('no test ran'));
    }

    public static function replies(): iterable
    {
        yield 'met' => ['{"met": true, "reason": "phpunit passed"}', true, 'phpunit passed'];
        yield 'not met' => ['{"met": false, "reason": "no test was run"}', false, 'no test was run'];
        yield 'text around' => ["Here:\n```json\n{\"met\": true, \"reason\": \"ok\"}\n```", true, 'ok'];
        yield 'no reason' => ['{"met": true}', true, 'the goal holds'];
        yield 'not json' => ['yes, it is met', false, 'the judge gave no verdict'];
        yield 'met is not a boolean' => ['{"met": "yes"}', false, 'the judge gave no verdict'];
        yield 'empty' => ['', false, 'the judge gave no verdict'];
    }

    #[DataProvider('replies')]
    public function testTheReplyOfTheJudgeIsReadStrictly(string $reply, bool $met, string $reason): void
    {
        self::assertSame([$met, $reason], GoalJudge::parse($reply));
    }
}
