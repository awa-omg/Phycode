<?php

declare(strict_types=1);

namespace Phycode\Diff;

final class UnifiedDiff
{
    private const HUNK = '/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/';
    private const ESCAPES = ['a' => "\x07", 'b' => "\x08", 't' => "\t", 'n' => "\n", 'v' => "\v", 'f' => "\f", 'r' => "\r", '"' => '"', '\\' => '\\'];

    public static function parse(string $text): array
    {
        $sections = [];
        $oldLeft = 0;
        $newLeft = 0;
        $oldNo = 0;
        $newNo = 0;
        foreach (explode("\n", $text) as $line) {
            if ($oldLeft > 0 || $newLeft > 0) {
                $marker = $line === '' ? ' ' : $line[0];
                if ($marker === '\\') {
                    continue;
                }
                if ($marker === ' ' || $marker === '-' || $marker === '+') {
                    $section = count($sections) - 1;
                    $hunk = array_key_last($sections[$section]['hunks']);
                    if ($marker !== '+') {
                        $oldNo++;
                        $oldLeft--;
                    }
                    if ($marker !== '-') {
                        $newNo++;
                        $newLeft--;
                    }
                    $sections[$section]['hunks'][$hunk]['lines'][] = [
                        'kind' => match ($marker) {
                            ' ' => FileDiff::CONTEXT,
                            '-' => FileDiff::REMOVED,
                            default => FileDiff::ADDED,
                        },
                        'text' => $line === '' ? '' : self::text(substr($line, 1)),
                        'oldNo' => $marker === '+' ? null : $oldNo,
                        'newNo' => $marker === '-' ? null : $newNo,
                    ];

                    continue;
                }
                $oldLeft = 0;
                $newLeft = 0;
            }

            if (str_starts_with($line, 'diff --git ')) {
                $sections[] = self::section(self::gitHeaderPath(substr($line, 11)));
            } elseif (str_starts_with($line, 'diff --cc ') || str_starts_with($line, 'diff --combined ')) {
                $sections[] = self::section(self::fileName(substr($line, strpos($line, ' ', 5) + 1), ''));
            } elseif (preg_match(self::HUNK, $line, $match) === 1) {
                if ($sections === []) {
                    $sections[] = self::section(null);
                }
                $oldStart = (int) $match[1];
                $oldCount = ($match[2] ?? '') === '' ? 1 : (int) $match[2];
                $newStart = (int) $match[3];
                $newCount = ($match[4] ?? '') === '' ? 1 : (int) $match[4];
                $oldLeft = $oldCount;
                $newLeft = $newCount;
                $oldNo = $oldStart - 1;
                $newNo = $newStart - 1;
                $section = count($sections) - 1;
                $sections[$section]['hunks'][] = [
                    'oldStart' => $oldStart,
                    'oldLines' => $oldCount,
                    'newStart' => $newStart,
                    'newLines' => $newCount,
                    'lines' => [],
                ];
            } elseif (str_starts_with($line, '--- ') || str_starts_with($line, '+++ ')) {
                if ($sections === []) {
                    $sections[] = self::section(null);
                }
                $section = count($sections) - 1;
                if (str_starts_with($line, '--- ')) {
                    $sections[$section]['old'] = self::fileName(substr($line, 4), 'a/');
                } else {
                    $sections[$section]['new'] = self::fileName(substr($line, 4), 'b/');
                }
            } elseif (str_starts_with($line, 'Binary files ') || $line === 'GIT binary patch') {
                if ($sections === []) {
                    $sections[] = self::section(null);
                }
                $sections[count($sections) - 1]['binary'] = true;
            }
        }

        $files = [];
        foreach ($sections as $section) {
            $path = $section['new'] ?? $section['old'] ?? $section['header'] ?? '';
            $files[] = FileDiff::of($path, $section['hunks'], $section['binary']);
        }

        return $files;
    }

    private static function section(?string $header): array
    {
        return ['header' => $header, 'old' => null, 'new' => null, 'binary' => false, 'hunks' => []];
    }

    private static function text(string $line): string
    {
        return str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
    }

    private static function gitHeaderPath(string $rest): ?string
    {
        if (str_starts_with($rest, '"')) {
            $end = self::closingQuote($rest);
            if ($end === null || !str_starts_with(substr($rest, $end + 1), ' ')) {
                return null;
            }
            $after = substr($rest, $end + 2);
            $name = str_starts_with($after, '"') ? self::unquote($after) : $after;

            return str_starts_with($name, 'b/') ? substr($name, 2) : null;
        }

        $length = strlen($rest);
        if ($length >= 5 && ($length - 5) % 2 === 0) {
            $half = intdiv($length - 5, 2);
            if (substr($rest, 0, 2) === 'a/' && substr($rest, 2 + $half, 3) === ' b/' && substr($rest, 2, $half) === substr($rest, 5 + $half)) {
                return substr($rest, 5 + $half);
            }
        }
        $split = strrpos($rest, ' b/');

        return $split === false ? null : substr($rest, $split + 3);
    }

    private static function fileName(string $field, string $prefix): ?string
    {
        if ($field === '/dev/null') {
            return null;
        }
        if (str_starts_with($field, '"')) {
            $end = self::closingQuote($field);
            $name = self::unquote(substr($field, 0, ($end ?? strlen($field) - 1) + 1));
        } else {
            $name = explode("\t", $field, 2)[0];
        }

        return $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
    }

    private static function closingQuote(string $text): ?int
    {
        for ($index = 1, $length = strlen($text); $index < $length; $index++) {
            if ($text[$index] === '\\') {
                $index++;
            } elseif ($text[$index] === '"') {
                return $index;
            }
        }

        return null;
    }

    private static function unquote(string $quoted): string
    {
        $inner = substr($quoted, 1, -1);

        return (string) preg_replace_callback(
            '/\\\\([0-7]{3}|.)/s',
            static fn (array $match): string => strlen($match[1]) === 3 ? chr(octdec($match[1]) % 256) : (self::ESCAPES[$match[1]] ?? $match[1]),
            $inner,
        );
    }
}
