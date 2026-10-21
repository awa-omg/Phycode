<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Git;

use Phycode\Exec\ProcessRunner;
use Phycode\Git\Branch;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class BranchTest extends TestCase
{
    private string $previousHome;
    private string $repository;

    protected function setUp(): void
    {
        $this->previousHome = (string) getenv('HOME');
        $home = Kit::folder('branch-home');
        Kit::write($home . '/.gitconfig', "[user]\n\tname = Phycode Test\n\temail = test@example.invalid\n[commit]\n\tgpgsign = false\n");
        putenv('HOME=' . $home);

        $this->repository = Kit::folder('branch-repository');
        $this->git(['init', '-q'], $this->repository);
        $this->git(['symbolic-ref', 'HEAD', 'refs/heads/feature/status'], $this->repository);
        $this->git(['commit', '-q', '--allow-empty', '-m', 'first'], $this->repository);
    }

    protected function tearDown(): void
    {
        putenv('HOME=' . $this->previousHome);
    }

    public function testTheCheckedOutBranchIsReported(): void
    {
        self::assertSame('feature/status', Branch::current($this->repository));
    }

    public function testASubfolderReportsTheBranchOfItsRepository(): void
    {
        mkdir($this->repository . '/src');

        self::assertSame('feature/status', Branch::current($this->repository . '/src'));
    }

    public function testADetachedHeadHasNoBranchName(): void
    {
        $this->git(['checkout', '-q', '--detach'], $this->repository);

        self::assertNull(Branch::current($this->repository));
    }

    public function testAFolderOutsideAnyRepositoryHasNoBranch(): void
    {
        self::assertNull(Branch::current(Kit::folder('plain')));
    }

    public function testAFolderThatDoesNotExistHasNoBranch(): void
    {
        self::assertNull(Branch::current($this->repository . '/missing'));
    }

    public function testARepositoryWithoutACommitHasNoBranchName(): void
    {
        $empty = Kit::folder('branch-empty');
        $this->git(['init', '-q'], $empty);

        self::assertNull(Branch::current($empty));
    }

    public function testAGitDirectoryExportedByTheEnvironmentDoesNotAnswerForAnotherFolder(): void
    {
        $previous = getenv('GIT_DIR');
        putenv('GIT_DIR=' . $this->repository . '/.git');
        try {
            self::assertNull(Branch::current(Kit::folder('plain-with-git-dir')));
        } finally {
            if ($previous === false) {
                putenv('GIT_DIR');
            } else {
                putenv('GIT_DIR=' . $previous);
            }
        }
    }

    public function testASecretOfTheEnvironmentDoesNotReachGit(): void
    {
        $bin = Kit::folder('branch-git');
        $log = $bin . '/environment.txt';
        Kit::write($bin . '/git', "#!/bin/sh\nset > '{$log}'\necho main\n");
        chmod($bin . '/git', 0755);
        $previousPath = (string) getenv('PATH');
        putenv('PATH=' . $bin . PATH_SEPARATOR . $previousPath);
        putenv('PHYCODE_TEST_SECRET=s3cret-' . bin2hex(random_bytes(4)));
        try {
            self::assertSame('main', Branch::current($this->repository));
        } finally {
            putenv('PATH=' . $previousPath);
            putenv('PHYCODE_TEST_SECRET');
        }

        $seen = (string) file_get_contents($log);
        self::assertStringContainsString('PATH=', $seen, 'the variables git needs are passed');
        self::assertStringNotContainsString('PHYCODE_TEST_SECRET', $seen);
    }

    public function testAMissingGitProgramReadsAsNull(): void
    {
        $previous = (string) getenv('PATH');
        putenv('PATH=/nonexistent-phycode-path');
        try {
            self::assertNull(Branch::current($this->repository));
        } finally {
            putenv('PATH=' . $previous);
        }
    }

    private function git(array $args, string $cwd): void
    {
        $result = (new ProcessRunner())->run(['git', ...$args], $cwd, 30, 65536);
        self::assertTrue($result->succeeded(), implode(' ', $args) . ': ' . $result->stderr);
    }
}
