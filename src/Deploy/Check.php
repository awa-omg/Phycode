<?php

declare(strict_types=1);

namespace Phycode\Deploy;

use Phycode\Config\ConfigException;

final class Check
{
    public const WORD = '/^[A-Za-z0-9_][A-Za-z0-9_.:@%+=\/,-]{0,199}$/D';
    public const VARIABLE = '/^[A-Z][A-Z0-9_]{1,63}$/D';

    public static function word(mixed $value, string $label, ?string $pattern = null): string
    {
        if (!is_string($value) || preg_match($pattern ?? self::WORD, $value) !== 1) {
            throw new ConfigException("{$label} must be a short word of letters, digits and . _ : @ % + = / , - that does not start with a dash");
        }

        return $value;
    }

    public static function optionalWord(mixed $value, string $label, ?string $pattern = null): ?string
    {
        return $value === null ? null : self::word($value, $label, $pattern);
    }

    public static function choice(mixed $value, string $label, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ConfigException("{$label} must be " . implode(', ', array_slice($allowed, 0, -1)) . (count($allowed) > 1 ? ' or ' : '') . end($allowed));
        }

        return $value;
    }

    public static function flag(mixed $value, string $label): bool
    {
        if (!is_bool($value)) {
            throw new ConfigException("{$label} must be true or false");
        }

        return $value;
    }

    public static function relativePath(mixed $value, string $label): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 500 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || str_starts_with($value, '/') || str_starts_with($value, '-') || in_array('..', explode('/', $value), true)) {
            throw new ConfigException("{$label} must be a relative path inside the project, without '..'");
        }

        return $value;
    }

    public static function words(mixed $value, string $label, int $max = 32, ?string $pattern = null): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
            throw new ConfigException("{$label} must be a list of at most {$max} words");
        }
        foreach ($value as $at => $word) {
            self::word($word, "{$label}[{$at}]", $pattern);
        }

        return $value;
    }

    public static function argv(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 64) {
            throw new ConfigException("{$label} must be a list of 1 to 64 strings, the program first");
        }
        foreach ($value as $at => $part) {
            if (!is_string($part) || ($at === 0 && trim($part) === '') || strlen($part) > 4096 || str_contains($part, "\0")) {
                throw new ConfigException("{$label}[{$at}] must be a string of at most 4096 bytes");
            }
        }

        return $value;
    }

    public static function command(mixed $value, string $label, string $default): array
    {
        return $value === null ? [$default] : self::argv($value, $label);
    }
}
