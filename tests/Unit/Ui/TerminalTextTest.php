<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ui;

use Phycode\Ui\TerminalText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TerminalTextTest extends TestCase
{
    public static function cleaned(): iterable
    {
        yield 'a clipboard write ended by BEL' => ["copy \e]52;c;SGVsbG8=\x07this", 'copy this'];
        yield 'a clipboard write ended by ST' => ["copy \e]52;c;SGVsbG8=\e\\this", 'copy this'];
        yield 'a clear screen' => ["\e[2Jdone", 'done'];
        yield 'a cursor up over the line above' => ["ok\e[1A", 'ok'];
        yield 'a colour' => ["\e[31mred\e[0m", 'red'];
        yield 'a private mode' => ["\e[?25lhidden cursor", 'hidden cursor'];
        yield 'a lone escape' => ["a\eb", 'ab'];
        yield 'a carriage return and a bell' => ["over\rwritten\x07", 'overwritten'];
        yield 'a backspace and a delete' => ["ab\x08c\x7f", 'abc'];
        yield 'a C1 control as its UTF-8 code point' => ["a\u{9b}2Jb", 'a2Jb'];
        yield 'newlines and tabs are kept' => ["a\n\tb", "a\n\tb"];
        yield 'text in other scripts is kept' => ['ñ 日本 € Ж', 'ñ 日本 € Ж'];
    }

    #[DataProvider('cleaned')]
    public function testControlSequencesAndCharactersAreRemovedAndTheTextStays(string $text, string $clean): void
    {
        self::assertSame($clean, TerminalText::clean($text));
    }

    public function testNoEscapeByteIsLeftEvenWhenASequenceIsCut(): void
    {
        self::assertStringNotContainsString("\e", TerminalText::clean("\e]52;c;x\e\\\e[2J\e[1A\e[?1049h" . "\e\e[" . "\e"));
    }

    public function testWrapKeepsEveryCharacterInRowsOfTheWidth(): void
    {
        self::assertSame(['aaaaaaaaaa', 'aaaaaaaaaa', 'aaaaa', '日本語テキ', 'スト'], TerminalText::wrap(str_repeat('a', 25) . "\n日本語テキスト", 10));
        self::assertSame([''], TerminalText::wrap('', 10));
    }
}
