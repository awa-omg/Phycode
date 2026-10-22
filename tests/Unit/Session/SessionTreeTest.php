<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Session;

use Phycode\Session\SessionFork;
use Phycode\Session\SessionTree;
use Phycode\Support\PhycodeException;
use PHPUnit\Framework\TestCase;

final class SessionTreeTest extends TestCase
{
    private static function row(string $id, string $title, int $messages, ?string $parent = null): array
    {
        return ['id' => $id, 'title' => $title, 'updatedAt' => '2026-10-01T10:00:00.000000Z', 'messages' => $messages, 'parent' => $parent];
    }

    public function testEachForkIsDrawnUnderTheSessionItCameFrom(): void
    {
        $tree = SessionTree::render([
            self::row('c', 'Try B', 5, 'a'),
            self::row('a', 'Fix the parser', 3),
            self::row('b', 'Try A', 4, 'a'),
            self::row('d', 'Try A again', 6, 'b'),
            self::row('e', 'Another job', 1),
        ], 'd');

        self::assertSame(<<<'TREE'
        a  Fix the parser  (3 messages)
        ├─ b  Try A  (4 messages)
        │  └─ d  Try A again  (6 messages)  <- current
        └─ c  Try B  (5 messages)
        e  Another job  (1 message)
        TREE, $tree);
    }

    public function testASessionWhoseParentWasDeletedIsARootAndAnEmptyTitleIsSaid(): void
    {
        $tree = SessionTree::render([self::row('b', '', 0, 'gone')], 'x');

        self::assertSame('b  no message yet  (0 messages)', $tree);
        self::assertSame('no sessions yet', SessionTree::render([], 'x'));
    }

    public function testACycleInTheFilesDoesNotLoopAndShowsEverySessionOnce(): void
    {
        $tree = SessionTree::render([self::row('a', 'A', 1, 'b'), self::row('b', 'B', 1, 'a')], 'a');

        self::assertSame(2, substr_count($tree, '(1 message)'));
    }

    public function testALongTitleIsCut(): void
    {
        $tree = SessionTree::render([self::row('a', str_repeat('word ', 30), 1)], 'x');

        self::assertLessThan(120, mb_strlen($tree));
    }

    public function testForkKeepsTheFirstUserMessagesWithTheirReplies(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 's'],
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'two'],
            ['role' => 'assistant', 'content' => 'a2'],
            ['role' => 'user', 'content' => 'three'],
            ['role' => 'assistant', 'content' => 'a3'],
        ];

        self::assertSame(3, SessionFork::userTurns($messages));
        self::assertSame(['s', 'one', 'a1'], array_column(SessionFork::keep($messages, 1), 'content'));
        self::assertSame(['s', 'one', 'a1', 'two', 'a2'], array_column(SessionFork::keep($messages, 2), 'content'));
        self::assertSame($messages, SessionFork::keep($messages, 3));
        self::assertSame($messages, SessionFork::keep($messages, 99));
    }

    public function testForkKeepsAToolCallTogetherWithItsResults(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 's'],
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'x']]],
            ['role' => 'tool', 'content' => 'result', 'tool_call_id' => 'x'],
            ['role' => 'assistant', 'content' => 'done'],
            ['role' => 'user', 'content' => 'two'],
        ];

        self::assertSame(['s', 'one', '', 'result', 'done'], array_column(SessionFork::keep($messages, 1), 'content'));
    }

    public function testForkNeedsAtLeastOneMessage(): void
    {
        $this->expectException(PhycodeException::class);
        SessionFork::keep([['role' => 'system', 'content' => 's']], 0);
    }
}
