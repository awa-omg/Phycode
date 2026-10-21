<?php

declare(strict_types=1);

namespace Phycode\Ui;

final class TerminalText
{
    private const SEQUENCES = '/\e\[[\x30-\x3f]*[\x20-\x2f]*[\x40-\x7e]|\e\][^\x07\e]*(?:\x07|\e\\\\)/';
    private const CONTROLS = '/[\x00-\x08\x0b-\x1f\x7f]|\xc2[\x80-\x9f]/';

    public static function clean(string $text): string
    {
        $text = preg_replace(self::SEQUENCES, '', $text) ?? $text;

        return preg_replace(self::CONTROLS, '', $text) ?? '';
    }

    public static function wrap(string $text, int $width): array
    {
        $width = max(1, $width);
        $rows = [];
        foreach (explode("\n", $text) as $paragraph) {
            $row = '';
            $used = 0;
            foreach (mb_str_split($paragraph) as $character) {
                $size = mb_strwidth($character);
                if ($used + $size > $width && $row !== '') {
                    $rows[] = $row;
                    $row = '';
                    $used = 0;
                }
                $row .= $character;
                $used += $size;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
