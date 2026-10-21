<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\DeleteTool;
use Phycode\Tools\EditTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\ToolContext;
use Phycode\Tools\WriteTool;
use PHPUnit\Framework\TestCase;

final class PreviewDiffTest extends TestCase
{
    private string $root;
    private ToolContext $context;

    protected function setUp(): void
    {
        $this->root = Kit::folder('preview');
        $this->context = Kit::context($this->root, Kit::agent(), RunPolicy::primary(Kit::agent(), 'yolo'), new RecordingUi());
    }

    public function testAWritePreviewShowsTheChangeAndWritesNothing(): void
    {
        Kit::write($this->root . '/notes.txt', "one\ntwo\nthree\n");

        $preview = (new WriteTool())->preview(['path' => 'notes.txt', 'content' => "one\n2\nthree\n"], $this->context);

        self::assertSame(['path' => 'notes.txt', 'added' => 1, 'removed' => 1], self::counts($preview));
        self::assertSame("one\ntwo\nthree\n", file_get_contents($this->root . '/notes.txt'));
    }

    public function testAWritePreviewOfANewFileAddsEveryLineAndCreatesNothing(): void
    {
        $preview = (new WriteTool())->preview(['path' => 'new/dir/f.txt', 'content' => "a\nb\n"], $this->context);

        self::assertSame(['path' => 'new/dir/f.txt', 'added' => 2, 'removed' => 0], self::counts($preview));
        self::assertDirectoryDoesNotExist($this->root . '/new');
    }

    public function testTheResultOfAWriteCarriesTheDiffOfThePreview(): void
    {
        Kit::write($this->root . '/notes.txt', "one\ntwo\n");
        $args = ['path' => 'notes.txt', 'content' => "one\nTWO\n"];
        $preview = (new WriteTool())->preview($args, $this->context);

        $result = (new WriteTool())->execute($args, $this->context);

        self::assertTrue($result->ok);
        self::assertSame($preview, $result->diff);
    }

    public function testAnEditPreviewShowsTheChangeAndTheResultCarriesTheSameDiff(): void
    {
        Kit::write($this->root . '/src.txt', "alpha\nbeta\n");
        $args = ['path' => 'src.txt', 'old_string' => 'beta', 'new_string' => 'gamma'];

        $preview = (new EditTool())->preview($args, $this->context);
        self::assertSame("alpha\nbeta\n", file_get_contents($this->root . '/src.txt'));
        $result = (new EditTool())->execute($args, $this->context);

        self::assertSame(['path' => 'src.txt', 'added' => 1, 'removed' => 1], self::counts($preview));
        self::assertSame("alpha\ngamma\n", file_get_contents($this->root . '/src.txt'));
        self::assertSame($preview, $result->diff);
    }

    public function testAnEditWithCrlfLinesPreviewsTheChangeInTheFilesLineEndings(): void
    {
        Kit::write($this->root . '/crlf.txt', "a\r\nb\r\n");

        $preview = (new EditTool())->preview(['path' => 'crlf.txt', 'old_string' => "a\nb", 'new_string' => "c\nd"], $this->context);

        self::assertSame(['path' => 'crlf.txt', 'added' => 2, 'removed' => 2], self::counts($preview));
        self::assertSame("a\r\nb\r\n", file_get_contents($this->root . '/crlf.txt'));
    }

    public function testAnEditThatWouldNotApplyHasNoPreview(): void
    {
        Kit::write($this->root . '/twice.txt', "x\nx\n");
        $edit = new EditTool();

        self::assertNull($edit->preview(['path' => 'twice.txt', 'old_string' => 'x', 'new_string' => 'y'], $this->context), 'two places');
        self::assertNull($edit->preview(['path' => 'missing.txt', 'old_string' => 'x', 'new_string' => 'y'], $this->context), 'no file');
        self::assertNull($edit->preview(['path' => 'twice.txt', 'old_string' => 'x'], $this->context), 'no new_string');
        self::assertNull($edit->preview(['path' => 'twice.txt', 'old_string' => 'x', 'new_string' => 'x'], $this->context), 'nothing to change');
    }

    public function testADeletePreviewRemovesEveryLineOfATextFileAndDeletesNothing(): void
    {
        Kit::write($this->root . '/gone.txt', "a\nb\nc\n");

        $preview = (new DeleteTool())->preview(['path' => 'gone.txt'], $this->context);

        self::assertSame(['path' => 'gone.txt', 'added' => 0, 'removed' => 3], self::counts($preview));
        self::assertFileExists($this->root . '/gone.txt');
        self::assertDirectoryDoesNotExist($this->root . '/.phycode', 'a preview does not create the trash folder');
    }

    public function testTheResultOfADeleteRemovesEveryLine(): void
    {
        Kit::write($this->root . '/gone.txt', "a\nb\n");

        $result = (new DeleteTool())->execute(['path' => 'gone.txt'], $this->context);

        self::assertTrue($result->ok);
        self::assertSame(['path' => 'gone.txt', 'added' => 0, 'removed' => 2], self::counts($result->diff));
        self::assertFileDoesNotExist($this->root . '/gone.txt');
    }

    public function testBinaryAndLinkedFilesHaveNoDeleteDiff(): void
    {
        Kit::write($this->root . '/blob.bin', "abc\0def");
        symlink($this->root . '/blob.bin', $this->root . '/link.bin');
        $delete = new DeleteTool();

        self::assertNull($delete->preview(['path' => 'blob.bin'], $this->context));
        self::assertNull($delete->preview(['path' => 'link.bin'], $this->context));
        self::assertNull($delete->execute(['path' => 'blob.bin'], $this->context)->diff);
    }

    public function testAFileOverTheDiffLimitHasNoDiffAndTheChangeStillHappens(): void
    {
        Kit::write($this->root . '/big.txt', str_repeat("line\n", 250000));
        $args = ['path' => 'big.txt', 'old_string' => 'line', 'new_string' => 'LINE', 'replace_all' => true];

        self::assertNull((new EditTool())->preview($args, $this->context));
        $result = (new EditTool())->execute($args, $this->context);

        self::assertTrue($result->ok);
        self::assertNull($result->diff);
        self::assertStringStartsWith("LINE\nLINE\n", (string) file_get_contents($this->root . '/big.txt'));
    }

    public function testToolsWithoutAFileChangeHaveNoPreview(): void
    {
        self::assertNull((new ReadTool())->preview(['path' => 'x'], $this->context));
    }

    private static function counts(?array $diff): array
    {
        self::assertNotNull($diff);

        return ['path' => $diff['path'], 'added' => $diff['added'], 'removed' => $diff['removed']];
    }
}
