<?php

declare(strict_types=1);

namespace Phycode\Support;

final class Json
{
    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($value, $flags);
    }

    public static function object(string $json): ?array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }

        return $value;
    }
}
