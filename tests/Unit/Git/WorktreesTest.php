<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Git;

use Phycode\Git\Worktrees;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorktreesTest extends TestCase
{
    private string $root;
    private string $previousHome;

    protected function setUp(): void
    {
        $this->previousHome = (string) getenv('HOME');
        $home = Kit::folder('worktrees-home');
        Kit::write($home . '/.gitconfig', "[user]\n\tname = Phycode Test\n\temail = test@example.invalid\n[init]\n\tdefaultBranch = main\n");
        putenv('HOME=' . $home);
        $this->root = realpath(Kit::folder('worktrees'));
    }

    protected function tearDown(): void
    {
        putenv('HOME=' . $this->previousHome);
    }

    private function git(string $cwd, string ...$arguments): string
    {
        exec('git -C ' . escapeshellarg($cwd) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));

        return trim(implode("\n", $output));
    }

    private function repository(): void
    {
        $this->git($this->root, 'init', '-q');
        Kit::write($this->root . '/a.txt', "one\n");
        $this->git($this->root, 'add', '.');
        $this->git($this->root, 'commit', '-q', '-m', 'first');
    }

    public static function names(): iterable
    {
        yield 'plain' => ['fix-parser', true];
        yield 'dots and digits' => ['v1.2_a', true];
        yield 'empty' => ['', false];
        yield 'slash' => ['a/b', false];
        yield 'starts with a hyphen' => ['-x', false];
        yield 'two dots' => ['a..b', false];
        yield 'lock suffix' => ['a.lock', false];
        yield 'space' => ['a b', false];
        yield 'too long' => [str_repeat('a', 41), false];
    }

    #[DataProvider('names')]
    public function testOnlyASimpleNameIsAWorktreeName(string $name, bool $valid): void
    {
        self::assertSame($valid, Worktrees::validName($name));
    }

    public function testAWorktreeIsANewBranchOfTheCurrentCommitInsideThePhycodeFolder(): void
    {
        $this->repository();
        $worktrees = new Worktrees($this->root);

        $added = $worktrees->add('try-a');

        self::assertSame($this->root . '/.phycode/worktrees/try-a', $added['path']);
        self::assertSame($added['path'], $added['cwd']);
        self::assertSame('phycode/try-a', $added['branch']);
        self::assertSame($this->git($this->root, 'rev-parse', 'HEAD'), $added['base']);
        self::assertSame("one\n", file_get_contents($added['path'] . '/a.txt'));
        self::assertSame('phycode/try-a', $this->git($added['path'], 'rev-parse', '--abbrev-ref', 'HEAD'));
        self::assertSame("*\n", file_get_contents($this->root . '/.phycode/.gitignore'));
        self::assertSame('', $this->git($this->root, 'status', '--porcelain'), 'the main tree does not see the worktree');
    }

    public function testAWorktreeIsChangedByAnEditAnUntrackedFileOrACommitAndNotByWaiting(): void
    {
        $this->repository();
        $worktrees = new Worktrees($this->root);
        $added = $worktrees->add('w');
        self::assertFalse($worktrees->changed($added));

        file_put_contents($added['path'] . '/a.txt', "two\n");
        self::assertTrue($worktrees->changed($added));
        $this->git($added['path'], 'checkout', '--', 'a.txt');
        self::assertFalse($worktrees->changed($added));

        Kit::write($added['path'] . '/new.txt', "x\n");
        self::assertTrue($worktrees->changed($added));
        $this->git($added['path'], 'add', '.');
        $this->git($added['path'], 'commit', '-q', '-m', 'work');
        self::assertTrue($worktrees->changed($added), 'a commit is a change even when the tree is clean');
    }

    public function testRemovingDeletesTheFolderTheBranchAndTheRecordOfGit(): void
    {
        $this->repository();
        $worktrees = new Worktrees($this->root);
        $added = $worktrees->add('gone');
        file_put_contents($added['path'] . '/a.txt', "dirty\n");

        $worktrees->remove('gone');

        self::assertDirectoryDoesNotExist($added['path']);
        self::assertSame('', $this->git($this->root, 'branch', '--list', 'phycode/gone'));
        self::assertStringNotContainsString('gone', $this->git($this->root, 'worktree', 'list'));
        $worktrees->add('gone');
    }

    public function testASecondWorktreeWithTheSameNameAndAnUnknownRemovalAreRefused(): void
    {
        $this->repository();
        $worktrees = new Worktrees($this->root);
        $worktrees->add('same');

        try {
            $worktrees->add('same');
            self::fail('the name is taken');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('the worktree same exists already', $error->getMessage());
        }
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('no worktree named nothing');
        $worktrees->remove('nothing');
    }

    public function testAnExistingBranchOfThatNameStopsTheWorktreeWithGitsReason(): void
    {
        $this->repository();
        $this->git($this->root, 'branch', 'phycode/taken');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('git worktree failed');
        (new Worktrees($this->root))->add('taken');
    }

    public function testWithoutACommitOrOutsideARepositoryThereIsNothingToBranchFrom(): void
    {
        $this->git($this->root, 'init', '-q');
        try {
            (new Worktrees($this->root))->add('x');
            self::fail('no commit yet');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('has no commit yet', $error->getMessage());
        }

        $this->expectException(PhycodeException::class);
        (new Worktrees(Kit::folder('worktrees-none')))->add('x');
    }

    public function testInsideASubfolderOfTheRepositoryTheRunStartsInTheSameSubfolder(): void
    {
        $this->repository();
        Kit::write($this->root . '/app/b.txt', "b\n");
        $this->git($this->root, 'add', '.');
        $this->git($this->root, 'commit', '-q', '-m', 'app');

        $added = (new Worktrees($this->root . '/app'))->add('sub');

        self::assertSame($this->root . '/app/.phycode/worktrees/sub/app', $added['cwd']);
        self::assertFileExists($added['cwd'] . '/b.txt');
    }

    public function testTheHooksOfTheRepositoryDoNotRunWhenAWorktreeIsMade(): void
    {
        $this->repository();
        Kit::write($this->root . '/.git/hooks/post-checkout', "#!/bin/sh\ntouch " . $this->root . "/hook-ran\n");
        chmod($this->root . '/.git/hooks/post-checkout', 0755);

        (new Worktrees($this->root))->add('quiet');

        self::assertFileDoesNotExist($this->root . '/hook-ran');
    }

    public function testTheListShowsTheBranchWhetherItIsDirtyAndHowManyCommitsItIsAhead(): void
    {
        $this->repository();
        $worktrees = new Worktrees($this->root);
        $clean = $worktrees->add('clean');
        $busy = $worktrees->add('busy');
        Kit::write($busy['path'] . '/x.txt', "x\n");
        $this->git($busy['path'], 'add', '.');
        $this->git($busy['path'], 'commit', '-q', '-m', 'one');
        file_put_contents($busy['path'] . '/a.txt', "dirty\n");

        self::assertSame([], (new Worktrees(Kit::folder('worktrees-empty')))->all());
        self::assertSame(
            [
                ['name' => 'busy', 'branch' => 'phycode/busy', 'dirty' => true, 'ahead' => 1],
                ['name' => 'clean', 'branch' => 'phycode/clean', 'dirty' => false, 'ahead' => 0],
            ],
            $worktrees->all(),
        );
        self::assertFalse($worktrees->changed($clean));
    }
}
