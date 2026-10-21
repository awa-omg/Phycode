<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tui;

use Phycode\Tui\Screen;
use PHPUnit\Framework\TestCase;

final class ScreenTest extends TestCase
{
    public function testWrapsByDisplayWidth(): void
    {
        self::assertSame(['abcd', 'ef'], Screen::wrap('abcdef', 4));
        self::assertSame(["\u{65e5}\u{672c}", "\u{8a9e}"], Screen::wrap("\u{65e5}\u{672c}\u{8a9e}", 4));
        self::assertSame(['ab', 'cd'], Screen::wrap("ab\ncd", 10));
        self::assertSame([''], Screen::wrap('', 10));
    }

    public function testSanitizeRemovesControlSequencesFromOutput(): void
    {
        self::assertSame('red text', Screen::sanitize("\e[31mred\e[0m text"));
        self::assertSame("line one\nline two", Screen::sanitize("line one\r\nline two"));
    }

    public function testFrameNeverExceedsTheTerminalAndKeepsItsParts(): void
    {
        $transcript = [
            ['style' => 'assistant', 'text' => str_repeat('a long answer that must wrap ', 20)],
            ['style' => 'user', 'text' => '> hello'],
        ];

        $frame = Screen::frame(12, 40, 'header line', $transcript, 0, '-----', 'build> ', 'typed', 5, 'status line');
        $rows = explode("\r\n", $frame);

        self::assertCount(12, $rows);
        foreach ($rows as $row) {
            $plain = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $row);
            self::assertLessThanOrEqual(40, mb_strwidth($plain));
        }
        $all = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $frame);
        self::assertStringContainsString('header line', $all);
        self::assertStringContainsString('build> typed', $all);
        self::assertStringContainsString('status line', $all);
        self::assertStringContainsString('> hello', $all);
    }

    public function testScrollingShowsOlderLines(): void
    {
        $transcript = [];
        for ($i = 1; $i <= 30; $i++) {
            $transcript[] = ['style' => 'plain', 'text' => "entry {$i}"];
        }

        $bottom = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', Screen::frame(10, 40, 'h', $transcript, 0, '-', 'p> ', '', 0, 's'));
        $scrolled = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', Screen::frame(10, 40, 'h', $transcript, 20, '-', 'p> ', '', 0, 's'));

        self::assertStringContainsString('entry 30', $bottom);
        self::assertStringNotContainsString('entry 30', $scrolled);
        self::assertStringContainsString('entry 1', $scrolled);
    }
}
