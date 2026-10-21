<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Support\PhycodeException;

final class Args
{
    public static function string(array $args, string $key, bool $required = true): string
    {
        $value = $args[$key] ?? null;
        if ($value === null) {
            if ($required) {
                throw new PhycodeException("missing required argument '{$key}'");
            }

            return '';
        }
        if (!is_string($value)) {
            throw new PhycodeException("argument '{$key}' must be a string");
        }

        return $value;
    }

    public static function int(array $args, string $key, int $min, int $max, int $default): int
    {
        $value = $args[$key] ?? $default;
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            throw new PhycodeException("argument '{$key}' must be an integer");
        }

        return max($min, min($max, $value));
    }

    public static function stringList(array $args, string $key): array
    {
        $value = $args[$key] ?? null;
        if ($value === null) {
            throw new PhycodeException("missing required argument '{$key}'");
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new PhycodeException("argument '{$key}' must be a list of strings");
        }
        if (count($value) > 200) {
            throw new PhycodeException("argument '{$key}' has more than 200 items");
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new PhycodeException("argument '{$key}' must contain only strings");
            }
            if (strlen($item) > 4096) {
                throw new PhycodeException("an item of argument '{$key}' is longer than 4096 bytes");
            }
            if (str_contains($item, "\0")) {
                throw new PhycodeException("argument '{$key}' contains a NUL byte");
            }
            $items[] = $item;
        }

        return $items;
    }

    public static function bool(array $args, string $key, bool $default): bool
    {
        $value = $args[$key] ?? $default;
        if (!is_bool($value)) {
            throw new PhycodeException("argument '{$key}' must be true or false");
        }

        return $value;
    }
}
