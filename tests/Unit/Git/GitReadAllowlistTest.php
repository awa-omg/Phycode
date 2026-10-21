<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Git;

use Phycode\Git\GitPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitReadAllowlistTest extends TestCase
{
    public static function abbreviationsThatReadOrWriteFiles(): iterable
    {
        yield 'blame --contents abbreviated' => [['blame', '--cont=/etc/passwd', 'README.md']];
        yield 'blame -S revisions file' => [['blame', '-S', '/etc/passwd', 'README.md']];
        yield 'blame --ignore-revs-file' => [['blame', '--ignore-revs-file', '/etc/passwd', 'README.md']];
        yield 'diff --no-index abbreviated' => [['diff', '--no-ind', '/etc/passwd', '/dev/null']];
        yield 'log --output abbreviated' => [['log', '--out=/root/.ssh/authorized_keys']];
        yield 'ls-files --exclude-from' => [['ls-files', '--exclude-from=/etc/passwd']];
        yield 'shortlog with an unlisted option' => [['shortlog', '--input=/etc/passwd']];
        yield 'status with an unlisted option' => [['status', '--ignored=matching', '--porcelain=v2', '--foo']];
    }

    #[DataProvider('abbreviationsThatReadOrWriteFiles')]
    public function testAnOptionThatIsNotListedIsRefused(array $args): void
    {
        self::assertNotNull(GitPolicy::refusal('read', $args));
    }

    public static function ordinaryReads(): iterable
    {
        yield 'log by count' => [['log', '-5', '--oneline']];
        yield 'log by author and date' => [['log', '--author=Ana', '--since=2.weeks', '--format=%h %s', '--', 'src']];
        yield 'show a commit' => [['show', '--stat', 'HEAD']];
        yield 'diff of the index' => [['diff', '--cached', '--name-status']];
        yield 'diff with a context count' => [['diff', '-U3', 'HEAD~2..HEAD', '--', 'src/a.php']];
        yield 'blame of a range' => [['blame', '-L', '10,20', '-w', 'src/a.php']];
        yield 'status short' => [['status', '--short', '--branch', '--untracked-files=all']];
        yield 'rev-parse of the top level' => [['rev-parse', '--show-toplevel']];
        yield 'ls-files of the index' => [['ls-files', '--cached', '--others', '--exclude-standard']];
        yield 'shortlog by commits' => [['shortlog', '-sn', 'HEAD']];
    }

    #[DataProvider('ordinaryReads')]
    public function testOrdinaryReadsAreAllowed(array $args): void
    {
        self::assertNull(GitPolicy::refusal('read', $args), json_encode($args) ?: '');
    }
}
