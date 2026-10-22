<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Memory\MemoryStore;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\MemorySaveTool;
use Phycode\Tools\MemoryTool;
use Phycode\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

final class MemoryToolsTest extends TestCase
{
    private string $root;
    private MemoryStore $store;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('memory-tools'));
        $this->store = new MemoryStore($this->root, Kit::folder('memory-tools-config'));
    }

    private function context(): ToolContext
    {
        return Kit::context($this->root, Kit::agent(), Kit::policy('ask'), new RecordingUi());
    }

    private function save(array $arguments, string $autonomy = 'auto'): \Phycode\Tools\ToolResult
    {
        return (new MemorySaveTool($this->store, true, $autonomy))->execute($arguments, $this->context());
    }

    public function testASavedNoteIsListedAndReadInFull(): void
    {
        $saved = $this->save(['action' => 'save', 'scope' => 'project', 'name' => 'Test Command', 'description' => 'How the tests run', 'body' => 'vendor/bin/phpunit']);
        $reader = new MemoryTool($this->store, true);

        self::assertTrue($saved->ok);
        self::assertSame('saved project/test-command', $saved->content);
        self::assertSame('project/test-command: How the tests run', $reader->execute(['action' => 'list'], $this->context())->content);
        self::assertSame("project/test-command: How the tests run\n\nvendor/bin/phpunit", $reader->execute(['action' => 'read', 'scope' => 'project', 'name' => 'test-command'], $this->context())->content);
    }

    public function testListingOneScopeShowsOnlyThatScopeAndAnEmptyStoreSaysSo(): void
    {
        $reader = new MemoryTool($this->store, true);
        self::assertSame('no notes are saved', $reader->execute(['action' => 'list'], $this->context())->content);

        $this->save(['action' => 'save', 'scope' => 'project', 'name' => 'a', 'description' => 'in project', 'body' => 'x']);
        $this->save(['action' => 'save', 'scope' => 'user', 'name' => 'b', 'description' => 'for the user', 'body' => 'x']);

        self::assertSame('user/b: for the user', $reader->execute(['action' => 'list', 'scope' => 'user'], $this->context())->content);
        self::assertSame("project/a: in project\nuser/b: for the user", $reader->execute(['action' => 'list'], $this->context())->content);
    }

    public function testReadingNeedsAScopeAndANoteThatExists(): void
    {
        $reader = new MemoryTool($this->store, true);

        self::assertSame('read needs scope: project or user', $reader->execute(['action' => 'read', 'name' => 'x'], $this->context())->content);
        self::assertStringContainsString('no note named project/x', $reader->execute(['action' => 'read', 'scope' => 'project', 'name' => 'x'], $this->context())->content);
        self::assertStringContainsString("scope is project or user, not 'team'", $reader->execute(['action' => 'list', 'scope' => 'team'], $this->context())->content);
        self::assertSame('action must be list or read', $reader->execute(['action' => 'write'], $this->context())->content);
        self::assertSame("missing required argument 'action'", $reader->execute([], $this->context())->content);
    }

    public function testForgettingDeletesTheNoteOnceAndThenSaysItIsNotThere(): void
    {
        $this->save(['action' => 'save', 'scope' => 'project', 'name' => 'old', 'description' => 'gone soon', 'body' => 'x']);

        self::assertSame('forgot project/old', $this->save(['action' => 'forget', 'scope' => 'project', 'name' => 'old'])->content);
        $again = $this->save(['action' => 'forget', 'scope' => 'project', 'name' => 'old']);
        self::assertFalse($again->ok);
        self::assertStringContainsString('no note named project/old', $again->content);
    }

    public function testASecretOrAMissingFieldIsAFailureThatSaysWhy(): void
    {
        $secret = $this->save(['action' => 'save', 'scope' => 'project', 'name' => 'k', 'description' => 'the key', 'body' => 'sk-abcdefghijklmnop']);
        $missing = $this->save(['action' => 'save', 'scope' => 'project', 'name' => 'k', 'description' => 'no body']);
        $wrongAction = $this->save(['action' => 'rename', 'scope' => 'project', 'name' => 'k']);
        $wrongScope = $this->save(['action' => 'save', 'scope' => 'team', 'name' => 'k', 'description' => 'd', 'body' => 'b']);

        self::assertFalse($secret->ok);
        self::assertStringContainsString('looks like it holds a key', $secret->content);
        self::assertSame("missing required argument 'body'", $missing->content);
        self::assertSame('action must be save or forget', $wrongAction->content);
        self::assertStringContainsString("scope is project or user, not 'team'", $wrongScope->content);
        self::assertSame([], $this->store->all('project'));
    }

    public function testAskingFirstIsForcedUnlessTheAutonomyIsAuto(): void
    {
        $asking = new MemorySaveTool($this->store, true, 'ask');
        $auto = new MemorySaveTool($this->store, true, 'auto');

        self::assertTrue($asking->forcesApproval([]));
        self::assertFalse($asking->autoApproved());
        self::assertFalse($auto->forcesApproval([]));
        self::assertTrue($auto->autoApproved());
    }

    public function testTheApprovalShowsTheNoteAsAFileDiffAndAReplacementShowsWhatChanged(): void
    {
        $tool = new MemorySaveTool($this->store, true, 'ask');
        $arguments = ['action' => 'save', 'scope' => 'project', 'name' => 'tests', 'description' => 'How the tests run', 'body' => 'phpunit'];

        $first = $tool->preview($arguments, $this->context());
        $tool->execute($arguments, $this->context());
        $second = $tool->preview([...$arguments, 'body' => 'phpunit --stop-on-failure'], $this->context());

        self::assertSame('memory/project/tests.md', $first['path']);
        self::assertGreaterThan(0, $first['added']);
        self::assertSame(0, $first['removed']);
        self::assertSame([1, 1], [$second['added'], $second['removed']]);
        self::assertNull($tool->preview(['action' => 'save'], $this->context()), 'a call that cannot run has no preview');
    }

    public function testValidationRaisesTheSameReasonsTheExecutionReports(): void
    {
        $tool = new MemorySaveTool($this->store, true);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('description is one line');
        $tool->validate(['action' => 'save', 'scope' => 'project', 'name' => 'a', 'description' => '', 'body' => 'b'], $this->context());
    }

    public function testBothToolsAreOfferedOnlyToTheMainAgentAndOnlyWhenMemoryIsOn(): void
    {
        $main = Kit::policy('ask');
        $child = RunPolicy::child($main, Kit::agent('explore', 'subagent'));

        foreach ([new MemoryTool($this->store, true), new MemorySaveTool($this->store, true)] as $tool) {
            self::assertTrue($tool->isAvailable($main));
            self::assertFalse($tool->isAvailable($child));
        }
        self::assertFalse((new MemoryTool($this->store, false))->isAvailable($main));
        self::assertFalse((new MemorySaveTool($this->store, false))->isAvailable($main));
    }

    public function testThePlanModeReadsNotesButNeverWritesThem(): void
    {
        self::assertSame(\Phycode\Tools\ToolCategory::Read, (new MemoryTool($this->store, true))->category());
        self::assertSame(\Phycode\Tools\ToolCategory::Write, (new MemorySaveTool($this->store, true))->category());
    }
}
