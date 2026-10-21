<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Diff;

use Phycode\Diff\GitDiff;
use Phycode\Exec\ProcessRunner;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class GitDiffTest extends TestCase
{
    private string|false $previousHome;
    private string|false $previousPath;
    private string|false $previousGitDir;
    private string $repository;

    protected function setUp(): void
    {
        $this->previousHome = getenv('HOME');
        $this->previousPath = getenv('PATH');
        $this->previousGitDir = getenv('GIT_DIR');
        $home = Kit::folder('gitdiff-home');
        Kit::write($home . '/.gitconfig', "[user]\n\tname = Phycode Test\n\temail = test@example.invalid\n[init]\n\tdefaultBranch = main\n[commit]\n\tgpgsign = false\n");
        putenv('HOME=' . $home);
        putenv('GIT_DIR');

        $this->repository = Kit::folder('gitdiff-repository');
        $this->git(['init', '-q'], $this->repository);
        Kit::write($this->repository . '/tracked.txt', "one\ntwo\nthree\n");
        Kit::write($this->repository . '/gone.txt', "bye\n");
        Kit::write($this->repository . '/image.bin', "\0\1\2 first\n");
        Kit::write($this->repository . '/sub/inner.txt', "inside\n");
        Kit::write($this->repository . '/.gitignore', "*.log\n");
        $this->git(['add', '-A'], $this->repository);
        $this->git(['commit', '-q', '-m', 'first'], $this->repository);
    }

    protected function tearDown(): void
    {
        self::restore('HOME', $this->previousHome);
        self::restore('PATH', $this->previousPath);
        self::restore('GIT_DIR', $this->previousGitDir);
    }

    public function testTrackedChangesAreDiffedAgainstTheIndex(): void
    {
        Kit::write($this->repository . '/tracked.txt', "one\nTWO\nthree\nfour\n");

        $files = GitDiff::files($this->repository);

        self::assertSame(['tracked.txt'], array_column($files, 'path'));
        self::assertSame(2, $files[0]['added']);
        self::assertSame(1, $files[0]['removed']);
        self::assertFalse($files[0]['binary']);
        self::assertSame([[1, 3, 1, 4]], DiffSupport::headers($files[0]));
    }

    public function testADeletedFileIsListedAsRemoved(): void
    {
        unlink($this->repository . '/gone.txt');

        $files = GitDiff::files($this->repository);

        self::assertSame(['gone.txt'], array_column($files, 'path'));
        self::assertSame(1, $files[0]['removed']);
        self::assertSame([[1, 1, 0, 0]], DiffSupport::headers($files[0]));
    }

    public function testAnUntrackedFileIsListedAsAddedAndIsNotAddedToTheIndex(): void
    {
        Kit::write($this->repository . '/new file.txt', "fresh\nlines\n");

        $files = GitDiff::files($this->repository);

        self::assertSame(['new file.txt'], array_column($files, 'path'));
        self::assertSame(2, $files[0]['added']);
        self::assertSame('', $this->git(['diff', '--cached', '--name-only'], $this->repository));
    }

    public function testUntrackedNamesWithQuotesAndNonAsciiCharactersAreReadCorrectly(): void
    {
        Kit::write($this->repository . '/q"x.txt', "quote\n");
        Kit::write($this->repository . '/café.txt', "cafe\n");

        $files = GitDiff::files($this->repository);

        self::assertSame(['café.txt', 'q"x.txt'], array_column($files, 'path'));
        self::assertSame([1, 1], array_column($files, 'added'));
    }

    public function testBinaryFilesHaveNoHunks(): void
    {
        Kit::write($this->repository . '/image.bin', "\0\1\2 second\n");
        Kit::write($this->repository . '/data.bin', "a\0b\n");

        $files = GitDiff::files($this->repository);

        self::assertSame(['data.bin', 'image.bin'], array_column($files, 'path'));
        self::assertSame([true, true], array_column($files, 'binary'));
        self::assertSame([[], []], array_column($files, 'hunks'));
    }

    public function testAnEmptyUntrackedFileIsListedWithoutHunks(): void
    {
        Kit::write($this->repository . '/empty.txt', '');

        $files = GitDiff::files($this->repository);

        self::assertSame([['path' => 'empty.txt', 'added' => 0, 'removed' => 0, 'binary' => false, 'hunks' => []]], $files);
    }

    public function testAnUntrackedSymbolicLinkIsListedWithoutItsTargetsContent(): void
    {
        symlink($this->repository . '/tracked.txt', $this->repository . '/link.txt');

        $files = GitDiff::files($this->repository);

        self::assertSame(['link.txt'], array_column($files, 'path'));
        self::assertSame([], $files[0]['hunks']);
    }

    public function testIgnoredFilesAreNotListed(): void
    {
        Kit::write($this->repository . '/build.log', "noise\n");

        self::assertSame([], GitDiff::files($this->repository));
    }

    public function testChangesThatAreOnlyStagedAreNotListedBecauseTheyAreNotInTheWorkingTreeDiff(): void
    {
        Kit::write($this->repository . '/tracked.txt', "staged\n");
        $this->git(['add', 'tracked.txt'], $this->repository);

        self::assertSame([], GitDiff::files($this->repository));
    }

    public function testResultsAreSortedByPath(): void
    {
        Kit::write($this->repository . '/zeta.txt', "z\n");
        Kit::write($this->repository . '/alpha.txt', "a\n");
        Kit::write($this->repository . '/tracked.txt', "changed\n");

        self::assertSame(['alpha.txt', 'tracked.txt', 'zeta.txt'], array_column(GitDiff::files($this->repository), 'path'));
    }

    public function testAFolderBelowTheRepositoryListsItsOwnFilesWithPathsRelativeToIt(): void
    {
        Kit::write($this->repository . '/sub/inner.txt', "inside\nmore\n");
        Kit::write($this->repository . '/sub/added.txt', "new\n");
        Kit::write($this->repository . '/outside.txt', "root\n");

        $files = GitDiff::files($this->repository . '/sub');

        self::assertSame(['added.txt', 'inner.txt'], array_column($files, 'path'));
    }

    public function testAFreshRepositoryWithoutCommitsListsEverythingAsAdded(): void
    {
        $fresh = Kit::folder('gitdiff-fresh');
        $this->git(['init', '-q'], $fresh);
        Kit::write($fresh . '/first.txt', "one\n");

        $files = GitDiff::files($fresh);

        self::assertSame(['first.txt'], array_column($files, 'path'));
        self::assertSame(1, $files[0]['added']);
    }

    public function testTheIndexIsNotWrittenEvenWhenAPlainStatusWouldRewriteIt(): void
    {
        $index = $this->repository . '/.git/index';
        sleep(1);
        touch($this->repository . '/tracked.txt');
        $before = (string) file_get_contents($index);
        $this->git(['status', '--porcelain=v1'], $this->repository);
        self::assertNotSame($before, (string) file_get_contents($index), 'plain git status rewrites the index in this setup');

        sleep(1);
        touch($this->repository . '/tracked.txt');
        Kit::write($this->repository . '/other.txt', "changed\n");
        $before = (string) file_get_contents($index);
        $files = GitDiff::files($this->repository);

        self::assertSame($before, (string) file_get_contents($index));
        self::assertSame(['other.txt'], array_column($files, 'path'));
    }

    public function testAFolderOutsideAnyRepositoryIsRefused(): void
    {
        $plain = Kit::folder('gitdiff-plain');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('is not inside a git repository');
        GitDiff::files($plain);
    }

    public function testAGitDirectoryExportedByTheEnvironmentDoesNotAnswerForAnotherFolder(): void
    {
        putenv('GIT_DIR=' . $this->repository . '/.git');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('is not inside a git repository');
        GitDiff::files(Kit::folder('gitdiff-with-git-dir'));
    }

    public function testAGitCommandThatTakesTooLongIsStopped(): void
    {
        $bin = Kit::folder('gitdiff-slow-git');
        Kit::write($bin . '/git', "#!/bin/sh\nexec sleep 5\n");
        chmod($bin . '/git', 0755);
        putenv('PATH=' . $bin . PATH_SEPARATOR . (string) getenv('PATH'));
        $started = microtime(true);

        try {
            GitDiff::files($this->repository, 0.5);
            self::fail('a command that takes too long must be stopped');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('took longer than 1 seconds', $error->getMessage());
        }
        self::assertLessThan(4.0, microtime(true) - $started);
    }

    public function testOutputLargerThanTheLimitIsRefusedInsteadOfBeingRead(): void
    {
        Kit::write($this->repository . '/tracked.txt', "changed\n");

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('printed more than 10 bytes');
        GitDiff::files($this->repository, 30.0, 10);
    }

    public function testAMissingGitProgramIsReportedAsSuch(): void
    {
        $empty = Kit::folder('gitdiff-no-git');
        putenv('PATH=' . $empty);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('git is not installed');
        GitDiff::files($this->repository);
    }

    private function git(array $args, string $cwd): string
    {
        $result = (new ProcessRunner())->run(['git', ...$args], $cwd, 30, 65536);
        self::assertTrue($result->succeeded(), implode(' ', $args) . ': ' . $result->stderr);

        return $result->stdout;
    }

    private static function restore(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
}
