<?php

declare(strict_types=1);

namespace Phycode\Support;

final class Text
{
    public static function clip(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        $omitted = strlen($text) - $max;

        return mb_scrub(substr($text, 0, $max), 'UTF-8') . "\n[... truncated, {$omitted} bytes omitted]";
    }

    public static function headTail(string $text, int $max): string
    {
        $max = max(16, $max);
        if (strlen($text) <= $max) {
            return $text;
        }

        $head = intdiv($max * 7, 10);
        $tail = $max - $head;
        $omitted = strlen($text) - $head - $tail;

        return mb_scrub(substr($text, 0, $head), 'UTF-8')
            . "\n[... {$omitted} bytes omitted ...]\n"
            . mb_scrub(substr($text, -$tail), 'UTF-8');
    }

    public static function isBinary(string $sample): bool
    {
        return str_contains($sample, "\0");
    }

    public static function oneLine(string $text, int $max = 140): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', mb_scrub($text, 'UTF-8')));

        return mb_strlen($flat) > $max ? mb_substr($flat, 0, $max - 1) . '…' : $flat;
    }
}
