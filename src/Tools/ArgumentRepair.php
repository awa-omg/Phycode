<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Support\Json;

final class ArgumentRepair
{
    private const PATHS = ['path', 'local', 'from', 'to', 'cwd', 'dir', 'file'];
    private const TRUE = ['true', 'yes', 'on', '1'];
    private const FALSE = ['false', 'no', 'off', '0'];

    public static function decode(string $raw): ?array
    {
        $text = trim($raw);
        if ($text === '' || $text === 'null' || $text === '{}') {
            return [];
        }
        $object = Json::object($text);
        if ($object !== null) {
            return $object;
        }
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $fenced) === 1) {
            $text = $fenced[1];
            $object = Json::object($text);
            if ($object !== null) {
                return $object;
            }
        }
        $decoded = json_decode($text, true);
        if (is_string($decoded)) {
            $inner = Json::object(trim($decoded));
            if ($inner !== null) {
                return $inner;
            }
        }
        $without = preg_replace('/,(\s*[}\]])/', '$1', $text);

        return $without !== null && $without !== $text ? Json::object($without) : null;
    }

    public static function apply(array $schema, array $args): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $repairs = [];

        foreach ($args as $name => $value) {
            $name = (string) $name;
            $definition = $properties[$name] ?? null;
            if (!is_array($definition)) {
                continue;
            }
            if ($value === null) {
                if (!in_array($name, $required, true)) {
                    unset($args[$name]);
                    $repairs[] = "{$name}: null dropped";
                }
                continue;
            }
            $fixed = self::fix($value, $definition);
            if (in_array($name, self::PATHS, true) && is_string($fixed)) {
                $fixed = self::path($fixed);
            }
            if ($fixed !== $value) {
                $repairs[] = "{$name}: " . self::show($value) . ' -> ' . self::show($fixed);
                $args[$name] = $fixed;
            }
        }

        return [$args, $repairs];
    }

    private static function fix(mixed $value, array $definition): mixed
    {
        $type = $definition['type'] ?? null;
        $enum = is_array($definition['enum'] ?? null) ? $definition['enum'] : null;

        if ($type === 'integer' && !is_int($value)) {
            if (is_float($value) && floor($value) === $value) {
                return (int) $value;
            }
            if (is_string($value) && preg_match('/^\s*(-?\d+)(?:\.0+)?\s*$/', $value, $number) === 1) {
                return (int) $number[1];
            }
        } elseif ($type === 'boolean' && !is_bool($value)) {
            $word = is_string($value) ? strtolower(trim($value)) : (is_int($value) ? (string) $value : null);
            if ($word !== null && in_array($word, self::TRUE, true)) {
                return true;
            }
            if ($word !== null && in_array($word, self::FALSE, true)) {
                return false;
            }
        } elseif ($type === 'string' && !is_string($value)) {
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            if (is_array($value) && count($value) === 1 && array_is_list($value) && is_string($value[0])) {
                return $value[0];
            }
        } elseif ($type === 'array' && !is_array($value)) {
            if (is_string($value)) {
                $text = trim($value);
                if (str_starts_with($text, '[')) {
                    $decoded = json_decode($text, true);
                    if (is_array($decoded) && array_is_list($decoded)) {
                        return $decoded;
                    }
                }

                return $text === '' ? [] : [$value];
            }
        } elseif ($type === 'object' && is_string($value)) {
            $decoded = Json::object(trim($value));
            if ($decoded !== null) {
                return $decoded;
            }
        }

        if ($enum !== null && is_string($value) && !in_array($value, $enum, true)) {
            $matches = array_values(array_filter($enum, static fn (mixed $member): bool => is_string($member) && strtolower($member) === strtolower(trim($value))));
            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        return $value;
    }

    private static function path(string $path): string
    {
        $path = trim($path);
        if (preg_match('~^\[([^\]]+)\]\((?:https?://)?([^)]+)\)$~', $path, $link) === 1 && $link[1] === $link[2]) {
            return $link[1];
        }
        if (preg_match('/^([\'"`])(.+)\1$/s', $path, $quoted) === 1) {
            return $quoted[2];
        }

        return $path;
    }

    private static function show(mixed $value): string
    {
        $text = is_string($value) ? '"' . $value . '"' : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strlen((string) $text) > 60 ? substr((string) $text, 0, 57) . '...' : (string) $text;
    }
}
