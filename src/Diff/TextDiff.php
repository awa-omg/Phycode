<?php

declare(strict_types=1);

namespace Phycode\Diff;

use Phycode\Support\Text;

final class TextDiff
{
    public const MAX_BYTES = 1048576;

    private const SAMPLE_BYTES = 8192;
    private const CONTEXT = 3;
    private const MAX_EDITS = 1000;

    public static function change(string $path, ?string $before, ?string $after): ?array
    {
        if (strlen($before ?? '') > self::MAX_BYTES || strlen($after ?? '') > self::MAX_BYTES) {
            return null;
        }

        return self::build($path, $before, $after);
    }

    public static function build(string $path, ?string $before, ?string $after): array
    {
        $old = $before ?? '';
        $new = $after ?? '';
        if (Text::isBinary(substr($old, 0, self::SAMPLE_BYTES)) || Text::isBinary(substr($new, 0, self::SAMPLE_BYTES))) {
            return FileDiff::of($path, [], true);
        }

        return FileDiff::of($path, self::hunks(self::edits(self::tokens($old), self::tokens($new))));
    }

    private static function tokens(string $text): array
    {
        $parts = explode("\n", $text);
        $last = array_pop($parts);
        $tokens = [];
        foreach ($parts as $part) {
            $tokens[] = $part . "\n";
        }
        if ($last !== '') {
            $tokens[] = $last;
        }

        return $tokens;
    }

    private static function display(string $token): string
    {
        $line = rtrim($token, "\n");

        return str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
    }

    private static function edits(array $old, array $new): array
    {
        $oldCount = count($old);
        $newCount = count($new);
        $head = 0;
        while ($head < $oldCount && $head < $newCount && $old[$head] === $new[$head]) {
            $head++;
        }
        $tail = 0;
        while ($tail < $oldCount - $head && $tail < $newCount - $head && $old[$oldCount - 1 - $tail] === $new[$newCount - 1 - $tail]) {
            $tail++;
        }
        $middleOld = array_slice($old, $head, $oldCount - $head - $tail);
        $middleNew = array_slice($new, $head, $newCount - $head - $tail);
        $script = self::myers($middleOld, $middleNew);
        $script = $script === null ? self::replacement($middleOld, $middleNew) : self::ordered($script);

        $edits = [];
        for ($index = 0; $index < $head; $index++) {
            $edits[] = ['kind' => FileDiff::CONTEXT, 'text' => self::display($old[$index])];
        }
        foreach ($script as $edit) {
            $edits[] = $edit;
        }
        for ($index = $oldCount - $tail; $index < $oldCount; $index++) {
            $edits[] = ['kind' => FileDiff::CONTEXT, 'text' => self::display($old[$index])];
        }

        return $edits;
    }

    private static function myers(array $old, array $new): ?array
    {
        $oldCount = count($old);
        $newCount = count($new);
        $centre = self::MAX_EDITS + 1;
        $furthest = array_fill(0, 2 * self::MAX_EDITS + 3, 0);
        $trace = [];
        for ($d = 0; $d <= self::MAX_EDITS; $d++) {
            $trace[] = array_slice($furthest, $centre - $d - 1, 2 * $d + 3);
            for ($k = -$d; $k <= $d; $k += 2) {
                $slot = $centre + $k;
                $x = ($k === -$d || ($k !== $d && $furthest[$slot - 1] < $furthest[$slot + 1])) ? $furthest[$slot + 1] : $furthest[$slot - 1] + 1;
                $y = $x - $k;
                while ($x < $oldCount && $y < $newCount && $old[$x] === $new[$y]) {
                    $x++;
                    $y++;
                }
                $furthest[$slot] = $x;
                if ($x >= $oldCount && $y >= $newCount) {
                    return self::backtrack($old, $new, $trace);
                }
            }
        }

        return null;
    }

    private static function backtrack(array $old, array $new, array $trace): array
    {
        $x = count($old);
        $y = count($new);
        $script = [];
        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $window = $trace[$d];
            $base = $d + 1;
            $k = $x - $y;
            $previous = ($k === -$d || ($k !== $d && $window[$k - 1 + $base] < $window[$k + 1 + $base])) ? $k + 1 : $k - 1;
            $previousX = $window[$previous + $base];
            $previousY = $previousX - $previous;
            while ($x > $previousX && $y > $previousY) {
                $script[] = ['kind' => FileDiff::CONTEXT, 'text' => self::display($old[$x - 1])];
                $x--;
                $y--;
            }
            if ($d > 0) {
                $script[] = $x === $previousX
                    ? ['kind' => FileDiff::ADDED, 'text' => self::display($new[$previousY])]
                    : ['kind' => FileDiff::REMOVED, 'text' => self::display($old[$previousX])];
            }
            $x = $previousX;
            $y = $previousY;
        }

        return array_reverse($script);
    }

    private static function ordered(array $script): array
    {
        $ordered = [];
        $removed = [];
        $added = [];
        foreach ($script as $edit) {
            if ($edit['kind'] === FileDiff::REMOVED) {
                $removed[] = $edit;
            } elseif ($edit['kind'] === FileDiff::ADDED) {
                $added[] = $edit;
            } else {
                foreach ([...$removed, ...$added] as $change) {
                    $ordered[] = $change;
                }
                $ordered[] = $edit;
                $removed = [];
                $added = [];
            }
        }
        foreach ([...$removed, ...$added] as $change) {
            $ordered[] = $change;
        }

        return $ordered;
    }

    private static function replacement(array $old, array $new): array
    {
        $edits = [];
        foreach ($old as $token) {
            $edits[] = ['kind' => FileDiff::REMOVED, 'text' => self::display($token)];
        }
        foreach ($new as $token) {
            $edits[] = ['kind' => FileDiff::ADDED, 'text' => self::display($token)];
        }

        return $edits;
    }

    private static function hunks(array $edits): array
    {
        $oldBefore = [];
        $newBefore = [];
        $old = 0;
        $new = 0;
        foreach ($edits as $index => $edit) {
            $oldBefore[$index] = $old;
            $newBefore[$index] = $new;
            if ($edit['kind'] !== FileDiff::ADDED) {
                $old++;
            }
            if ($edit['kind'] !== FileDiff::REMOVED) {
                $new++;
            }
        }

        $ranges = [];
        foreach ($edits as $index => $edit) {
            if ($edit['kind'] === FileDiff::CONTEXT) {
                continue;
            }
            $previous = count($ranges) - 1;
            if ($previous >= 0 && $index - $ranges[$previous][1] - 1 <= 2 * self::CONTEXT) {
                $ranges[$previous][1] = $index;
            } else {
                $ranges[] = [$index, $index];
            }
        }

        $hunks = [];
        foreach ($ranges as [$first, $last]) {
            $start = max(0, $first - self::CONTEXT);
            $end = min(count($edits) - 1, $last + self::CONTEXT);
            $lines = [];
            $oldLines = 0;
            $newLines = 0;
            for ($index = $start; $index <= $end; $index++) {
                $kind = $edits[$index]['kind'];
                $oldNo = $kind === FileDiff::ADDED ? null : $oldBefore[$index] + 1;
                $newNo = $kind === FileDiff::REMOVED ? null : $newBefore[$index] + 1;
                $oldLines += $oldNo === null ? 0 : 1;
                $newLines += $newNo === null ? 0 : 1;
                $lines[] = ['kind' => $kind, 'text' => $edits[$index]['text'], 'oldNo' => $oldNo, 'newNo' => $newNo];
            }
            $hunks[] = [
                'oldStart' => $oldLines > 0 ? $oldBefore[$start] + 1 : $oldBefore[$start],
                'oldLines' => $oldLines,
                'newStart' => $newLines > 0 ? $newBefore[$start] + 1 : $newBefore[$start],
                'newLines' => $newLines,
                'lines' => $lines,
            ];
        }

        return $hunks;
    }
}
