<?php

declare(strict_types=1);

namespace Phycode\Tui;

use Phycode\Ui\TerminalText;

final class Screen
{
    private const STYLES = [
        'header' => "\e[7m",
        'hint' => "\e[7m",
        'status' => "\e[2m",
        'user' => "\e[1m",
        'tool' => "\e[2m",
        'info' => "\e[2m",
        'sub' => "\e[36m",
        'warn' => "\e[33m",
        'error' => "\e[31m",
    ];

    public static function sanitize(string $text): string
    {
        return str_replace("\t", '    ', TerminalText::clean($text));
    }

    public static function wrap(string $text, int $width): array
    {
        return TerminalText::wrap($text, $width);
    }

    public static function frame(
        int $rows,
        int $cols,
        string $header,
        array $transcript,
        int $scroll,
        string $hint,
        string $prompt,
        string $input,
        int $cursor,
        string $status,
    ): string {
        $rows = max(6, $rows);
        $cols = max(20, $cols);
        $hintLines = self::hintRows(self::sanitize($hint), $cols, $rows - 4);
        $viewport = $rows - 3 - count($hintLines);

        $lines = [];
        foreach ($transcript as $entry) {
            foreach (self::wrap($entry['text'], $cols) as $line) {
                $lines[] = ['style' => $entry['style'], 'text' => $line];
            }
        }
        $end = max(0, count($lines) - max(0, $scroll));
        $visible = array_slice($lines, max(0, $end - $viewport), min($end, $viewport));
        $padding = array_fill(0, $viewport - count($visible), ['style' => 'plain', 'text' => '']);

        $rendered = [self::paint('header', self::sanitize($header), $cols)];
        foreach ([...$padding, ...$visible] as $line) {
            $rendered[] = self::paint($line['style'], $line['text'], $cols);
        }
        foreach ($hintLines as $line) {
            $rendered[] = self::paint('hint', $line, $cols);
        }
        [$inputView, $cursorColumn] = self::inputView($prompt, $input, $cursor, $cols);
        $rendered[] = self::paint('plain', $prompt . $inputView, $cols);
        $rendered[] = self::paint('status', self::sanitize($status), $cols);

        return "\e[H" . implode("\r\n", $rendered) . "\e[" . ($rows - 1) . ';' . $cursorColumn . "H\e[?25h";
    }

    private static function hintRows(string $hint, int $cols, int $max): array
    {
        $lines = self::wrap($hint, $cols);
        if (count($lines) <= $max) {
            return $lines;
        }
        $head = intdiv($max - 1, 2);
        $tail = $max - 1 - $head;
        $end = self::wrap(self::tail($hint, $tail * $cols), $cols);

        return [...array_slice($lines, 0, $head), '[... rows hidden ...]', ...array_slice($end, -$tail)];
    }

    private static function inputView(string $prompt, string $input, int $cursor, int $cols): array
    {
        $available = max(1, $cols - mb_strwidth($prompt) - 1);
        $before = self::tail(mb_substr($input, 0, $cursor), $available);
        $after = mb_strimwidth(mb_substr($input, $cursor), 0, max(0, $available - mb_strwidth($before)), '');

        return [$before . $after, mb_strwidth($prompt) + mb_strwidth($before) + 1];
    }

    private static function tail(string $text, int $width): string
    {
        $result = '';
        $used = 0;
        foreach (array_reverse(mb_str_split($text)) as $character) {
            $size = mb_strwidth($character);
            if ($used + $size > $width) {
                break;
            }
            $result = $character . $result;
            $used += $size;
        }

        return $result;
    }

    private static function paint(string $style, string $text, int $cols): string
    {
        return (self::STYLES[$style] ?? '') . self::fit($text, $cols) . "\e[0m";
    }

    private static function fit(string $text, int $cols): string
    {
        $text = mb_strimwidth($text, 0, $cols, '');

        return $text . str_repeat(' ', max(0, $cols - mb_strwidth($text)));
    }
}
