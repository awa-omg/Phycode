<?php

declare(strict_types=1);

namespace Phycode\Review;

use Phycode\Support\Text;

final class ReplyParser
{
    private const OUTCOMES = ['refuted', 'corroborated', 'inconclusive'];

    public static function findings(string $reply, ReviewLens $lens, int $first): ?array
    {
        $items = self::items($reply, 'findings');
        if ($items === null) {
            return null;
        }
        $findings = [];
        $dropped = 0;
        foreach ($items['list'] as $item) {
            $finding = is_array($item) ? self::finding($item, $lens, 'F' . ($first + count($findings))) : null;
            if ($finding === null) {
                $dropped++;
                continue;
            }
            $findings[] = $finding;
        }

        return ['findings' => $findings, 'dropped' => $dropped, 'salvaged' => $items['salvaged']];
    }

    public static function verdicts(string $reply): ?array
    {
        $items = self::items($reply, 'results');
        if ($items === null) {
            return null;
        }
        $verdicts = [];
        foreach ($items['list'] as $item) {
            if (!is_array($item) || preg_match('/(\d+)/', self::text($item['id'] ?? null), $match) !== 1) {
                continue;
            }
            $outcome = strtolower(self::text($item['outcome'] ?? null));
            $evidence = self::text($item['evidence'] ?? null);
            if (!in_array($outcome, self::OUTCOMES, true) || ($outcome === 'refuted' && $evidence === '')) {
                $outcome = 'inconclusive';
            }
            $verdicts['F' . (int) $match[1]] = ['outcome' => $outcome, 'evidence' => $evidence];
        }

        return ['verdicts' => $verdicts, 'salvaged' => $items['salvaged']];
    }

    private static function finding(array $item, ReviewLens $lens, string $id): ?Finding
    {
        $severity = Severity::parse($item['severity'] ?? null);
        $file = preg_replace('#^\./#', '', self::text($item['file'] ?? null)) ?? '';
        $line = self::line($item['line'] ?? null);
        if ($line === null && preg_match('/^(.+):(\d+)$/', $file, $match) === 1) {
            $file = $match[1];
            $line = (int) $match[2];
        }
        $evidence = self::text($item['evidence'] ?? null);
        if ($severity === null || $file === '' || $evidence === '') {
            return null;
        }
        $title = self::text($item['title'] ?? null);
        $detail = self::text($item['detail'] ?? null);

        return new Finding(
            $id,
            $lens,
            $severity,
            $file,
            $line,
            $title !== '' ? $title : Text::oneLine($detail !== '' ? $detail : 'untitled finding', 120),
            $detail,
            $evidence,
        );
    }

    private static function items(string $reply, string $key): ?array
    {
        $document = self::document($reply, $key);
        if ($document !== null) {
            return ['list' => array_values($document[$key]), 'salvaged' => false];
        }

        $at = strpos($reply, '"' . $key . '"');
        $open = $at === false ? false : strpos($reply, '[', $at);
        if ($open === false) {
            return null;
        }
        $list = [];
        $position = $open + 1;
        while (($start = strpos($reply, '{', $position)) !== false) {
            $end = self::objectEnd($reply, $start);
            if ($end === null) {
                break;
            }
            $object = json_decode(substr($reply, $start, $end - $start + 1), true);
            if (is_array($object)) {
                $list[] = $object;
            }
            $position = $end + 1;
        }

        return ['list' => $list, 'salvaged' => true];
    }

    private static function document(string $reply, string $key): ?array
    {
        $position = 0;
        while (($start = strpos($reply, '{', $position)) !== false) {
            $end = self::objectEnd($reply, $start);
            $data = $end === null ? null : json_decode(substr($reply, $start, $end - $start + 1), true);
            if (is_array($data) && isset($data[$key]) && is_array($data[$key])) {
                return $data;
            }
            $position = $start + 1;
        }

        return null;
    }

    private static function objectEnd(string $text, int $start): ?int
    {
        $depth = 0;
        $inString = false;
        $length = strlen($text);
        for ($index = $start; $index < $length; $index++) {
            $char = $text[$index];
            if ($inString) {
                if ($char === '\\') {
                    $index++;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private static function text(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode("\n", array_filter($value, 'is_string'));
        }

        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
    }

    private static function line(mixed $value): ?int
    {
        $number = is_int($value) ? $value : (is_string($value) && ctype_digit(trim($value)) ? (int) trim($value) : null);

        return $number !== null && $number >= 1 ? $number : null;
    }
}
