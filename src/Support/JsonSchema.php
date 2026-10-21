<?php

declare(strict_types=1);

namespace Phycode\Support;

final class JsonSchema
{
    private const ANNOTATIONS = ['$schema', '$id', '$comment', 'title', 'description', 'default', 'examples', 'format', 'deprecated', 'readOnly', 'writeOnly', '$defs', 'definitions'];
    private const KEYWORDS = ['type', 'enum', 'const', 'properties', 'required', 'additionalProperties', 'items', 'minItems', 'maxItems', 'uniqueItems', 'minLength', 'maxLength', 'pattern', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minProperties', 'maxProperties', 'not', 'allOf', 'anyOf', 'oneOf', '$ref'];
    private const TYPES = ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'];
    private const MAX_DEPTH = 64;
    private const MAX_ERRORS = 10;

    public static function check(array $schema): void
    {
        self::checkNode($schema, '#', $schema);
    }

    public static function errors(mixed $value, array $schema): array
    {
        $errors = [];
        self::validate($value, $schema, $schema, '$', 0, $errors);

        return array_slice($errors, 0, self::MAX_ERRORS);
    }

    public static function extract(string $text, mixed &$value): bool
    {
        $text = trim($text);
        $candidates = [$text];
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $fenced) === 1) {
            $candidates[] = $fenced[1];
        }
        foreach (['{' => '}', '[' => ']'] as $open => $close) {
            $from = strpos($text, $open);
            $to = strrpos($text, $close);
            if ($from !== false && $to !== false && $to > $from) {
                $candidates[] = substr($text, $from, $to - $from + 1);
            }
        }
        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;

                return true;
            }
        }

        return false;
    }

    private static function checkNode(mixed $node, string $where, array $root): void
    {
        if (is_bool($node)) {
            return;
        }
        if (!is_array($node) || ($node !== [] && array_is_list($node))) {
            throw new PhycodeException("schema {$where} must be an object");
        }
        foreach ($node as $keyword => $argument) {
            $keyword = (string) $keyword;
            if (in_array($keyword, self::ANNOTATIONS, true) || str_starts_with($keyword, 'x-')) {
                continue;
            }
            if (!in_array($keyword, self::KEYWORDS, true)) {
                throw new PhycodeException("schema {$where}: the keyword '{$keyword}' is not supported (supported: " . implode(', ', self::KEYWORDS) . ')');
            }
            match ($keyword) {
                'type' => self::checkType($argument, $where),
                'properties' => self::checkEach($argument, $where . '/properties', $root),
                'items', 'not', 'additionalProperties' => self::checkNode($argument, "{$where}/{$keyword}", $root),
                'allOf', 'anyOf', 'oneOf' => self::checkList($argument, "{$where}/{$keyword}", $root),
                'required' => self::checkRequired($argument, $where),
                'pattern' => self::checkPattern($argument, $where),
                '$ref' => self::checkRef($argument, $where, $root),
                'enum' => self::must(is_array($argument) && array_is_list($argument) && $argument !== [], "schema {$where}: enum must be a non-empty list"),
                'minItems', 'maxItems', 'minLength', 'maxLength', 'minProperties', 'maxProperties' => self::must(is_int($argument) && $argument >= 0, "schema {$where}: {$keyword} must be a whole number of at least 0"),
                'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum' => self::must(is_int($argument) || is_float($argument), "schema {$where}: {$keyword} must be a number"),
                'uniqueItems' => self::must(is_bool($argument), "schema {$where}: uniqueItems must be true or false"),
                default => null,
            };
        }
        foreach (['$defs', 'definitions'] as $key) {
            if (isset($node[$key])) {
                self::checkEach($node[$key], "{$where}/{$key}", $root);
            }
        }
    }

    private static function must(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new PhycodeException($message);
        }
    }

    private static function checkType(mixed $type, string $where): void
    {
        $types = is_array($type) ? $type : [$type];
        self::must($types !== [] && array_is_list($types), "schema {$where}: type must be a type name or a list of them");
        foreach ($types as $name) {
            self::must(is_string($name) && in_array($name, self::TYPES, true), "schema {$where}: the type '" . (is_string($name) ? $name : json_encode($name)) . "' is not one of " . implode(', ', self::TYPES));
        }
    }

    private static function checkEach(mixed $nodes, string $where, array $root): void
    {
        self::must(is_array($nodes) && ($nodes === [] || !array_is_list($nodes)), "schema {$where} must be an object of schemas");
        foreach ($nodes as $name => $node) {
            self::checkNode($node, "{$where}/{$name}", $root);
        }
    }

    private static function checkList(mixed $nodes, string $where, array $root): void
    {
        self::must(is_array($nodes) && array_is_list($nodes) && $nodes !== [], "schema {$where} must be a non-empty list of schemas");
        foreach ($nodes as $index => $node) {
            self::checkNode($node, "{$where}/{$index}", $root);
        }
    }

    private static function checkRequired(mixed $required, string $where): void
    {
        self::must(is_array($required) && array_is_list($required), "schema {$where}: required must be a list of property names");
        foreach ($required as $name) {
            self::must(is_string($name), "schema {$where}: required must be a list of property names");
        }
    }

    private static function checkPattern(mixed $pattern, string $where): void
    {
        self::must(is_string($pattern) && @preg_match(self::regex($pattern), '') !== false, "schema {$where}: pattern is not a valid regular expression");
    }

    private static function checkRef(mixed $ref, string $where, array $root): void
    {
        self::must(is_string($ref) && self::resolve($ref, $root) !== null, "schema {$where}: \$ref " . (is_string($ref) ? "'{$ref}'" : '') . ' does not name a place of this schema (only #, #/$defs/NAME and similar pointers do)');
    }

    private static function resolve(string $ref, array $root): array|bool|null
    {
        if ($ref === '#') {
            return $root;
        }
        if (!str_starts_with($ref, '#/')) {
            return null;
        }
        $node = $root;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($part));
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return is_array($node) || is_bool($node) ? $node : null;
    }

    private static function regex(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~u';
    }

    private static function validate(mixed $value, array|bool $schema, array $root, string $at, int $depth, array &$errors): void
    {
        if (count($errors) >= self::MAX_ERRORS) {
            return;
        }
        if (is_bool($schema)) {
            if (!$schema) {
                $errors[] = "{$at}: no value is allowed here";
            }

            return;
        }
        if ($depth > self::MAX_DEPTH) {
            $errors[] = "{$at}: the schema nests too deeply";

            return;
        }
        if (isset($schema['$ref'])) {
            $target = self::resolve((string) $schema['$ref'], $root);
            if ($target !== null) {
                self::validate($value, $target, $root, $at, $depth + 1, $errors);
            }
        }
        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $fits = array_filter($types, static fn (mixed $type): bool => self::isType($value, (string) $type));
            if ($fits === []) {
                $errors[] = "{$at}: expected " . implode(' or ', array_map('strval', $types)) . ', got ' . self::describe($value);

                return;
            }
        }
        if (isset($schema['enum']) && !self::in($value, $schema['enum'])) {
            $errors[] = "{$at}: must be one of " . json_encode($schema['enum'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ', got ' . self::show($value);
        }
        if (array_key_exists('const', $schema) && !self::same($value, $schema['const'])) {
            $errors[] = "{$at}: must be " . self::show($schema['const']) . ', got ' . self::show($value);
        }
        if (is_string($value)) {
            self::string($value, $schema, $at, $errors);
        } elseif (is_int($value) || is_float($value)) {
            self::number($value, $schema, $at, $errors);
        } elseif (is_array($value)) {
            if ($value === [] || array_is_list($value)) {
                self::arrayOf($value, $schema, $root, $at, $depth, $errors);
            }
            if ($value === [] || !array_is_list($value)) {
                self::objectOf($value, $schema, $root, $at, $depth, $errors);
            }
        }
        self::combined($value, $schema, $root, $at, $depth, $errors);
    }

    private static function string(string $value, array $schema, string $at, array &$errors): void
    {
        $length = mb_strlen($value);
        if (isset($schema['minLength']) && $length < $schema['minLength']) {
            $errors[] = "{$at}: must have at least {$schema['minLength']} characters, has {$length}";
        }
        if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
            $errors[] = "{$at}: must have at most {$schema['maxLength']} characters, has {$length}";
        }
        if (isset($schema['pattern']) && preg_match(self::regex((string) $schema['pattern']), $value) !== 1) {
            $errors[] = "{$at}: must match the pattern {$schema['pattern']}";
        }
    }

    private static function number(int|float $value, array $schema, string $at, array &$errors): void
    {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = "{$at}: must be at least {$schema['minimum']}, got {$value}";
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = "{$at}: must be at most {$schema['maximum']}, got {$value}";
        }
        if (isset($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
            $errors[] = "{$at}: must be more than {$schema['exclusiveMinimum']}, got {$value}";
        }
        if (isset($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
            $errors[] = "{$at}: must be less than {$schema['exclusiveMaximum']}, got {$value}";
        }
    }

    private static function arrayOf(array $value, array $schema, array $root, string $at, int $depth, array &$errors): void
    {
        $count = count($value);
        if (isset($schema['minItems']) && $count < $schema['minItems']) {
            $errors[] = "{$at}: must have at least {$schema['minItems']} items, has {$count}";
        }
        if (isset($schema['maxItems']) && $count > $schema['maxItems']) {
            $errors[] = "{$at}: must have at most {$schema['maxItems']} items, has {$count}";
        }
        if (($schema['uniqueItems'] ?? false) === true) {
            $seen = [];
            foreach ($value as $index => $item) {
                $key = json_encode($item);
                if (isset($seen[$key])) {
                    $errors[] = "{$at}[{$index}]: duplicates the item at [{$seen[$key]}]";
                    break;
                }
                $seen[$key] = $index;
            }
        }
        if (isset($schema['items'])) {
            foreach ($value as $index => $item) {
                self::validate($item, $schema['items'], $root, "{$at}[{$index}]", $depth + 1, $errors);
            }
        }
    }

    private static function objectOf(array $value, array $schema, array $root, string $at, int $depth, array &$errors): void
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($schema['required'] ?? [] as $name) {
            if (!array_key_exists($name, $value)) {
                $errors[] = "{$at}: the property '{$name}' is required";
            }
        }
        if (isset($schema['minProperties']) && count($value) < $schema['minProperties']) {
            $errors[] = "{$at}: must have at least {$schema['minProperties']} properties, has " . count($value);
        }
        if (isset($schema['maxProperties']) && count($value) > $schema['maxProperties']) {
            $errors[] = "{$at}: must have at most {$schema['maxProperties']} properties, has " . count($value);
        }
        foreach ($value as $name => $item) {
            $name = (string) $name;
            if (array_key_exists($name, $properties)) {
                self::validate($item, $properties[$name], $root, "{$at}.{$name}", $depth + 1, $errors);
            } elseif (array_key_exists('additionalProperties', $schema)) {
                self::validate($item, $schema['additionalProperties'], $root, "{$at}.{$name}", $depth + 1, $errors);
            }
        }
    }

    private static function combined(mixed $value, array $schema, array $root, string $at, int $depth, array &$errors): void
    {
        foreach ($schema['allOf'] ?? [] as $part) {
            self::validate($value, $part, $root, $at, $depth + 1, $errors);
        }
        if (isset($schema['anyOf'])) {
            $fits = array_filter($schema['anyOf'], static fn (mixed $part): bool => self::fits($value, $part, $root, $depth));
            if ($fits === []) {
                $errors[] = "{$at}: matches none of the alternatives (anyOf)";
            }
        }
        if (isset($schema['oneOf'])) {
            $count = count(array_filter($schema['oneOf'], static fn (mixed $part): bool => self::fits($value, $part, $root, $depth)));
            if ($count !== 1) {
                $errors[] = "{$at}: must match exactly one alternative (oneOf), matches {$count}";
            }
        }
        if (isset($schema['not']) && self::fits($value, $schema['not'], $root, $depth)) {
            $errors[] = "{$at}: must not match the schema in 'not'";
        }
    }

    private static function fits(mixed $value, array|bool $schema, array $root, int $depth): bool
    {
        $errors = [];
        self::validate($value, $schema, $root, '$', $depth + 1, $errors);

        return $errors === [];
    }

    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value && is_finite($value)),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            'array' => is_array($value) && ($value === [] || array_is_list($value)),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            default => false,
        };
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && ($value === [] || array_is_list($value)) => 'array',
            default => 'object',
        };
    }

    private static function show(mixed $value): string
    {
        $text = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strlen($text) > 60 ? substr($text, 0, 57) . '...' : $text;
    }

    private static function in(mixed $value, mixed $list): bool
    {
        foreach (is_array($list) ? $list : [] as $member) {
            if (self::same($value, $member)) {
                return true;
            }
        }

        return false;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        return $a === $b;
    }
}
