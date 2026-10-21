<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Diff;

use Phycode\Diff\TextDiff;
use Phycode\Diff\UnifiedDiff;
use Phycode\Exec\ProcessRunner;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class UnifiedDiffTest extends TestCase
{
    public function testEachFileOfAGitDiffHasItsCountsAndHunks(): void
    {
        $files = UnifiedDiff::parse(self::lines([
            'diff --git a/src/app.php b/src/app.php',
            'index 1111111..2222222 100644',
            '--- a/src/app.php',
            '+++ b/src/app.php',
            '@@ -1,4 +1,4 @@',
            ' <?php',
            '-echo "old";',
            '+echo "new";',
            ' ',
            ' done();',
            'diff --git a/new.txt b/new.txt',
            'new file mode 100644',
            'index 0000000..3333333',
            '--- /dev/null',
            '+++ b/new.txt',
            '@@ -0,0 +1,2 @@',
            '+first',
            '+second',
            'diff --git a/gone.txt b/gone.txt',
            'deleted file mode 100644',
            'index 4444444..0000000',
            '--- a/gone.txt',
            '+++ /dev/null',
            '@@ -1 +0,0 @@',
            '-bye',
            'diff --git a/data.bin b/data.bin',
            'index 5555555..6666666 100644',
            'Binary files a/data.bin and b/data.bin differ',
        ]));

        self::assertSame(['src/app.php', 'new.txt', 'gone.txt', 'data.bin'], array_column($files, 'path'));
        self::assertSame([1, 2, 0, 0], array_column($files, 'added'));
        self::assertSame([1, 0, 1, 0], array_column($files, 'removed'));
        self::assertSame([false, false, false, true], array_column($files, 'binary'));
        self::assertSame([[1, 4, 1, 4]], DiffSupport::headers($files[0]));
        self::assertSame([
            ['ctx', '<?php', 1, 1],
            ['del', 'echo "old";', 2, null],
            ['add', 'echo "new";', null, 2],
            ['ctx', '', 3, 3],
            ['ctx', 'done();', 4, 4],
        ], DiffSupport::lines($files[0]));
        self::assertSame([[0, 0, 1, 2]], DiffSupport::headers($files[1]));
        self::assertSame([[1, 1, 0, 0]], DiffSupport::headers($files[2]));
        self::assertSame([], $files[3]['hunks']);
    }

    public function testContentThatLooksLikeAHeaderInsideAHunkIsStillContent(): void
    {
        $files = UnifiedDiff::parse(self::lines([
            'diff --git a/c.txt b/c.txt',
            '--- a/c.txt',
            '+++ b/c.txt',
            '@@ -1,3 +1,4 @@',
            ' keep',
            '--- old comment',
            '+++ new',
            '+@@ -1 +1 @@',
            ' diff --git a/x b/x',
        ]));

        self::assertCount(1, $files);
        self::assertSame('c.txt', $files[0]['path']);
        self::assertSame([
            ['ctx', 'keep', 1, 1],
            ['del', '-- old comment', 2, null],
            ['add', '++ new', null, 2],
            ['add', '@@ -1 +1 @@', null, 3],
            ['ctx', 'diff --git a/x b/x', 3, 4],
        ], DiffSupport::lines($files[0]));
    }

    public function testTheNoNewlineMarkerIsNotAContentLine(): void
    {
        $files = UnifiedDiff::parse(self::lines([
            'diff --git a/n.txt b/n.txt',
            '--- a/n.txt',
            '+++ b/n.txt',
            '@@ -1 +1 @@',
            '-old',
            '\\ No newline at end of file',
            '+new',
            '\\ No newline at end of file',
        ]));

        self::assertSame([['del', 'old', 1, null], ['add', 'new', null, 1]], DiffSupport::lines($files[0]));
        self::assertSame(1, $files[0]['added']);
        self::assertSame(1, $files[0]['removed']);
    }

    public function testQuotedNamesAreUnquotedIncludingOctalBytes(): void
    {
        $files = UnifiedDiff::parse(self::lines([
            'diff --git "a/caf\303\251 \"x\".txt" "b/caf\303\251 \"x\".txt"',
            'new file mode 100644',
            'index 0000000..7777777',
            '--- /dev/null',
            '+++ "b/caf\303\251 \"x\".txt"',
            '@@ -0,0 +1 @@',
            '+hi',
        ]));

        self::assertSame('café "x".txt', $files[0]['path']);
        self::assertSame(1, $files[0]['added']);
    }

    public function testNamesWithSpacesAreReadWhileTheTabAfterThemIsIgnored(): void
    {
        $files = UnifiedDiff::parse(self::lines([
            'diff --git a/my notes.txt b/my notes.txt',
            "--- a/my notes.txt\t",
            "+++ b/my notes.txt\t",
            '@@ -1 +1 @@',
            '-a',
            '+b',
        ]));

        self::assertSame('my notes.txt', $files[0]['path']);
    }

    public function testAHunkHeaderWithoutCountsMeansOneLine(): void
    {
        $files = UnifiedDiff::parse(self::lines([
            'diff --git a/one.txt b/one.txt',
            '--- a/one.txt',
            '+++ b/one.txt',
            '@@ -3 +3 @@ function name()',
            '-x',
            '+y',
        ]));

        self::assertSame([[3, 1, 3, 1]], DiffSupport::headers($files[0]));
    }

    public function testCarriageReturnsOfCrlfFilesAreNotPartOfTheText(): void
    {
        $files = UnifiedDiff::parse(self::lines([
            'diff --git a/w.txt b/w.txt',
            '--- a/w.txt',
            '+++ b/w.txt',
            '@@ -1 +1 @@',
            "-a\r",
            "+b\r",
        ]));

        self::assertSame([['del', 'a', 1, null], ['add', 'b', null, 1]], DiffSupport::lines($files[0]));
    }

    public function testAModeOnlyChangeIsListedWithoutHunks(): void
    {
        $files = UnifiedDiff::parse(self::lines([
            'diff --git a/run.sh b/run.sh',
            'old mode 100644',
            'new mode 100755',
        ]));

        self::assertSame([['path' => 'run.sh', 'added' => 0, 'removed' => 0, 'binary' => false, 'hunks' => []]], $files);
    }

    public function testAnEmptyNewFileIsListedWithoutHunks(): void
    {
        $files = UnifiedDiff::parse(self::lines([
            'diff --git a/empty.txt b/empty.txt',
            'new file mode 100644',
            'index 0000000..e69de29',
        ]));

        self::assertSame('empty.txt', $files[0]['path']);
        self::assertSame([], $files[0]['hunks']);
    }

    public function testTextWithoutDiffSectionsHasNoFiles(): void
    {
        self::assertSame([], UnifiedDiff::parse(''));
        self::assertSame([], UnifiedDiff::parse("\n"));
        self::assertSame([], UnifiedDiff::parse("just some text\n"));
    }

    public function testAConflictedFileIsListedWithoutHunksBecauseCombinedHunksCannotBeShown(): void
    {
        $files = UnifiedDiff::parse(self::lines([
            'diff --cc merged.txt',
            'index 1111111,2222222..0000000',
            '--- a/merged.txt',
            '+++ b/merged.txt',
            '@@@ -1,3 -1,3 +1,7 @@@',
            '  a',
            '- b',
            '+ c',
        ]));

        self::assertSame('merged.txt', $files[0]['path']);
        self::assertSame([], $files[0]['hunks']);
    }

    public function testAnUnambiguousChangeHasTheSameStructureAsTextDiff(): void
    {
        $parsed = UnifiedDiff::parse(self::lines([
            'diff --git a/f b/f',
            'index 1..2 100644',
            '--- a/f',
            '+++ b/f',
            '@@ -1,3 +1,4 @@',
            ' a',
            ' b',
            '+X',
            ' c',
        ]));

        self::assertSame(TextDiff::build('f', "a\nb\nc\n", "a\nb\nX\nc\n"), $parsed[0]);
    }

    public function testGitOutputForRandomFilesRebuildsTheNewFileWithTheShortestDiff(): void
    {
        $folder = Kit::folder('unified-git');
        $random = new DiffRandom(4242);
        $alphabet = ['a', 'b', 'c', '', '-- x', '+++ y', '--- z', '@@ -1 +1 @@', 'diff --git a/q b/q', ' sp', '\\ x', '++', '-'];
        for ($case = 1; $case <= 60; $case++) {
            $before = DiffSupport::randomText($random, $alphabet, 30);
            $after = $case % 3 === 0 ? DiffSupport::randomText($random, $alphabet, 30) : DiffSupport::mutate($random, $before, $alphabet);
            Kit::write($folder . '/before.txt', $before);
            Kit::write($folder . '/after.txt', $after);

            $result = (new ProcessRunner())->run(
                ['git', 'diff', '--no-index', '--no-color', '--no-ext-diff', '--no-textconv', '--src-prefix=a/', '--dst-prefix=b/', '--unified=3', '--', 'before.txt', 'after.txt'],
                $folder,
                30,
                1048576,
            );
            self::assertContains($result->exitCode, [0, 1], "case {$case}: " . $result->stderr);
            $parsed = UnifiedDiff::parse($result->stdout);
            $diff = $parsed[0] ?? TextDiff::build('f', $before, $before);

            self::assertSame($after, DiffSupport::rebuild($before, $diff), "case {$case} does not rebuild from git's hunks");
            $ours = TextDiff::build('f', $before, $after);
            self::assertSame($ours['added'] + $ours['removed'], $diff['added'] + $diff['removed'], "case {$case} has a longer diff than TextDiff");
        }
    }

    private static function lines(array $lines): string
    {
        return implode("\n", $lines) . "\n";
    }
}
