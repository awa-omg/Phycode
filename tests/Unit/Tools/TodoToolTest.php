<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\TodoList;
use Phycode\Tools\TodoTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TodoToolTest extends TestCase
{
    private TodoList $list;
    private TodoTool $tool;

    protected function setUp(): void
    {
        $this->list = new TodoList();
        $this->tool = new TodoTool($this->list);
    }

    private function context(): \Phycode\Tools\ToolContext
    {
        return Kit::context(Kit::folder('todo-tool'), Kit::agent(), Kit::policy('ask'), new RecordingUi());
    }

    public function testTheModelWritesTheWholeListAndGetsItBackWithMarks(): void
    {
        $result = $this->tool->execute(['todos' => [
            ['content' => 'Read the config', 'status' => 'completed'],
            ['content' => 'Change the loader', 'status' => 'in_progress'],
            ['content' => 'Run the tests', 'status' => 'pending'],
        ]], $this->context());

        self::assertTrue($result->ok);
        self::assertSame("1. [x] Read the config\n2. [>] Change the loader\n3. [ ] Run the tests\n(1 of 3 done)", $result->content);
        self::assertSame(['done' => 1, 'total' => 3], $result->meta);
        self::assertSame('Change the loader', $this->list->current());
    }

    public function testWithoutArgumentsItReadsTheListAndWritingAgainReplacesIt(): void
    {
        self::assertSame('no tasks yet', $this->tool->execute([], $this->context())->content);
        $this->tool->execute(['todos' => [['content' => 'One', 'status' => 'pending']]], $this->context());
        self::assertSame("1. [ ] One\n(0 of 1 done)", $this->tool->execute([], $this->context())->content);

        $this->tool->execute(['todos' => [['content' => 'Two', 'status' => 'completed']]], $this->context());

        self::assertSame("1. [x] Two\n(1 of 1 done)", $this->tool->execute([], $this->context())->content);
        self::assertNull($this->list->current());
        $this->tool->execute(['todos' => []], $this->context());
        self::assertTrue($this->list->isEmpty());
    }

    public static function statuses(): iterable
    {
        yield 'done' => ['done', 'completed'];
        yield 'doing' => ['doing', 'in_progress'];
        yield 'in-progress' => ['in-progress', 'in_progress'];
        yield 'todo' => ['todo', 'pending'];
        yield 'upper case with spaces' => [' Completed ', 'completed'];
    }

    #[DataProvider('statuses')]
    public function testTheWordsThatSmallModelsUseForAStatusAreUnderstood(string $written, string $stored): void
    {
        $this->tool->execute(['todos' => [['content' => 'x', 'status' => $written]]], $this->context());

        self::assertSame($stored, $this->list->items()[0]['status']);
    }

    public static function refused(): iterable
    {
        yield 'a string' => ['do things', 'todos must be a list of tasks'];
        yield 'a task without content' => [[['status' => 'pending']], 'task 1 needs a content'];
        yield 'an empty content' => [[['content' => '  ', 'status' => 'pending']], 'task 1 needs a content'];
        yield 'a status that does not exist' => [[['content' => 'a', 'status' => 'pending'], ['content' => 'b', 'status' => 'blocked']], 'task 2 has the status blocked'];
        yield 'too many tasks' => [array_fill(0, 41, ['content' => 'x', 'status' => 'pending']), 'at most 40 tasks'];
        yield 'not a list' => [['a' => ['content' => 'x', 'status' => 'pending']], 'todos must be a list'];
    }

    #[DataProvider('refused')]
    public function testAListThatCannotBeOneIsRefusedAndTheOldListStays(mixed $todos, string $message): void
    {
        $this->tool->execute(['todos' => [['content' => 'keep me', 'status' => 'pending']]], $this->context());

        $result = $this->tool->execute(['todos' => $todos], $this->context());

        self::assertFalse($result->ok);
        self::assertStringContainsString($message, $result->content);
        self::assertSame('keep me', $this->list->items()[0]['content']);
    }

    public function testALongTaskIsCutAndSavedListsComeBack(): void
    {
        $this->list->replace([['content' => str_repeat('word ', 80), 'status' => 'pending']]);
        self::assertLessThanOrEqual(200, mb_strlen($this->list->items()[0]['content']));

        $copy = new TodoList();
        $copy->restore($this->list->toArray());
        self::assertSame($this->list->toArray(), $copy->toArray());

        $copy->restore('garbage');
        self::assertTrue($copy->isEmpty());
        $copy->restore([['content' => 'ok', 'status' => 'nonsense']]);
        self::assertTrue($copy->isEmpty(), 'a saved list that does not read back is dropped, not half kept');
    }

    public function testTheSummaryIsOneLineAndOnlyTheMainAgentHasTheTool(): void
    {
        self::assertSame('todo (read)', $this->tool->summarize([]));
        self::assertSame('todo 1/2 done', $this->tool->summarize(['todos' => [['content' => 'a', 'status' => 'completed'], ['content' => 'b', 'status' => 'pending']]]));
        self::assertSame(\Phycode\Tools\ToolCategory::Read, $this->tool->category());
        self::assertTrue($this->tool->isAvailable(new RunPolicy(null, [], [], 'ask', 0)));
        self::assertFalse($this->tool->isAvailable(new RunPolicy(null, [], [], 'ask', 1)));
    }
}
