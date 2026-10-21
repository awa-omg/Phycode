<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Review;

use Phycode\Exec\ProcessRunner;
use Phycode\Review\ChangeSet;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class ChangeSetTest extends TestCase
{
    public function testCommittedUncommittedAndUntrackedChangesAreReadAgainstABase(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n\$a = 1;\n", 'src/b.php' => "<?php\n\$b = 1;\n"]);
        Kit::write($root . '/src/a.php', "<?php\n\$a = 2; // committed change\n");
        ReviewRepo::commit($root, 'change a');
        Kit::write($root . '/src/b.php', "<?php\n\$b = 2; // uncommitted change\n");
        Kit::write($root . '/src/c.php', "<?php\n\$c = 3; // untracked file\n");

        $set = ChangeSet::read(new ProcessRunner(), $root, 'HEAD~1');

        self::assertStringContainsString('+$a = 2; // committed change', $set->text);
        self::assertStringContainsString('+$b = 2; // uncommitted change', $set->text);
        self::assertStringContainsString('+$c = 3; // untracked file', $set->text);
        self::assertSame(['src/a.php', 'src/b.php', 'src/c.php'], $set->files);
        self::assertStringStartsWith('changes against HEAD~1 (', $set->base);
        self::assertFalse($set->cut);
        self::assertFalse($set->isEmpty());
    }

    public function testWithoutABaseOnlyTheUncommittedChangesAndUntrackedFilesAreRead(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n\$a = 1;\n"]);
        Kit::write($root . '/src/a.php', "<?php\n\$a = 2; // committed change\n");
        ReviewRepo::commit($root, 'change a');
        Kit::write($root . '/src/a.php', "<?php\n\$a = 2; // committed change\n\$a = 4; // uncommitted change\n");
        Kit::write($root . '/src/new.php', "<?php\n// untracked file\n");

        $set = ChangeSet::read(new ProcessRunner(), $root, null);

        self::assertSame('uncommitted changes against HEAD', $set->base);
        self::assertStringNotContainsString('+$a = 2; // committed change', $set->text, 'the commit is already part of HEAD');
        self::assertStringContainsString('+$a = 4; // uncommitted change', $set->text);
        self::assertStringContainsString('+// untracked file', $set->text);
        self::assertSame(['src/a.php', 'src/new.php'], $set->files);
    }

    public function testStagedChangesAreUncommittedChanges(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n\$a = 1;\n"]);
        Kit::write($root . '/src/a.php', "<?php\n\$a = 9; // staged change\n");
        ReviewRepo::git($root, ['add', 'src/a.php']);

        $set = ChangeSet::read(new ProcessRunner(), $root, null);

        self::assertStringContainsString('+$a = 9; // staged change', $set->text);
    }

    public function testAnEmptyChangeSetIsEmpty(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n\$a = 1;\n"]);

        $set = ChangeSet::read(new ProcessRunner(), $root, null);

        self::assertTrue($set->isEmpty());
        self::assertSame([], $set->files);
        self::assertSame('0 files, 0 bytes', $set->summary());
    }

    public function testAChangeSetLargerThan60KbIsCutWithANote(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n"]);
        Kit::write($root . '/src/a.php', "<?php\n" . str_repeat("echo 'a line of the change';\n", 6000));

        $set = ChangeSet::read(new ProcessRunner(), $root, null);

        self::assertTrue($set->cut);
        self::assertGreaterThan(ChangeSet::LIMIT, $set->bytes);
        self::assertLessThan(ChangeSet::LIMIT + 200, strlen($set->text));
        self::assertStringContainsString('[... change set cut at 60 KB:', $set->text);
        self::assertStringContainsString('cut to 60 KB', $set->summary());
    }

    public function testTheProjectFolderOfPhycodeIsLeftOut(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n\$a = 1;\n"]);
        Kit::write($root . '/.phycode/sessions/one.json', '{"secret":"session text"}');
        Kit::write($root . '/src/a.php', "<?php\n\$a = 2;\n");

        $set = ChangeSet::read(new ProcessRunner(), $root, null);

        self::assertSame(['src/a.php'], $set->files);
        self::assertStringNotContainsString('session text', $set->text);
    }

    public function testAProjectFolderInsideARepositoryReadsOnlyItsOwnChanges(): void
    {
        $repo = ReviewRepo::create(['app/a.php' => "<?php\n", 'other/b.php' => "<?php\n"]);
        Kit::write($repo . '/app/a.php', "<?php\n// changed in the project\n");
        Kit::write($repo . '/app/new.php', "<?php\n// new in the project\n");
        Kit::write($repo . '/other/b.php', "<?php\n// changed elsewhere\n");

        $set = ChangeSet::read(new ProcessRunner(), $repo . '/app', null);

        self::assertSame(['a.php', 'new.php'], $set->files, 'the paths are relative to the project folder');
        self::assertStringContainsString('// changed in the project', $set->text);
        self::assertStringContainsString('// new in the project', $set->text);
        self::assertStringNotContainsString('changed elsewhere', $set->text);
    }

    public function testABaseRefThatNamesNoCommitIsRefused(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n"]);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage("'no-such-ref' does not name a commit");

        ChangeSet::read(new ProcessRunner(), $root, 'no-such-ref');
    }

    public function testABaseRefThatLooksLikeAnOptionIsRefused(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n"]);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage("'--output=/tmp/x' is not a ref");

        ChangeSet::read(new ProcessRunner(), $root, '--output=/tmp/x');
    }

    public function testAFolderOutsideGitIsRefused(): void
    {
        $folder = Kit::folder('not-a-repository');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage("{$folder} is not inside a git repository");

        ChangeSet::read(new ProcessRunner(), $folder, null);
    }

    public function testARepositoryWithoutCommitsNeedsABaseRef(): void
    {
        $root = Kit::folder('review-empty-repo');
        ReviewRepo::git($root, ['init', '-q', '-b', 'main']);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('the repository has no commit yet');

        ChangeSet::read(new ProcessRunner(), $root, null);
    }
}
