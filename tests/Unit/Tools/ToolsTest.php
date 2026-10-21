<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Exec\ProcessRunner;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\DeleteTool;
use Phycode\Tools\EditTool;
use Phycode\Tools\GrepTool;
use Phycode\Tools\ListTool;
use Phycode\Tools\MoveTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\ToolContext;
use Phycode\Tools\WriteTool;
use PHPUnit\Framework\TestCase;

final class ToolsTest extends TestCase
{
    private string $root;
    private ToolContext $context;

    protected function setUp(): void
    {
        $this->root = Kit::folder('tools');
        Kit::write($this->root . '/src/a.php', "<?php\nline2\nline3\nline4\nline5\n");
        Kit::write($this->root . '/node_modules/pkg/index.js', "module.exports = 1;\n");
        $this->context = Kit::context($this->root, Kit::agent(), RunPolicy::primary(Kit::agent(), 'yolo'), new RecordingUi());
    }

    public function testReadNumbersLinesAndPaginates(): void
    {
        $result = (new ReadTool())->execute(['path' => 'src/a.php', 'offset' => 2, 'limit' => 2], $this->context);

        self::assertTrue($result->ok);
        self::assertStringContainsString("     2  line2\n     3  line3", $result->content);
        self::assertStringContainsString('call read with offset=4 to continue', $result->content);
    }

    public function testReadRefusesDirectoriesAndBinaryFiles(): void
    {
        Kit::write($this->root . '/blob.bin', "abc\0def");

        self::assertFalse((new ReadTool())->execute(['path' => 'src'], $this->context)->ok);
        self::assertStringContainsString('binary', (new ReadTool())->execute(['path' => 'blob.bin'], $this->context)->content);
    }

    public function testEditRequiresAUniqueMatchUnlessReplaceAll(): void
    {
        Kit::write($this->root . '/twice.txt', "x\nx\n");
        $edit = new EditTool();

        $refused = $edit->execute(['path' => 'twice.txt', 'old_string' => 'x', 'new_string' => 'y'], $this->context);
        self::assertFalse($refused->ok);
        self::assertStringContainsString('matches 2 places', $refused->content);

        $done = $edit->execute(['path' => 'twice.txt', 'old_string' => 'x', 'new_string' => 'y', 'replace_all' => true], $this->context);
        self::assertTrue($done->ok);
        self::assertSame("y\ny\n", file_get_contents($this->root . '/twice.txt'));
    }

    public function testEditNotFoundPointsAtTheNearestLine(): void
    {
        Kit::write($this->root . '/n.txt', "alpha\nbeta gamma\n");

        $result = (new EditTool())->execute(['path' => 'n.txt', 'old_string' => "beta\nWRONG", 'new_string' => 'z'], $this->context);

        self::assertFalse($result->ok);
        self::assertStringContainsString('appears at line 2', $result->content);
        self::assertSame("alpha\nbeta gamma\n", file_get_contents($this->root . '/n.txt'), 'a failed edit changes nothing');
    }

    public function testEditKeepsWindowsLineEndings(): void
    {
        Kit::write($this->root . '/crlf.txt', "a\r\nb\r\n");

        $result = (new EditTool())->execute(['path' => 'crlf.txt', 'old_string' => "a\nb", 'new_string' => "c\nd"], $this->context);

        self::assertTrue($result->ok);
        self::assertSame("c\r\nd\r\n", file_get_contents($this->root . '/crlf.txt'));
    }

    public function testWriteCreatesFoldersAndReportsOverwrites(): void
    {
        $write = new WriteTool();

        self::assertStringStartsWith('created', $write->execute(['path' => 'new/dir/f.txt', 'content' => "hi\n"], $this->context)->content);
        self::assertStringStartsWith('overwrote', $write->execute(['path' => 'new/dir/f.txt', 'content' => 'again'], $this->context)->content);
        self::assertSame('again', file_get_contents($this->root . '/new/dir/f.txt'));
    }

    public function testEditThatBreaksPhpSyntaxIsRolledBack(): void
    {
        $original = "<?php\n\nfunction ok() {\n    return 1;\n}\n";
        Kit::write($this->root . '/Broken.php', $original);

        $result = (new EditTool())->execute(['path' => 'Broken.php', 'old_string' => 'return 1;', 'new_string' => 'return 1'], $this->context);

        self::assertFalse($result->ok);
        self::assertStringContainsString('left as it was', $result->content);
        self::assertSame($original, file_get_contents($this->root . '/Broken.php'));
    }

    public function testWriteOfBrokenPythonIsRefusedAndNoFileIsCreated(): void
    {
        if (ProcessRunner::which('python3') === null) {
            self::markTestSkipped('python3 is not installed');
        }

        $result = (new WriteTool())->execute(['path' => 'bad.py', 'content' => "def f(:\n    pass\n"], $this->context);

        self::assertFalse($result->ok);
        self::assertFileDoesNotExist($this->root . '/bad.py');
    }

    public function testBrokenJsonIsKeptButReported(): void
    {
        $result = (new WriteTool())->execute(['path' => 'data.json', 'content' => '{"a": 1,}'], $this->context);

        self::assertTrue($result->ok);
        self::assertStringContainsString('syntax check FAILED (json)', $result->content);
        self::assertFileExists($this->root . '/data.json');
    }

    public function testValidPhpWriteReportsItsSyntaxCheck(): void
    {
        $result = (new WriteTool())->execute(['path' => 'ok.php', 'content' => "<?php\necho 1;\n"], $this->context);

        self::assertTrue($result->ok);
        self::assertStringContainsString('syntax check (php -l): ok', $result->content);
    }

    public function testGrepDoesNotReadThroughSymbolicLinksOutsideTheProject(): void
    {
        $outside = Kit::folder('outside');
        Kit::write($outside . '/secret.txt', "token-xyz\n");
        symlink($outside . '/secret.txt', $this->root . '/linked.txt');

        self::assertSame('no matches', (new GrepTool())->execute(['pattern' => 'token-xyz'], $this->context)->content);
    }

    public function testOnlySavedChangesAreRecorded(): void
    {
        (new WriteTool())->execute(['path' => 'saved.php', 'content' => "<?php\necho 1;\n"], $this->context);
        $broken = (new WriteTool())->execute(['path' => 'broken.php', 'content' => "<?php\necho 1\n"], $this->context);

        self::assertFalse($broken->ok);
        self::assertSame(['saved.php'], $this->context->changes->since(0));
    }

    public function testWriteRefusesGitFolder(): void
    {
        $this->expectException(\Phycode\Security\PathException::class);
        (new WriteTool())->execute(['path' => '.git/hooks/x', 'content' => 'x'], $this->context);
    }

    public function testDeleteMovesFilesToTheTrash(): void
    {
        $result = (new DeleteTool())->execute(['path' => 'src/a.php'], $this->context);

        self::assertTrue($result->ok);
        self::assertFileDoesNotExist($this->root . '/src/a.php');
        self::assertCount(1, glob($this->root . '/.phycode/trash/*/src/a.php') ?: [], 'the file is recoverable from the trash');
    }

    public function testMoveNeverOverwrites(): void
    {
        Kit::write($this->root . '/other.txt', 'keep');

        $result = (new MoveTool())->execute(['from' => 'src/a.php', 'to' => 'other.txt'], $this->context);

        self::assertFalse($result->ok);
        self::assertSame('keep', file_get_contents($this->root . '/other.txt'));
    }

    public function testDeletingASymbolicLinkRemovesOnlyTheLink(): void
    {
        Kit::write($this->root . '/notes/target.txt', "keep me\n");
        symlink('../notes/target.txt', $this->root . '/src/link.txt');

        $result = (new DeleteTool())->execute(['path' => 'src/link.txt'], $this->context);

        self::assertTrue($result->ok);
        self::assertSame("keep me\n", file_get_contents($this->root . '/notes/target.txt'), 'the target is never touched');
        self::assertFalse(is_link($this->root . '/src/link.txt'));
        $trashed = glob($this->root . '/.phycode/trash/*/src/link.txt') ?: [];
        self::assertCount(1, $trashed, 'the link is recoverable from the trash');
        self::assertTrue(is_link($trashed[0]), 'the trash holds the link, not a copy of the target');
    }

    public function testDeletingASymbolicLinkToAFolderKeepsTheFolder(): void
    {
        symlink($this->root . '/src', $this->root . '/source-link');

        $result = (new DeleteTool())->execute(['path' => 'source-link'], $this->context);

        self::assertTrue($result->ok);
        self::assertFalse(is_link($this->root . '/source-link'));
        self::assertFileExists($this->root . '/src/a.php');
    }

    public function testMovingASymbolicLinkMovesTheLinkNotItsTarget(): void
    {
        symlink('src/a.php', $this->root . '/alias.php');

        $result = (new MoveTool())->execute(['from' => 'alias.php', 'to' => 'moved/alias.php'], $this->context);

        self::assertTrue($result->ok);
        self::assertTrue(is_link($this->root . '/moved/alias.php'));
        self::assertFalse(is_link($this->root . '/alias.php'));
        self::assertFileExists($this->root . '/src/a.php');
    }

    public function testListShowsFoldersAndHidesIgnoredOnes(): void
    {
        $result = (new ListTool())->execute(['depth' => 1], $this->context);

        self::assertTrue($result->ok);
        self::assertStringContainsString('src/', $result->content);
        self::assertStringNotContainsString('node_modules', $result->content);
    }

    public function testGrepFindsLiteralMatchesWithLineNumbers(): void
    {
        $result = (new GrepTool())->execute(['pattern' => 'line3'], $this->context);

        self::assertTrue($result->ok);
        self::assertStringContainsString('src/a.php:3:', $result->content);
        self::assertSame('no matches', (new GrepTool())->execute(['pattern' => 'absent-text-xyz'], $this->context)->content);
    }

    public function testReadReportsTheNextOffsetWhenTheWindowIsSmall(): void
    {
        $result = (new ReadTool())->execute(['path' => 'src/a.php', 'limit' => 1], $this->context);

        self::assertStringContainsString('call read with offset=2', $result->content);
    }
}
