<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Lsp;

use Phycode\Lsp\LspFiles;
use Phycode\Lsp\LspPositions;
use PHPUnit\Framework\TestCase;

final class LspPositionsTest extends TestCase
{
    public function testCharactersBecomeUtf16Units(): void
    {
        $cases = [
            'ascii' => ['hello', 3, 3],
            'a two byte letter is one unit' => ['héllo', 2, 2],
            'an emoji is two units' => ['a😀b', 2, 3],
            'after the emoji' => ['a😀b', 3, 4],
            'cjk is one unit each' => ['日本語', 2, 2],
            'past the end of the line' => ['ab', 10, 2],
            'the start' => ['a😀b', 0, 0],
            'two emoji' => ['😀😀x', 2, 4],
        ];

        foreach ($cases as $label => [$line, $characters, $units]) {
            self::assertSame($units, LspPositions::toUtf16Units($line, $characters), $label);
        }
    }

    public function testUtf16UnitsBecomeCharacters(): void
    {
        $cases = [
            'ascii' => ['hello', 3, 3],
            'a two byte letter' => ['héllo', 2, 2],
            'before the emoji' => ['a😀b', 1, 1],
            'after the emoji' => ['a😀b', 3, 2],
            'the end after the emoji' => ['a😀b', 4, 3],
            'cjk' => ['日本語', 1, 1],
            'past the end' => ['ab', 10, 2],
        ];

        foreach ($cases as $label => [$line, $units, $characters]) {
            self::assertSame($characters, LspPositions::toCharacters($line, $units), $label);
        }
    }

    public function testTheTwoConversionsAgreeOnEveryCharacterOfALine(): void
    {
        $line = 'x = "é😀日本" . $y;';
        foreach (range(0, mb_strlen($line)) as $character) {
            self::assertSame($character, LspPositions::toCharacters($line, LspPositions::toUtf16Units($line, $character)));
        }
    }

    public function testAPathSurvivesTheRoundTripThroughAUri(): void
    {
        foreach (['/srv/app/src/Foo.php', '/tmp/a b/é#%.php', '/tmp/x+y@z:w/(1).php'] as $path) {
            $uri = LspFiles::uri($path);

            self::assertStringStartsWith('file:///', $uri);
            self::assertStringNotContainsString(' ', $uri);
            self::assertSame($path, LspFiles::path($uri));
        }
        self::assertSame('file:///tmp/a%20b/%C3%A9.php', LspFiles::uri('/tmp/a b/é.php'));
    }

    public function testOnlyFileUrisBecomePaths(): void
    {
        self::assertNull(LspFiles::path('untitled:Untitled-1'));
        self::assertNull(LspFiles::path('jar:file:///x.jar!/A.class'));
        self::assertSame('/x y', LspFiles::path('file:///x%20y'));
    }

    public function testLinesSplitOnEveryLineEnding(): void
    {
        self::assertSame(['a', 'b', 'c', 'd', ''], LspFiles::lines("a\r\nb\rc\nd\n"));
        self::assertSame([''], LspFiles::lines(''));
    }

    public function testTheLanguageIdFollowsTheExtension(): void
    {
        self::assertSame('typescriptreact', LspFiles::languageId('/p/App.tsx'));
        self::assertSame('php', LspFiles::languageId('/p/INDEX.PHP'));
        self::assertSame('zzz', LspFiles::languageId('/p/file.zzz'));
        self::assertSame('plaintext', LspFiles::languageId('/p/Makefile'));
    }
}
