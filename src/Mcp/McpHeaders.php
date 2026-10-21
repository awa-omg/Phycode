<?php

declare(strict_types=1);

namespace Phycode\Mcp;

final class McpHeaders
{
    private const SENTINEL = '/^=\?base64\?.*\?=$/s';
    private const TOKEN = "/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/D";
    private const SAFE_INTEGER = 9007199254740991;

    public static function annotations(array $schema, ?string &$why = null): ?array
    {
        $found = [];
        $names = [];
        $why = null;
        if (!self::scan($schema, true, [], $found, $names, $why)) {
            return null;
        }

        return $found;
    }

    private static function scan(array $node, bool $reachable, array $path, array &$found, array &$names, ?string &$why): bool
    {
        foreach ($node as $key => $value) {
            $key = (string) $key;
            if ($key === 'x-mcp-header') {
                if (!$reachable) {
                    $why = 'x-mcp-header is not on a property reached through properties alone';

                    return false;
                }
                if (!is_string($value) || $value === '' || preg_match(self::TOKEN, $value) !== 1) {
                    $why = 'x-mcp-header must be a name made of HTTP token characters';

                    return false;
                }
                if (isset($names[strtolower($value)])) {
                    $why = "x-mcp-header '{$value}' is used twice";

                    return false;
                }
                if (!in_array($node['type'] ?? null, ['integer', 'string', 'boolean'], true)) {
                    $why = "x-mcp-header '{$value}' is on a parameter that is not an integer, a string or a boolean";

                    return false;
                }
                $names[strtolower($value)] = true;
                $found[$value] = $path;
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            if ($key === 'properties' && $reachable && ($value === [] || !array_is_list($value))) {
                foreach ($value as $name => $child) {
                    if (is_array($child) && !self::scan($child, true, [...$path, (string) $name], $found, $names, $why)) {
                        return false;
                    }
                }
                continue;
            }
            if (!self::scan($value, false, $path, $found, $names, $why)) {
                return false;
            }
        }

        return true;
    }

    public static function forCall(array $annotations, array $arguments): array
    {
        $headers = [];
        foreach ($annotations as $name => $path) {
            $value = $arguments;
            foreach ($path as $step) {
                if (!is_array($value) || !array_key_exists($step, $value)) {
                    continue 2;
                }
                $value = $value[$step];
            }
            $text = match (true) {
                is_string($value) => $value,
                is_bool($value) => $value ? 'true' : 'false',
                is_int($value) && abs($value) <= self::SAFE_INTEGER => (string) $value,
                default => null,
            };
            if ($text !== null) {
                $headers["Mcp-Param-{$name}"] = self::encode($text);
            }
        }

        return $headers;
    }

    public static function encode(string $value): string
    {
        $plain = preg_match('/^[\x21-\x7E](?:[\x20\x21-\x7E\t]*[\x21-\x7E])?$/D', $value) === 1 && preg_match(self::SENTINEL, $value) !== 1;

        return $plain ? $value : '=?base64?' . base64_encode($value) . '?=';
    }
}
