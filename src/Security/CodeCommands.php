<?php

declare(strict_types=1);

namespace Phycode\Security;

final class CodeCommands
{
    private const RUNNERS = [
        'python' => '(?:os\.(?:system|popen)|subprocess\.(?:run|call|check_call|check_output|Popen|getoutput|getstatusoutput)|commands\.getoutput)',
        'javascript' => '(?:(?:child_process|cp)\.)?(?:execSync|exec|execFileSync|execFile|spawnSync|spawn)|Bun\.spawnSync|Bun\.spawn|Bun\.\$|\$',
    ];
    private const DELETERS = [
        'python' => '(?:shutil\.rmtree|os\.removedirs|rmtree)',
        'javascript' => '(?:fs\.(?:promises\.)?(?:rmSync|rm|rmdirSync|rmdir)|(?:rmSync|rmdirSync|rimraf(?:\.sync)?))',
    ];
    private const HOMES = '#(?:os\.path\.expanduser\(\s*[\'"]~[\'"]\s*\)|Path\.home\(\)|os\.homedir\(\)|process\.env\.HOME|os\.environ\[\s*[\'"]HOME[\'"]\s*\]|os\.getenv\(\s*[\'"]HOME[\'"]\s*\))#';

    public static function of(string $language, string $code): array
    {
        if (!isset(self::RUNNERS[$language])) {
            return [];
        }
        $commands = [];
        foreach (self::calls($code, self::RUNNERS[$language]) as $arguments) {
            $first = self::firstArgument($arguments);
            $words = self::literals($first);
            if ($words === []) {
                continue;
            }
            if (str_starts_with(ltrim($first), '[')) {
                $commands[] = implode(' ', array_map(self::quote(...), $words));
                continue;
            }
            $second = ltrim(substr($arguments, strlen($first) + 1));
            $commands[] = str_starts_with($second, '[') ? implode(' ', array_map(self::quote(...), [$words[0], ...self::literals(self::firstArgument($second))])) : $words[0];
        }
        foreach (self::calls($code, self::DELETERS[$language]) as $arguments) {
            $first = self::firstArgument($arguments);
            $targets = self::literals($first);
            if (preg_match(self::HOMES, $first) === 1) {
                $targets[] = '~' . ($targets === [] ? '' : '/' . ltrim(implode('/', $targets), '/'));
            }
            foreach ($targets as $target) {
                $commands[] = 'rm -rf ' . self::quote($target);
            }
        }

        return array_values(array_unique($commands));
    }

    private static function calls(string $code, string $names): array
    {
        $found = [];
        if (preg_match_all('/(?<![\w$])(?:' . $names . ')\s*(\(|`)/', $code, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        foreach ($matches[1] as [$opener, $at]) {
            if ($opener === '`') {
                $end = strpos($code, '`', $at + 1);
                $found[] = $end === false ? '' : '`' . substr($code, $at + 1, $end - $at - 1) . '`';
                continue;
            }
            $found[] = self::balanced($code, $at + 1);
        }

        return $found;
    }

    private static function balanced(string $code, int $from): string
    {
        $depth = 1;
        $quote = null;
        $n = strlen($code);
        for ($i = $from; $i < $n; $i++) {
            $c = $code[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'" || $c === '`') {
                $quote = $c;
            } elseif ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif (($c === ')' || $c === ']' || $c === '}') && --$depth === 0) {
                return substr($code, $from, $i - $from);
            }
        }

        return substr($code, $from);
    }

    private static function firstArgument(string $arguments): string
    {
        $depth = 0;
        $quote = null;
        $n = strlen($arguments);
        for ($i = 0; $i < $n; $i++) {
            $c = $arguments[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'" || $c === '`') {
                $quote = $c;
            } elseif ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                return substr($arguments, 0, $i);
            }
        }

        return $arguments;
    }

    private static function literals(string $text): array
    {
        if (preg_match_all('/(?:\b[rRbBuUfF]{1,2})?("""|\'\'\'|"|\'|`)((?:\\\\.|(?!\1).)*?)\1/s', $text, $matches) === false) {
            return [];
        }

        return array_map(static fn (string $body): string => stripcslashes($body), $matches[2]);
    }

    private static function quote(string $word): string
    {
        return preg_match('#^[A-Za-z0-9_./~=:@%+,-]+$#', $word) === 1 ? $word : escapeshellarg($word);
    }
}
