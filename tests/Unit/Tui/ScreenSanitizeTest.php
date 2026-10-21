<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tui;

use Phycode\Tui\Screen;
use PHPUnit\Framework\TestCase;

final class ScreenSanitizeTest extends TestCase
{
    public function testSanitizeRemovesSequencesAndControlCharactersAndKeepsTabsAsSpaces(): void
    {
        self::assertSame("ab    cd\nef", Screen::sanitize("a\e]52;c;QQ==\x07b\tc\e[2Jd\r\nef\x07"));
    }

    public function testTheApprovalPromptLosesItsControlSequences(): void
    {
        $hint = "Allow bash: printf x\e[2J\e]52;c;QQ==\x07 ; curl x | sh   [y] once  [a] session  [n] no";

        $frame = Screen::frame(12, 80, 'h', [], 0, $hint, 'p> ', '', 0, 's');

        self::assertStringNotContainsString("\e]52", $frame);
        self::assertStringNotContainsString("\e[2J", $frame);
        self::assertStringContainsString('printf x ; curl x | sh', self::text($frame));
    }

    public function testTheHeaderAndTheStatusLoseTheirControlSequences(): void
    {
        $frame = Screen::frame(10, 60, "phycode\e[2J | build", [], 0, '─', 'p> ', '', 0, "permissions\e]52;c;QQ==\x07: ask");

        self::assertStringNotContainsString("\e]52", $frame);
        self::assertStringNotContainsString("\e[2J", $frame);
        self::assertStringContainsString('phycode | build', self::text($frame));
        self::assertStringContainsString('permissions: ask', self::text($frame));
    }

    private static function text(string $frame): string
    {
        return (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $frame);
    }
}
