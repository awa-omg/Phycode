<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\PathException;
use Phycode\Security\Workspace;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class ReadOnlyFoldersTest extends TestCase
{
    private string $root;
    private string $extra;
    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->root = Kit::folder('ro-project');
        $this->extra = Kit::folder('ro-extra');
        Kit::write($this->root . '/src/app.php', "<?php\n");
        Kit::write($this->extra . '/notes.txt', "notes\n");
        Kit::write($this->extra . '/sub/deep.txt', "deep\n");
        $this->workspace = new Workspace($this->root, [$this->extra]);
    }

    public function testAPathInTheReadOnlyFolderResolvesInsideIt(): void
    {
        self::assertSame(realpath($this->extra) . '/notes.txt', $this->workspace->resolve($this->extra . '/notes.txt'));
        self::assertSame(realpath($this->extra) . '/sub/deep.txt', $this->workspace->resolve($this->extra . '/sub/../sub/deep.txt'));
    }

    public function testTheProjectKeepsItsRelativePaths(): void
    {
        self::assertSame(realpath($this->root) . '/src/new.php', $this->workspace->resolve('src/new.php'));
        self::assertSame('src/app.php', $this->workspace->relative(realpath($this->root) . '/src/app.php'));
        self::assertSame(realpath($this->extra) . '/notes.txt', $this->workspace->relative(realpath($this->extra) . '/notes.txt'));
    }

    public function testPathsOutsideBothFoldersAreRefused(): void
    {
        foreach (['/etc/passwd', $this->extra . '/../escape.txt', $this->root . '/../escape.txt', '../escape.txt'] as $path) {
            $this->assertRefused($path);
        }
    }

    public function testALinkInTheReadOnlyFolderCannotLeaveIt(): void
    {
        $outside = Kit::folder('ro-outside');
        Kit::write($outside . '/secret.txt', "secret\n");
        symlink($outside, $this->extra . '/out');
        symlink($this->root, $this->extra . '/project');

        $this->assertRefused($this->extra . '/out/secret.txt');
        $this->assertRefused($this->extra . '/project/src/app.php');
    }

    public function testALinkInTheProjectCannotReachTheReadOnlyFolder(): void
    {
        symlink($this->extra, $this->root . '/peek');

        $this->assertRefused('peek/notes.txt');
    }

    public function testChangesInTheReadOnlyFolderAreRefused(): void
    {
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('read-only folder');

        $this->workspace->resolveWritable($this->extra . '/notes.txt');
    }

    public function testANewFileInTheReadOnlyFolderIsRefused(): void
    {
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('read-only folder');

        $this->workspace->resolveWritable($this->extra . '/new.txt');
    }

    public function testRemovingAnEntryOfTheReadOnlyFolderIsRefused(): void
    {
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('read-only folder');

        $this->workspace->resolveEntry($this->extra . '/sub');
    }

    public function testAWriteThroughALinkIntoTheReadOnlyFolderIsRefused(): void
    {
        symlink($this->extra, $this->root . '/peek');

        $this->expectException(PathException::class);

        $this->workspace->resolveWritable('peek/notes.txt');
    }

    public function testACommandThatNamesTheReadOnlyFolderIsRefusedButASiblingNameIsNot(): void
    {
        $this->workspace->assertNotNamed('cat src/app.php');
        $this->workspace->assertNotNamed('cat ' . $this->extra . '-other/file');
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('read-only folder');

        $this->workspace->assertNotNamed('cat ' . $this->extra . '/notes.txt');
    }

    public function testAFolderThatOverlapsTheProjectIsRefused(): void
    {
        mkdir($this->root . '/docs', 0700);

        foreach ([$this->root, $this->root . '/docs', dirname(realpath($this->root))] as $folder) {
            try {
                new Workspace($this->root, [$folder]);
            } catch (PathException $error) {
                self::assertStringContainsString('overlaps the project folder', $error->getMessage());
                continue;
            }
            self::fail("{$folder} should be refused as a read-only folder");
        }
    }

    public function testAMissingFolderAndTheRootAreRefused(): void
    {
        foreach ([$this->root . '/missing', '/'] as $folder) {
            try {
                new Workspace($this->root, [$folder]);
            } catch (PathException) {
                continue;
            }
            self::fail("{$folder} should be refused as a read-only folder");
        }
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('read-only folder does not exist or is not a folder');

        new Workspace($this->root, [$this->root . '/missing']);
    }

    private function assertRefused(string $path): void
    {
        try {
            $this->workspace->resolve($path);
        } catch (PathException) {
            self::assertTrue(true);

            return;
        }
        self::fail("{$path} should be refused");
    }
}
