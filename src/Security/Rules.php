<?php

declare(strict_types=1);

namespace Phycode\Security;

final class Rules
{
    public function __construct(private readonly array $rules = [])
    {
    }

    public function denying(string $tool, string $summary): ?string
    {
        return $this->firstOfForms('deny', $tool, $summary);
    }

    public function asking(string $tool, string $summary): ?string
    {
        return $this->firstOfForms('ask', $tool, $summary);
    }

    public function allowing(string $tool, string $summary): ?string
    {
        $parts = ShellCommand::of($tool, $summary);
        if ($parts === null) {
            return $this->first('allow', $tool, $summary);
        }
        [$prefix, $command] = $parts;
        $segments = ShellCommand::segments($command);
        if ($segments === null || $segments === []) {
            foreach ($this->rules['allow'] ?? [] as $rule) {
                if (self::coversEveryCall($rule, $tool)) {
                    return $rule;
                }
            }

            return null;
        }
        $used = null;
        foreach ($segments as $segment) {
            if (self::setsGitProgram($segment)) {
                return null;
            }
            $rule = $this->first('allow', $tool, $prefix . $segment);
            if ($rule === null) {
                return null;
            }
            $used ??= $rule;
        }

        return $used;
    }

    private static function setsGitProgram(string $segment): bool
    {
        foreach (ShellCommand::views($segment) as $view) {
            $words = preg_split('/\s+/', trim($view)) ?: [];
            if (basename($words[0] ?? '') !== 'git') {
                continue;
            }
            foreach (array_slice($words, 1) as $word) {
                if (!str_starts_with($word, '-')) {
                    break;
                }
                if (preg_match('/^(-c|--config-env|--exec-path|--git-dir|--work-tree)(=|$)/', $word) === 1 || str_starts_with($word, '-c')) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function matches(string $rule, string $tool, string $summary): bool
    {
        if ($rule === '*') {
            return true;
        }
        [$toolPattern, $glob] = str_contains($rule, ':') ? explode(':', $rule, 2) : [$rule, '*'];
        if (!fnmatch($toolPattern, $tool)) {
            return false;
        }
        $globs = str_ends_with($glob, ' *') && substr_count($glob, '*') === 1 ? [$glob, substr($glob, 0, -2)] : [$glob];
        $bare = self::withoutTool($tool, $summary);
        foreach ([$summary, $bare, self::withoutCount($bare)] as $subject) {
            foreach ($globs as $candidate) {
                if (fnmatch($candidate, $subject)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function firstOfForms(string $list, string $tool, string $summary): ?string
    {
        $forms = [$summary];
        $parts = ShellCommand::of($tool, $summary);
        if ($parts !== null) {
            [$prefix, $command] = $parts;
            foreach (ShellCommand::segments($command) ?? [$command] as $segment) {
                foreach (ShellCommand::views($segment) as $view) {
                    $forms[] = $prefix . $view;
                }
            }
        }
        foreach ($this->rules[$list] ?? [] as $rule) {
            foreach (array_unique($forms) as $form) {
                if (self::matches($rule, $tool, $form)) {
                    return $rule;
                }
            }
        }

        return null;
    }

    private static function coversEveryCall(string $rule, string $tool): bool
    {
        [$toolPattern, $glob] = str_contains($rule, ':') ? explode(':', $rule, 2) : [$rule, '*'];

        return $rule === '*' || ($glob === '*' && fnmatch($toolPattern, $tool));
    }

    private function first(string $list, string $tool, string $summary): ?string
    {
        foreach ($this->rules[$list] ?? [] as $rule) {
            if (self::matches($rule, $tool, $summary)) {
                return $rule;
            }
        }

        return null;
    }

    private static function withoutTool(string $tool, string $summary): string
    {
        foreach ([$tool . ': ', $tool . ' '] as $prefix) {
            if (str_starts_with($summary, $prefix)) {
                return substr($summary, strlen($prefix));
            }
        }

        return $summary;
    }

    private static function withoutCount(string $text): string
    {
        return preg_replace('/ \(\d+ lines?\)$/', '', $text) ?? $text;
    }
}
