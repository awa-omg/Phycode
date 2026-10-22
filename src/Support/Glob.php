<?php

declare(strict_types=1);

namespace Phycode\Support;

final class Glob
{
    public const MAX_LENGTH = 200;
    private const MAX_DOUBLE_STARS = 6;

    public static function valid(string $pattern): bool
    {
        return $pattern !== ''
            && strlen($pattern) <= self::MAX_LENGTH
            && !str_contains($pattern, "\0")
            && substr_count($pattern, '**') <= self::MAX_DOUBLE_STARS;
    }

    public static function matches(string $pattern, string $path): bool
    {
        $pattern = (string) preg_replace('~^(?:\./)+~', '', $pattern);
        if (!self::valid($pattern)) {
            return false;
        }

        return preg_match(self::regex($pattern), str_contains($pattern, '/') ? $path : basename($path)) === 1;
    }

    public static function regex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);
        $inGroup = false;
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $slashAfter = ($pattern[$i + 2] ?? '') === '/';
                    $regex .= $slashAfter ? '(?:.*/)?' : '.*';
                    $i += $slashAfter ? 2 : 1;

                    continue;
                }
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } elseif ($char === '[' && ($end = strpos($pattern, ']', $i + 2)) !== false) {
                $class = substr($pattern, $i + 1, $end - $i - 1);
                $negated = $class !== '' && ($class[0] === '!' || $class[0] === '^');
                $regex .= '[' . ($negated ? '^' : '') . str_replace(['\\', ']', '['], ['\\\\', '\\]', '\\['], $negated ? substr($class, 1) : $class) . ']';
                $i = $end;
            } elseif ($char === '{' && !$inGroup && strpos($pattern, '}', $i) !== false) {
                $regex .= '(?:';
                $inGroup = true;
            } elseif ($char === '}' && $inGroup) {
                $regex .= ')';
                $inGroup = false;
            } elseif ($char === ',' && $inGroup) {
                $regex .= '|';
            } elseif ($char === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($pattern[++$i], '~');
            } else {
                $regex .= preg_quote($char, '~');
            }
        }

        return '~^' . $regex . ($inGroup ? ')' : '') . '$~D';
    }
}
