<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Checkpoint\CheckpointStore;
use Phycode\Config\Limits;
use Phycode\Exec\ProcessRunner;
use Phycode\Security\Permissions;
use Phycode\Security\Workspace;
use Phycode\Session\Transcript;
use Phycode\Support\Cancellation;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\ChangeLog;
use Phycode\Tools\DeleteTool;
use Phycode\Tools\EditTool;
use Phycode\Tools\MoveTool;
use Phycode\Tools\Tool;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolResult;
use Phycode\Tools\WriteTool;
use PHPUnit\Framework\TestCase;

final class CheckpointWritesTest extends TestCase
{
    private string $root;
    private RecordingUi $ui;
    private CheckpointStore $store;

    protected function setUp(): void
    {
        $this->root = Kit::folder('checkpoint-writes');
        $this->ui = new RecordingUi();
        Kit::write($this->root . '/src/a.txt', "A0\n");
        Kit::write($this->root . '/src/b.txt', "B0\n");
        $this->store = new CheckpointStore(new Workspace($this->root));
        $this->store->begin('turn-1');
    }

    public function testWriteKeepsTheContentItReplacesAndMarksANewFileAbsent(): void
    {
        self::assertTrue($this->call(new WriteTool(), ['path' => 'src/a.txt', 'content' => "A1\n"])->ok);
        self::assertTrue($this->call(new WriteTool(), ['path' => 'src/new.txt', 'content' => "N1\n"])->ok);

        self::assertSame([
            ['path' => 'src/a.txt', 'before' => "A0\n", 'after' => "A1\n"],
            ['path' => 'src/new.txt', 'before' => null, 'after' => "N1\n"],
        ], $this->store->turnContents('turn-1'));
    }

    public function testEditKeepsTheContentItReplaces(): void
    {
        self::assertTrue($this->call(new EditTool(), ['path' => 'src/a.txt', 'old_string' => 'A0', 'new_string' => 'A1'])->ok);

        self::assertSame([['path' => 'src/a.txt', 'before' => "A0\n", 'after' => "A1\n"]], $this->store->turnContents('turn-1'));
    }

    public function testDeleteKeepsTheContentItRemoves(): void
    {
        self::assertTrue($this->call(new DeleteTool(), ['path' => 'src/b.txt'])->ok);

        self::assertSame([['path' => 'src/b.txt', 'before' => "B0\n", 'after' => null]], $this->store->turnContents('turn-1'));
    }

    public function testMoveKeepsTheSourceContentAndMarksTheTargetAbsent(): void
    {
        self::assertTrue($this->call(new MoveTool(), ['from' => 'src/a.txt', 'to' => 'moved/a.txt'])->ok);

        self::assertSame([
            ['path' => 'src/a.txt', 'before' => "A0\n", 'after' => null],
            ['path' => 'moved/a.txt', 'before' => null, 'after' => "A0\n"],
        ], $this->store->turnContents('turn-1'));
    }

    public function testMovingAFolderIsUndoneFileByFile(): void
    {
        Kit::write($this->root . '/src/dir/x.txt', "X0\n");
        Kit::write($this->root . '/src/dir/sub/y.txt', "Y0\n");

        self::assertTrue($this->call(new MoveTool(), ['from' => 'src/dir', 'to' => 'moved'])->ok);
        $changed = $this->store->changedFiles('turn-1');
        sort($changed);
        self::assertSame(['moved/sub/y.txt', 'moved/x.txt', 'src/dir/sub/y.txt', 'src/dir/x.txt'], $changed);

        $this->store->undo('turn-1');

        self::assertSame("X0\n", $this->content('src/dir/x.txt'));
        self::assertSame("Y0\n", $this->content('src/dir/sub/y.txt'));
        self::assertNull($this->content('moved/x.txt'));
        self::assertNull($this->content('moved/sub/y.txt'));
    }

    public function testALinkIsRefusedInsideATurnAndStaysWhereItIs(): void
    {
        symlink('a.txt', $this->root . '/src/link.txt');

        $result = $this->call(new DeleteTool(), ['path' => 'src/link.txt']);

        self::assertFalse($result->ok);
        self::assertStringContainsString('symbolic link', $result->content);
        self::assertTrue(is_link($this->root . '/src/link.txt'));
    }

    public function testNoWriteToolChangesAFileWhenItCannotKeepItsCopy(): void
    {
        Kit::write($this->root . '/.phycode/checkpoints/index.json', 'not an index');

        $results = [
            $this->call(new WriteTool(), ['path' => 'src/a.txt', 'content' => "A1\n"]),
            $this->call(new EditTool(), ['path' => 'src/a.txt', 'old_string' => 'A0', 'new_string' => 'A1']),
            $this->call(new MoveTool(), ['from' => 'src/a.txt', 'to' => 'moved.txt']),
            $this->call(new DeleteTool(), ['path' => 'src/a.txt']),
        ];

        foreach ($results as $result) {
            self::assertFalse($result->ok);
            self::assertStringContainsString('nothing was', $result->content);
        }
        self::assertSame("A0\n", $this->content('src/a.txt'));
        self::assertNull($this->content('moved.txt'));
    }

    public function testAWriteWithNoTurnInProgressGoesOnWithoutACopy(): void
    {
        $this->store = new CheckpointStore(new Workspace($this->root));

        self::assertTrue($this->call(new WriteTool(), ['path' => 'src/a.txt', 'content' => "A1\n"])->ok);

        self::assertSame("A1\n", $this->content('src/a.txt'));
        self::assertSame([], $this->store->turns());
    }

    public function testASubagentContextKeepsTheStoreOfTheTurn(): void
    {
        $child = $this->context()->forAgent(Kit::agent('explore'), RunPolicy::primary(Kit::agent(), 'yolo'), new Cancellation(), 'test', new ProcessRunner());

        self::assertSame($this->store, $child->checkpoints);
    }

    private function call(Tool $tool, array $args): ToolResult
    {
        return $tool->execute($args, $this->context());
    }

    private function context(): ToolContext
    {
        return new ToolContext(
            new Workspace($this->root),
            new Permissions($this->ui),
            new ProcessRunner(),
            $this->ui,
            Transcript::memory(),
            new Limits(),
            Kit::agent(),
            RunPolicy::primary(Kit::agent(), 'yolo'),
            new Cancellation(),
            'test',
            null,
            new ChangeLog(),
            new ProcessRunner(),
            $this->store,
        );
    }

    private function content(string $relative): ?string
    {
        $file = $this->root . '/' . $relative;

        return is_file($file) ? (string) file_get_contents($file) : null;
    }
}
