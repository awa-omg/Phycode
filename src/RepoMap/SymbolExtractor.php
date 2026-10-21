<?php

declare(strict_types=1);

namespace Phycode\RepoMap;

final class SymbolExtractor
{
    public const EXTENSIONS = [
        'php' => 'php', 'js' => 'js', 'jsx' => 'js', 'mjs' => 'js', 'cjs' => 'js', 'ts' => 'js', 'tsx' => 'js', 'mts' => 'js',
        'py' => 'python', 'go' => 'go', 'rs' => 'rust', 'java' => 'jvm', 'kt' => 'jvm', 'kts' => 'jvm', 'scala' => 'jvm', 'cs' => 'jvm',
        'rb' => 'ruby', 'c' => 'c', 'h' => 'c', 'cc' => 'c', 'cpp' => 'c', 'hpp' => 'c', 'cxx' => 'c',
    ];
    private const CONTAINERS = ['class', 'interface', 'trait', 'enum', 'struct', 'impl', 'module', 'object', 'record', 'union'];
    private const KEYWORDS = ['if', 'for', 'foreach', 'while', 'switch', 'catch', 'return', 'function', 'else', 'elseif', 'do', 'try', 'using', 'lock', 'synchronized', 'new', 'throw', 'when', 'match', 'with', 'await', 'yield', 'typeof', 'sizeof', 'delete', 'unless', 'until', 'defer', 'select', 'case', 'super', 'this'];
    private const MAX_SIGNATURE = 100;

    public static function languageOf(string $path): ?string
    {
        return self::EXTENSIONS[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    public static function extract(string $path, string $text): array
    {
        $language = self::languageOf($path);
        if ($language === null) {
            return [];
        }
        $symbols = [];
        $stack = [];
        $function = null;
        foreach (preg_split('/\R/', $text) ?: [] as $index => $line) {
            if (trim($line) === '' || strlen($line) > 400) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, " \t"));
            $trimmed = ltrim($line);
            if (self::comment($trimmed, $language)) {
                continue;
            }
            if (!preg_match('/^[{)\],]/', $trimmed)) {
                while ($stack !== [] && $indent <= $stack[array_key_last($stack)]['indent']) {
                    array_pop($stack);
                }
                if ($function !== null && $indent <= $function) {
                    $function = null;
                }
            }

            $found = self::match($language, $line, $indent);
            if ($found === null) {
                continue;
            }
            [$kind, $name, $signature, $receiver] = $found;
            if (in_array($kind, self::CONTAINERS, true) || $kind === 'type') {
                if ($function !== null && $language === 'python') {
                    continue;
                }
                $parent = $stack === [] ? null : $stack[array_key_last($stack)]['name'];
                if ($kind !== 'impl') {
                    $symbols[] = ['kind' => $kind, 'name' => $name, 'sig' => $signature, 'line' => $index + 1, 'parent' => $parent];
                }
                if (in_array($kind, self::CONTAINERS, true)) {
                    $stack[] = ['indent' => $indent, 'name' => $name, 'member' => null];
                }
                continue;
            }
            if ($function !== null && in_array($language, ['python', 'ruby'], true)) {
                continue;
            }
            $top = $stack === [] ? null : $stack[array_key_last($stack)];
            if ($top !== null && $language !== 'go') {
                if (($top['member'] !== null && $indent !== $top['member']) || ($top['member'] === null && $indent <= $top['indent'])) {
                    continue;
                }
                $stack[array_key_last($stack)]['member'] = $indent;
            } elseif ($top === null && $indent > 0 && $language !== 'rust' && $language !== 'go') {
                continue;
            }
            $parent = $receiver ?? ($top['name'] ?? null);
            $symbols[] = ['kind' => $parent !== null ? 'method' : 'function', 'name' => $name, 'sig' => $signature, 'line' => $index + 1, 'parent' => $parent];
            if (in_array($language, ['python', 'ruby'], true)) {
                $function = $indent;
            }
        }

        return $symbols;
    }

    private static function match(string $language, string $line, int $indent): ?array
    {
        return match ($language) {
            'php' => self::php($line),
            'js' => self::javascript($line, $indent),
            'python' => self::python($line),
            'go' => self::go($line),
            'rust' => self::rust($line),
            'jvm' => self::jvm($line),
            'ruby' => self::ruby($line),
            'c' => self::cFamily($line),
            default => null,
        };
    }

    private static function php(string $line): ?array
    {
        if (preg_match('/^\s*(?:(?:abstract|final|readonly)\s+)*(class|interface|trait|enum)\s+(\w+)/', $line, $m) === 1) {
            return [$m[1], $m[2], "{$m[1]} {$m[2]}", null];
        }
        if (preg_match('/^\s*(?:(?:public|protected|private|static|abstract|final)\s+)*function\s+&?(\w+)\s*\(([^)]*)\)?\s*(?::\s*([\w\\\\?|]+))?/', $line, $m) === 1) {
            return ['function', $m[1], self::signature($m[1], $m[2], $m[3] ?? ''), null];
        }

        return null;
    }

    private static function javascript(string $line, int $indent): ?array
    {
        if (preg_match('/^\s*(?:export\s+)?(?:default\s+)?(?:abstract\s+)?(class|interface|enum)\s+(\w+)/', $line, $m) === 1) {
            return [$m[1], $m[2], "{$m[1]} {$m[2]}", null];
        }
        if (preg_match('/^\s*(?:export\s+)?type\s+(\w+)\s*(?:<[^=]*>)?\s*=/', $line, $m) === 1) {
            return ['type', $m[1], "type {$m[1]}", null];
        }
        if (preg_match('/^\s*(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s*\*?\s*(\w+)\s*(?:<[^>]*>)?\s*\(([^)]*)\)?\s*(?::\s*([\w<>\[\]|?. ]+?))?\s*\{?\s*$/', $line, $m) === 1) {
            return ['function', $m[1], self::signature($m[1], $m[2], $m[3] ?? ''), null];
        }
        if (preg_match('/^\s*(?:export\s+)?(?:const|let|var)\s+(\w+)\s*(?::[^=]+)?=\s*(?:async\s+)?(?:\(([^)]*)\)|\w+)\s*(?::[^=]+)?=>/', $line, $m) === 1) {
            return ['function', $m[1], self::signature($m[1], $m[2] ?? '', ''), null];
        }
        if ($indent > 0 && preg_match('/^\s+(?:(?:public|private|protected|static|async|readonly|override|abstract|get|set)\s+)*(?:\*\s*)?(\w+)\s*(?:<[^>]*>)?\s*\(([^)]*)\)?\s*(?::\s*([\w<>\[\]|?. ]+?))?\s*\{\s*$/', $line, $m) === 1 && !in_array($m[1], self::KEYWORDS, true)) {
            return ['function', $m[1], self::signature($m[1], $m[2], $m[3] ?? ''), null];
        }

        return null;
    }

    private static function python(string $line): ?array
    {
        if (preg_match('/^\s*class\s+(\w+)/', $line, $m) === 1) {
            return ['class', $m[1], "class {$m[1]}", null];
        }
        if (preg_match('/^\s*(?:async\s+)?def\s+(\w+)\s*\(([^)]*)\)?\s*(?:->\s*([^:]+))?/', $line, $m) === 1) {
            return ['function', $m[1], self::signature($m[1], $m[2], trim($m[3] ?? '')), null];
        }

        return null;
    }

    private static function go(string $line): ?array
    {
        if (preg_match('/^func\s+(?:\(\s*\w*\s*\*?(\w+)(?:\[[^\]]*\])?\s*\)\s*)?(\w+)\s*(?:\[[^\]]*\])?\(([^)]*)\)?\s*([^{]*)/', $line, $m) === 1) {
            return ['function', $m[2], self::signature($m[2], $m[3], trim($m[4])), $m[1] !== '' ? $m[1] : null];
        }
        if (preg_match('/^type\s+(\w+)\s+(struct|interface)\b/', $line, $m) === 1) {
            return [$m[2], $m[1], "{$m[2]} {$m[1]}", null];
        }
        if (preg_match('/^type\s+(\w+)\s+\S/', $line, $m) === 1) {
            return ['type', $m[1], "type {$m[1]}", null];
        }

        return null;
    }

    private static function rust(string $line): ?array
    {
        if (preg_match('/^\s*(?:pub(?:\([^)]*\))?\s+)?(struct|enum|trait|union)\s+(\w+)/', $line, $m) === 1) {
            return [$m[1], $m[2], "{$m[1]} {$m[2]}", null];
        }
        if (preg_match('/^\s*impl(?:<[^>]*>)?\s+(?:[\w:<>, ]+?\s+for\s+)?(\w+)/', $line, $m) === 1) {
            return ['impl', $m[1], "impl {$m[1]}", null];
        }
        if (preg_match('/^\s*(?:pub(?:\([^)]*\))?\s+)?(?:const\s+)?(?:async\s+)?(?:unsafe\s+)?fn\s+(\w+)\s*(?:<[^>]*>)?\s*\(([^)]*)\)?\s*(?:->\s*([^{;]+))?/', $line, $m) === 1) {
            return ['function', $m[1], self::signature($m[1], $m[2], trim($m[3] ?? '')), null];
        }

        return null;
    }

    private static function jvm(string $line): ?array
    {
        if (preg_match('/^\s*(?:(?:public|private|protected|internal|abstract|final|static|sealed|open|data|partial|inline|value|case|enum)\s+)*(class|interface|object|record|trait|enum|struct)\s+(\w+)/', $line, $m) === 1) {
            return [$m[1], $m[2], "{$m[1]} {$m[2]}", null];
        }
        if (preg_match('/^\s+(?:(?:public|private|protected|internal|static|final|abstract|override|virtual|async|suspend|open|synchronized|inline|operator)\s+)*(?:fun\s+(?:<[^>]*>\s*)?|def\s+)?(?:[\w<>\[\],?.]+\s+)?(\w+)\s*(?:<[^>]*>)?\s*\(([^)]*)\)?\s*(?::\s*([\w<>\[\],?.]+))?[^;=]*\{?\s*$/', $line, $m) === 1
            && !in_array($m[1], self::KEYWORDS, true)
            && preg_match('/^\s+(?:public|private|protected|internal|static|final|abstract|override|virtual|async|suspend|open|fun|def|[\w<>\[\],?.]+\s+\w+\s*\()/', $line) === 1) {
            return ['function', $m[1], self::signature($m[1], $m[2], $m[3] ?? ''), null];
        }

        return null;
    }

    private static function ruby(string $line): ?array
    {
        if (preg_match('/^\s*(class|module)\s+([A-Z]\w*(?:::\w+)*)/', $line, $m) === 1) {
            return [$m[1], $m[2], "{$m[1]} {$m[2]}", null];
        }
        if (preg_match('/^\s*def\s+(?:self\.)?(\w+[?!=]?)\s*(?:\(([^)]*)\)?)?/', $line, $m) === 1) {
            return ['function', $m[1], self::signature($m[1], $m[2] ?? '', ''), null];
        }

        return null;
    }

    private static function cFamily(string $line): ?array
    {
        if (preg_match('/^\s*(?:typedef\s+)?(class|struct|enum)\s+(?:class\s+)?(\w+)\s*(?:final\s*)?(?::[^{;]*)?\{/', $line, $m) === 1) {
            return [$m[1] === 'enum' ? 'enum' : $m[1], $m[2], "{$m[1]} {$m[2]}", null];
        }

        return null;
    }

    private static function signature(string $name, string $parameters, string $return): string
    {
        $parameters = trim((string) preg_replace('/\s+/', ' ', $parameters));
        $text = $name . '(' . $parameters . ')' . ($return !== '' ? ': ' . trim($return) : '');

        return strlen($text) > self::MAX_SIGNATURE ? substr($text, 0, self::MAX_SIGNATURE - 3) . '...' : $text;
    }

    private static function comment(string $trimmed, string $language): bool
    {
        return str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || (in_array($language, ['python', 'ruby'], true) && str_starts_with($trimmed, '#')) || ($language === 'php' && str_starts_with($trimmed, '#'));
    }
}
