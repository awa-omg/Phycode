<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Diff;

final class DiffSupport
{
    public static function headers(array $diff): array
    {
        return array_map(
            static fn (array $hunk): array => [$hunk['oldStart'], $hunk['oldLines'], $hunk['newStart'], $hunk['newLines']],
            $diff['hunks'],
        );
    }

    public static function lines(array $diff): array
    {
        $lines = [];
        foreach ($diff['hunks'] as $hunk) {
            foreach ($hunk['lines'] as $line) {
                $lines[] = [$line['kind'], $line['text'], $line['oldNo'], $line['newNo']];
            }
        }

        return $lines;
    }

    public static function rebuild(string $before, array $diff): string
    {
        $old = self::lineList($before);
        $result = [];
        $position = 0;
        foreach ($diff['hunks'] as $hunk) {
            $start = $hunk['oldLines'] === 0 ? $hunk['oldStart'] : $hunk['oldStart'] - 1;
            while ($position < $start) {
                $result[] = $old[$position++];
            }
            foreach ($hunk['lines'] as $line) {
                if ($line['kind'] === 'ctx') {
                    $result[] = $line['text'];
                    $position++;
                } elseif ($line['kind'] === 'del') {
                    $position++;
                } else {
                    $result[] = $line['text'];
                }
            }
        }
        while ($position < count($old)) {
            $result[] = $old[$position++];
        }

        return self::join($result);
    }

    public static function lineList(string $text): array
    {
        $parts = explode("\n", $text);
        if (end($parts) === '') {
            array_pop($parts);
        }

        return $parts;
    }

    public static function join(array $lines): string
    {
        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    public static function numbered(int $count): array
    {
        return array_map(static fn (int $n): string => "line {$n}", range(1, $count));
    }

    public static function randomText(DiffRandom $random, array $alphabet, int $maxLines): string
    {
        $lines = [];
        for ($i = $random->int(0, $maxLines); $i > 0; $i--) {
            $lines[] = $alphabet[$random->int(0, count($alphabet) - 1)];
        }

        return self::join($lines);
    }

    public static function mutate(DiffRandom $random, string $text, array $alphabet): string
    {
        $lines = self::lineList($text);
        for ($i = $random->int(0, 6); $i > 0; $i--) {
            $choice = $random->int(0, 2);
            if ($choice === 0 || $lines === []) {
                array_splice($lines, $random->int(0, count($lines)), 0, [$alphabet[$random->int(0, count($alphabet) - 1)]]);
            } elseif ($choice === 1) {
                array_splice($lines, $random->int(0, count($lines) - 1), 1);
            } else {
                $lines[$random->int(0, count($lines) - 1)] = $alphabet[$random->int(0, count($alphabet) - 1)];
            }
        }

        return self::join($lines);
    }

    public static function longestCommonSubsequence(array $old, array $new): int
    {
        $previous = array_fill(0, count($new) + 1, 0);
        foreach ($old as $oldLine) {
            $current = [0];
            foreach ($new as $index => $newLine) {
                $current[] = $oldLine === $newLine ? $previous[$index] + 1 : max($previous[$index + 1], $current[$index]);
            }
            $previous = $current;
        }

        return $previous[count($new)];
    }
}
