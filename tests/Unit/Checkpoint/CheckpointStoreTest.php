<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Checkpoint;

use Phycode\Checkpoint\CheckpointException;
use Phycode\Checkpoint\CheckpointStore;
use Phycode\Security\PathException;
use Phycode\Security\Workspace;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckpointStoreTest extends TestCase
{
    private string $root;
    private CheckpointStore $store;

    protected function setUp(): void
    {
        $this->root = Kit::folder('checkpoint');
        Kit::write($this->root . '/src/a.txt', "A0\n");
        Kit::write($this->root . '/src/b.txt', "B0\n");
        $this->store = new CheckpointStore(new Workspace($this->root));
    }

    public function testUndoRedoAndRewindAcrossTwoTurnsRestoreEachState(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/new.txt', null);
        $this->put('src/new.txt', "N1\n");
        $this->store->record('src/a.txt', "A0\n");
        $this->put('src/a.txt', "A1\n");
        $this->store->record('src/b.txt', "B0\n");
        $this->remove('src/b.txt');

        $this->store->begin('turn-2');
        $this->store->record('src/a.txt', "A1\n");
        $this->put('src/a.txt', "A2\n");
        $this->store->record('src/c.txt', null);
        $this->put('src/c.txt', "C1\n");

        self::assertSame(['src/new.txt', 'src/a.txt', 'src/b.txt'], $this->store->changedFiles('turn-1'));
        self::assertSame(['src/a.txt', 'src/c.txt'], $this->store->changedFiles('turn-2'));

        $this->store->undo('turn-2');
        self::assertSame("A1\n", $this->content('src/a.txt'));
        self::assertNull($this->content('src/c.txt'), 'a file the turn created is removed by undo');

        $this->store->redo('turn-2');
        self::assertSame("A2\n", $this->content('src/a.txt'));
        self::assertSame("C1\n", $this->content('src/c.txt'));

        $this->store->undo('turn-2');
        $this->store->undo('turn-1');
        self::assertNull($this->content('src/new.txt'));
        self::assertSame("A0\n", $this->content('src/a.txt'));
        self::assertSame("B0\n", $this->content('src/b.txt'), 'a deleted file comes back on undo');
        self::assertNull($this->content('src/c.txt'));

        $this->store->redo('turn-1');
        self::assertSame("N1\n", $this->content('src/new.txt'));
        self::assertSame("A1\n", $this->content('src/a.txt'));
        self::assertNull($this->content('src/b.txt'), 'redo deletes the file again');

        $this->store->redo('turn-2');
        self::assertSame("A2\n", $this->content('src/a.txt'));
        self::assertSame("C1\n", $this->content('src/c.txt'));

        $this->store->rewind('turn-1');
        self::assertNull($this->content('src/new.txt'));
        self::assertSame("A0\n", $this->content('src/a.txt'));
        self::assertSame("B0\n", $this->content('src/b.txt'));
        self::assertNull($this->content('src/c.txt'));

        $this->store->redo('turn-1');
        $this->store->redo('turn-2');
        $this->store->rewind('turn-2');
        self::assertSame("N1\n", $this->content('src/new.txt'), 'rewinding to turn-2 keeps turn-1');
        self::assertSame("A1\n", $this->content('src/a.txt'));
        self::assertNull($this->content('src/c.txt'));
    }

    public function testACreatedFileIsRemovedByUndoAndComesBackOnRedo(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('deep/new/file.txt', null);
        $this->put('deep/new/file.txt', "fresh\n");

        $this->store->undo('turn-1');
        self::assertNull($this->content('deep/new/file.txt'));

        $this->store->redo('turn-1');
        self::assertSame("fresh\n", $this->content('deep/new/file.txt'));
    }

    public function testTheFirstRecordOfAFileInATurnHoldsTheContentBeforeTheTurn(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->put('src/a.txt', "A1\n");
        $this->store->record('src/a.txt', "A1\n");
        $this->put('src/a.txt', "A2\n");

        self::assertSame(['src/a.txt'], $this->store->changedFiles('turn-1'));
        $this->store->undo('turn-1');
        self::assertSame("A0\n", $this->content('src/a.txt'));
    }

    public function testSeveralRecordsInOneTurnMakeOneEntryAndKeepTheContentBeforeEachFirstChange(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->put('src/a.txt', "A1\n");
        $this->store->record('src/b.txt', "B0\n");
        $this->put('src/b.txt', "B1\n");
        $this->store->record('src/a.txt', "A1\n");
        $this->put('src/a.txt', "A2\n");

        self::assertSame([['id' => 'turn-1', 'undone' => false]], $this->store->turns());
        self::assertSame([
            ['path' => 'src/a.txt', 'before' => "A0\n", 'after' => "A2\n"],
            ['path' => 'src/b.txt', 'before' => "B0\n", 'after' => "B1\n"],
        ], $this->store->turnContents('turn-1'));
    }

    public function testPathsThatNameTheSameFileAreOneRecord(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('./src/a.txt', "A0\n");
        $this->store->record('src/../src/a.txt', "A1\n");

        self::assertSame(['src/a.txt'], $this->store->changedFiles('turn-1'));
    }

    public function testAPathThatIsNotUtf8IsRefusedBecauseTheIndexCouldNotKeepIt(): void
    {
        $name = "src/bad-\xff.txt";
        Kit::write($this->root . '/' . $name, "x\n");
        $this->store->begin('turn-1');

        $this->expectException(CheckpointException::class);
        $this->expectExceptionMessage('not valid UTF-8');
        $this->store->record($name, "x\n");
    }

    public static function protectedAndOutsidePaths(): iterable
    {
        yield 'git config' => ['.git/config'];
        yield 'git config below a folder' => ['sub/.git/config'];
        yield 'git folder in upper case' => ['sub/.GIT/hooks/pre-commit'];
        yield 'phycode folder' => ['.phycode/notes.txt'];
        yield 'the checkpoint index itself' => ['.phycode/checkpoints/index.json'];
        yield 'claude agents' => ['.claude/agents/x.md'];
        yield 'above the project' => ['../outside.txt'];
        yield 'an absolute path elsewhere' => ['/etc/passwd'];
    }

    #[DataProvider('protectedAndOutsidePaths')]
    public function testAProtectedOrOutsidePathIsRefusedAndNothingIsRecorded(string $path): void
    {
        Kit::write($this->root . '/.git/config', "[core]\n");
        $this->store->begin('turn-1');

        try {
            $this->store->record($path, 'x');
            self::fail("{$path} was recorded");
        } catch (PathException $error) {
            self::assertTrue(
                str_contains($error->getMessage(), 'protected folder') || str_contains($error->getMessage(), 'outside the project'),
                $error->getMessage(),
            );
        }
        self::assertSame([], $this->store->turns());
        self::assertSame("[core]\n", $this->content('.git/config'));
    }

    public function testASymbolicLinkIsRefusedWhetherItPointsInsideOrOutside(): void
    {
        $outside = Kit::folder('checkpoint-outside');
        Kit::write($outside . '/secret.txt', "secret\n");
        symlink($this->root . '/src/a.txt', $this->root . '/inside-link.txt');
        symlink($outside . '/secret.txt', $this->root . '/outside-link.txt');
        $this->store->begin('turn-1');

        foreach (['inside-link.txt', 'outside-link.txt'] as $link) {
            try {
                $this->store->record($link, 'x');
                self::fail("{$link} was recorded");
            } catch (PathException $error) {
                self::assertStringContainsString('symbolic link', $error->getMessage());
            }
        }
        self::assertSame("secret\n", file_get_contents($outside . '/secret.txt'));
    }

    public function testAFolderCannotBeRecorded(): void
    {
        $this->store->begin('turn-1');

        $this->expectException(CheckpointException::class);
        $this->expectExceptionMessage('is not a regular file');
        $this->store->record('src', null);
    }

    public function testACheckpointFolderThatIsALinkIsRefused(): void
    {
        $outside = Kit::folder('checkpoint-link-target');
        mkdir($this->root . '/.phycode');
        symlink($outside, $this->root . '/.phycode/checkpoints');

        $this->expectException(PathException::class);
        $this->expectExceptionMessage('symbolic link');
        $this->store->begin('turn-1');
    }

    public function testTheIndexIsPrivateAndTheFolderIsIgnoredByGit(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");

        self::assertSame("*\n", file_get_contents($this->root . '/.phycode/.gitignore'));
        self::assertSame(0600, fileperms($this->root . '/.phycode/checkpoints/index.json') & 0777);
    }

    public function testNoTemporaryFilesAreLeftBehindAndBlobsAreNamedByDigest(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->put('src/a.txt', "A1\n");
        $this->store->undo('turn-1');
        $this->store->redo('turn-1');

        $folder = $this->root . '/.phycode/checkpoints';
        self::assertSame(['blobs', 'index.json'], $this->names($folder));
        foreach ($this->names($folder . '/blobs') as $name) {
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $name);
        }
    }

    public function testBeginningATurnTwiceIsRefused(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");

        $this->expectException(CheckpointException::class);
        $this->expectExceptionMessage('turn turn-1 already has a checkpoint');
        $this->store->begin('turn-1');
    }

    public static function invalidTurnIds(): iterable
    {
        yield 'empty' => [''];
        yield 'a path' => ['../turn'];
        yield 'a space' => ['turn 1'];
        yield 'a newline' => ["turn\n"];
        yield 'too long' => [str_repeat('t', 129)];
    }

    #[DataProvider('invalidTurnIds')]
    public function testATurnIdThatIsNotASafeNameIsRefused(string $turnId): void
    {
        $this->expectException(CheckpointException::class);
        $this->expectExceptionMessage('invalid turn id');
        $this->store->begin($turnId);
    }

    public static function operationsOnUnknownTurns(): iterable
    {
        yield 'undo' => ['undo'];
        yield 'redo' => ['redo'];
        yield 'rewind' => ['rewind'];
        yield 'changedFiles' => ['changedFiles'];
        yield 'turnContents' => ['turnContents'];
    }

    #[DataProvider('operationsOnUnknownTurns')]
    public function testAnUnknownTurnIsRefusedByEveryOperation(string $operation): void
    {
        $this->store->begin('turn-1');

        $this->expectException(CheckpointException::class);
        $this->expectExceptionMessage('no checkpoint for turn missing');
        $this->store->{$operation}('missing');
    }

    public function testRecordOutsideATurnChangesNothingAndCreatesNoFolder(): void
    {
        $this->store->record('src/a.txt', 'x');

        self::assertSame([], $this->store->turns());
        self::assertDirectoryDoesNotExist($this->root . '/.phycode/checkpoints');
    }

    public function testRecordAfterTheCurrentTurnWasUndoneChangesNothing(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->store->undo('turn-1');

        $this->store->record('src/b.txt', 'x');

        self::assertSame(['src/a.txt'], $this->store->changedFiles('turn-1'));
        self::assertSame([['id' => 'turn-1', 'undone' => true]], $this->store->turns());
    }

    public function testRecordIsNotKeptByATurnThatAnotherStoreBegan(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");

        $later = new CheckpointStore(new Workspace($this->root));
        $later->record('src/b.txt', "B0\n");

        self::assertSame(['src/a.txt'], $this->store->changedFiles('turn-1'));
    }

    public function testAFailedBeginRefusesRecordsUntilATurnOpens(): void
    {
        symlink(Kit::folder('elsewhere'), $this->root . '/.phycode');
        try {
            $this->store->begin('turn-1');
            self::fail('a checkpoint folder that is a link must refuse the turn');
        } catch (PathException) {
        }

        try {
            $this->store->record('src/a.txt', 'x');
            self::fail('a change must not go on without its copy');
        } catch (CheckpointException) {
        }

        unlink($this->root . '/.phycode');
        $this->store->begin('turn-2');
        $this->store->record('src/a.txt', 'x');

        self::assertSame(['src/a.txt'], $this->store->changedFiles('turn-2'));
    }

    public function testOnlyTheLatestTurnCanBeUndone(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->store->begin('turn-2');
        $this->store->record('src/b.txt', "B0\n");

        $this->expectException(CheckpointException::class);
        $this->expectExceptionMessage('turn-1 is not the latest change; undo turn-2 first');
        $this->store->undo('turn-1');
    }

    public function testOnlyTheEarliestUndoneTurnCanBeRedone(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->store->begin('turn-2');
        $this->store->record('src/b.txt', "B0\n");
        $this->store->undo('turn-2');
        $this->store->undo('turn-1');

        $this->expectException(CheckpointException::class);
        $this->expectExceptionMessage('turn-2 is not the next to redo; redo turn-1 first');
        $this->store->redo('turn-2');
    }

    public function testATurnCannotBeUndoneTwiceOrRedoneWhileApplied(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->store->undo('turn-1');

        try {
            $this->store->undo('turn-1');
            self::fail('an undone turn was undone again');
        } catch (CheckpointException $error) {
            self::assertStringContainsString('already undone', $error->getMessage());
        }

        $this->store->redo('turn-1');
        try {
            $this->store->redo('turn-1');
            self::fail('an applied turn was redone');
        } catch (CheckpointException $error) {
            self::assertStringContainsString('is not undone', $error->getMessage());
        }
    }

    public function testRewindOfAnUndoneTurnChangesNothing(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->put('src/a.txt', "A1\n");
        $this->store->undo('turn-1');

        $this->store->rewind('turn-1');

        self::assertSame("A0\n", $this->content('src/a.txt'));
        $this->store->redo('turn-1');
        self::assertSame("A1\n", $this->content('src/a.txt'));
    }

    public function testAChangeAfterAnUndoDropsTheUndoneTurnsAndTheContentOnlyTheyKept(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->put('src/a.txt', "A1\n");
        $this->store->begin('turn-2');
        $this->store->record('src/c.txt', null);
        $this->put('src/c.txt', "C1\n");
        $this->store->undo('turn-2');
        self::assertFileExists($this->root . '/.phycode/checkpoints/blobs/' . hash('sha256', "C1\n"), 'the undone turn keeps its content for redo');

        $this->store->begin('turn-3');
        $this->store->record('src/b.txt', "B0\n");

        self::assertFileDoesNotExist($this->root . '/.phycode/checkpoints/blobs/' . hash('sha256', "C1\n"));
        $this->expectException(CheckpointException::class);
        $this->expectExceptionMessage('no checkpoint for turn turn-2');
        $this->store->redo('turn-2');
    }

    public function testATurnThatChangesNoFileLeavesNoEntry(): void
    {
        $this->store->begin('turn-1');

        self::assertSame([], $this->store->turns());
        self::assertDirectoryDoesNotExist($this->root . '/.phycode/checkpoints');
    }

    public function testATurnWithoutChangesKeepsTheUndoneTurnForRedo(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->put('src/a.txt', "A1\n");
        $this->store->undo('turn-1');

        $this->store->begin('turn-2');
        self::assertSame([['id' => 'turn-1', 'undone' => true]], $this->store->turns());

        $this->store->redo('turn-1');
        self::assertSame("A1\n", $this->content('src/a.txt'));
    }

    public function testAnotherStoreOnTheSameProjectSeesTheTurnsAndCanUndoThem(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->put('src/a.txt', "A1\n");

        $later = new CheckpointStore(new Workspace($this->root));
        $later->undo('turn-1');
        self::assertSame("A0\n", $this->content('src/a.txt'));

        $this->store->redo('turn-1');
        self::assertSame("A1\n", $this->content('src/a.txt'));
    }

    public function testADamagedIndexIsNotOverwritten(): void
    {
        Kit::write($this->root . '/.phycode/checkpoints/index.json', "{not json");

        $this->expectException(CheckpointException::class);
        $this->expectExceptionMessage('cannot be read; no file was changed');
        try {
            $this->store->begin('turn-1');
        } finally {
            self::assertSame("{not json", $this->content('.phycode/checkpoints/index.json'));
        }
    }

    public function testMissingSavedContentStopsUndoBeforeAnyFileIsChanged(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->put('src/a.txt', "A1\n");
        $this->store->record('src/new.txt', null);
        $this->put('src/new.txt', "N1\n");
        $this->removeTree($this->root . '/.phycode/checkpoints/blobs');

        try {
            $this->store->undo('turn-1');
            self::fail('undo ran without its saved content');
        } catch (CheckpointException $error) {
            self::assertStringContainsString('is missing from the checkpoints', $error->getMessage());
        }
        self::assertSame("A1\n", $this->content('src/a.txt'));
        self::assertSame("N1\n", $this->content('src/new.txt'));
    }

    public function testAFileThatBecameAFolderStopsUndoBeforeAnythingIsChanged(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->put('src/a.txt', "A1\n");
        $this->store->record('src/new.txt', null);
        $this->put('src/new.txt', "N1\n");
        unlink($this->root . '/src/a.txt');
        mkdir($this->root . '/src/a.txt');

        try {
            $this->store->undo('turn-1');
            self::fail('undo ran over a folder');
        } catch (CheckpointException $error) {
            self::assertStringContainsString('is not a regular file', $error->getMessage());
        }
        self::assertSame("N1\n", $this->content('src/new.txt'), 'the other file of the turn is untouched');
    }

    public function testBinaryContentIsRestoredExactly(): void
    {
        $bytes = "\0\1\2\xff\xfe binary\n\r\0";
        $this->put('src/image.bin', $bytes);
        $this->store->begin('turn-1');
        $this->store->record('src/image.bin', $bytes);
        $this->put('src/image.bin', "replaced\n");

        $this->store->undo('turn-1');

        self::assertSame($bytes, $this->content('src/image.bin'));
    }

    public function testTurnsAreListedAppliedFirstThenUndone(): void
    {
        self::assertSame([], $this->store->turns());

        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->store->begin('turn-2');
        $this->store->record('src/b.txt', "B0\n");
        $this->store->undo('turn-2');

        self::assertSame([
            ['id' => 'turn-1', 'undone' => false],
            ['id' => 'turn-2', 'undone' => true],
        ], $this->store->turns());
    }

    public function testTurnContentsGiveTheFileNowForAnAppliedTurnAndTheSavedContentForAnUndoneOne(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->put('src/a.txt', "A1\n");
        $this->store->record('src/new.txt', null);
        $this->put('src/new.txt', "N1\n");

        self::assertSame([
            ['path' => 'src/a.txt', 'before' => "A0\n", 'after' => "A1\n"],
            ['path' => 'src/new.txt', 'before' => null, 'after' => "N1\n"],
        ], $this->store->turnContents('turn-1'));

        $this->put('src/a.txt', "A2\n");
        self::assertSame("A2\n", $this->store->turnContents('turn-1')[0]['after'], 'an applied turn shows the file as it is now');

        $this->store->undo('turn-1');
        self::assertSame("A0\n", $this->content('src/a.txt'));
        self::assertSame([
            ['path' => 'src/a.txt', 'before' => "A0\n", 'after' => "A2\n"],
            ['path' => 'src/new.txt', 'before' => null, 'after' => "N1\n"],
        ], $this->store->turnContents('turn-1'), 'an undone turn shows the content saved when it was undone');
    }

    private function names(string $folder): array
    {
        $names = array_values(array_diff(scandir($folder) ?: [], ['.', '..']));
        sort($names);

        return $names;
    }

    private function put(string $relative, string $content): void
    {
        Kit::write($this->root . '/' . $relative, $content);
    }

    private function remove(string $relative): void
    {
        unlink($this->root . '/' . $relative);
    }

    private function content(string $relative): ?string
    {
        $file = $this->root . '/' . $relative;

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    private function removeTree(string $folder): void
    {
        foreach (glob($folder . '/*') ?: [] as $entry) {
            unlink($entry);
        }
        rmdir($folder);
    }
}
