<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Diff;

use Phycode\Diff\TextDiff;
use PHPUnit\Framework\TestCase;

final class TextDiffTest extends TestCase
{
    public function testIdenticalTextsHaveNoHunks(): void
    {
        self::assertSame(
            ['path' => 'a.txt', 'added' => 0, 'removed' => 0, 'binary' => false, 'hunks' => []],
            TextDiff::build('a.txt', "one\ntwo\n", "one\ntwo\n"),
        );
    }

    public function testAnInsertionShowsThreeLinesOfContextOnEachSide(): void
    {
        $diff = TextDiff::build('f', "l1\nl2\nl3\nl4\nl5\nl6\nl7\nl8\nl9\nl10\n", "l1\nl2\nl3\nl4\nl5\nnew\nl6\nl7\nl8\nl9\nl10\n");

        self::assertSame(1, $diff['added']);
        self::assertSame(0, $diff['removed']);
        self::assertSame([[3, 6, 3, 7]], DiffSupport::headers($diff));
        self::assertSame([
            ['ctx', 'l3', 3, 3],
            ['ctx', 'l4', 4, 4],
            ['ctx', 'l5', 5, 5],
            ['add', 'new', null, 6],
            ['ctx', 'l6', 6, 7],
            ['ctx', 'l7', 7, 8],
            ['ctx', 'l8', 8, 9],
        ], DiffSupport::lines($diff));
    }

    public function testADeletionAtTheStartNumbersTheRemainingLines(): void
    {
        $diff = TextDiff::build('f', "a\nb\nc\n", "b\nc\n");

        self::assertSame(0, $diff['added']);
        self::assertSame(1, $diff['removed']);
        self::assertSame([[1, 3, 1, 2]], DiffSupport::headers($diff));
        self::assertSame([['del', 'a', 1, null], ['ctx', 'b', 2, 1], ['ctx', 'c', 3, 2]], DiffSupport::lines($diff));
    }

    public function testAReplacementListsTheRemovedLineBeforeTheAddedOne(): void
    {
        $diff = TextDiff::build('f', "a\nb\nc\n", "a\nX\nc\n");

        self::assertSame(1, $diff['added']);
        self::assertSame(1, $diff['removed']);
        self::assertSame([[1, 3, 1, 3]], DiffSupport::headers($diff));
        self::assertSame([
            ['ctx', 'a', 1, 1],
            ['del', 'b', 2, null],
            ['add', 'X', null, 2],
            ['ctx', 'c', 3, 3],
        ], DiffSupport::lines($diff));
    }

    public function testANewFileIsOneHunkStartingAtZero(): void
    {
        $diff = TextDiff::build('new.txt', null, "x\ny\n");

        self::assertSame(2, $diff['added']);
        self::assertSame([[0, 0, 1, 2]], DiffSupport::headers($diff));
        self::assertSame([['add', 'x', null, 1], ['add', 'y', null, 2]], DiffSupport::lines($diff));
    }

    public function testADeletedFileIsOneHunkEndingAtZero(): void
    {
        $diff = TextDiff::build('gone.txt', "x\ny\n", '');

        self::assertSame(2, $diff['removed']);
        self::assertSame([[1, 2, 0, 0]], DiffSupport::headers($diff));
    }

    public function testEmptyFilesHaveNoHunks(): void
    {
        self::assertSame([], TextDiff::build('f', '', '')['hunks']);
        self::assertSame([], TextDiff::build('f', null, null)['hunks']);
    }

    public function testAnInsertionIntoAnEmptyFileStartsAtZeroOnTheOldSide(): void
    {
        $diff = TextDiff::build('f', '', "only\n");

        self::assertSame([[0, 0, 1, 1]], DiffSupport::headers($diff));
        self::assertSame(1, $diff['added']);
    }

    public function testTheLastLineWithoutANewlineDiffersFromTheSameLineWithOne(): void
    {
        $diff = TextDiff::build('f', "a\nb", "a\nb\n");

        self::assertSame(1, $diff['added']);
        self::assertSame(1, $diff['removed']);
        self::assertSame([['ctx', 'a', 1, 1], ['del', 'b', 2, null], ['add', 'b', null, 2]], DiffSupport::lines($diff));
    }

    public function testLinesAddedAfterALastLineWithoutANewlineChangeThatLine(): void
    {
        $diff = TextDiff::build('f', "a\nb", "a\nb\nc");

        self::assertSame(2, $diff['added']);
        self::assertSame(1, $diff['removed']);
        self::assertSame([
            ['ctx', 'a', 1, 1],
            ['del', 'b', 2, null],
            ['add', 'b', null, 2],
            ['add', 'c', null, 3],
        ], DiffSupport::lines($diff));
    }

    public function testTheLineEndingIsNotPartOfTheTextButStillChangesTheLine(): void
    {
        $diff = TextDiff::build('f', "a\r\nb\r\n", "a\nb\n");

        self::assertSame(2, $diff['added']);
        self::assertSame(2, $diff['removed']);
        self::assertSame(['a', 'b', 'a', 'b'], array_column(DiffSupport::lines($diff), 1));
    }

    public function testChangesSixUnchangedLinesApartShareOneHunk(): void
    {
        $before = DiffSupport::numbered(20);
        $after = $before;
        $after[0] = 'X1';
        $after[7] = 'X8';

        self::assertSame([[1, 11, 1, 11]], DiffSupport::headers(TextDiff::build('f', DiffSupport::join($before), DiffSupport::join($after))));
    }

    public function testChangesSevenUnchangedLinesApartStartTwoHunks(): void
    {
        $before = DiffSupport::numbered(20);
        $after = $before;
        $after[0] = 'X1';
        $after[8] = 'X9';

        self::assertSame([[1, 4, 1, 4], [6, 7, 6, 7]], DiffSupport::headers(TextDiff::build('f', DiffSupport::join($before), DiffSupport::join($after))));
    }

    public function testBinaryContentHasNoHunks(): void
    {
        self::assertSame(
            ['path' => 'b.dat', 'added' => 0, 'removed' => 0, 'binary' => true, 'hunks' => []],
            TextDiff::build('b.dat', "a\0b\n", "a\0c\n"),
        );
    }

    public function testBinaryIsDecidedByTheNewTextAsWellAsTheOldOne(): void
    {
        $diff = TextDiff::build('f', "text\n", "te\0xt\n");

        self::assertTrue($diff['binary']);
        self::assertSame([], $diff['hunks']);
    }

    public function testANulByteAfterTheFirst8KiBDoesNotMakeTheFileBinary(): void
    {
        $before = str_repeat("x\n", 5000) . "\0\n";
        $diff = TextDiff::build('f', $before, $before . "y\n");

        self::assertFalse($diff['binary']);
        self::assertSame(1, $diff['added']);
    }

    public function testAVeryDifferentFileIsStillRebuiltFromItsHunks(): void
    {
        $before = DiffSupport::join(array_map(static fn (int $n): string => "old {$n}", range(1, 1500)));
        $after = DiffSupport::join(array_map(static fn (int $n): string => "new {$n}", range(1, 1500)));

        $diff = TextDiff::build('f', $before, $after);

        self::assertSame(1500, $diff['added']);
        self::assertSame(1500, $diff['removed']);
        self::assertSame($after, DiffSupport::rebuild($before, $diff));
    }

    public function testRandomEditsAreRebuiltAndAreAsShortAsPossible(): void
    {
        $random = new DiffRandom(20261019);
        $alphabet = ['a', 'b', 'c', '', '-- x', '+++ y', '@@ -1 +1 @@', 'diff --git a/q b/q', ' sp', '\\ x'];
        for ($case = 1; $case <= 300; $case++) {
            $before = DiffSupport::randomText($random, $alphabet, 40);
            $after = $case % 2 === 0 ? DiffSupport::mutate($random, $before, $alphabet) : DiffSupport::randomText($random, $alphabet, 40);
            $diff = TextDiff::build('f', $before, $after);

            self::assertSame($after, DiffSupport::rebuild($before, $diff), "case {$case} does not rebuild");
            $oldLines = DiffSupport::lineList($before);
            $newLines = DiffSupport::lineList($after);
            self::assertSame(
                count($oldLines) + count($newLines) - 2 * DiffSupport::longestCommonSubsequence($oldLines, $newLines),
                $diff['added'] + $diff['removed'],
                "case {$case} is not the shortest diff",
            );
        }
    }
}
