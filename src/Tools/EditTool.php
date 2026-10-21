<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Diff\TextDiff;
use Phycode\Security\SecretFiles;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class EditTool extends BaseTool
{
    public function name(): string
    {
        return 'edit';
    }

    public function description(): string
    {
        return 'Replace old_string with new_string in a file. old_string must match exactly once (or set replace_all). Read the file first. Needs user approval.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Write;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'File path relative to the project root'],
                'old_string' => ['type' => 'string', 'description' => 'Exact text to replace, including indentation'],
                'new_string' => ['type' => 'string', 'description' => 'Replacement text'],
                'replace_all' => ['type' => 'boolean', 'description' => 'Replace every occurrence instead of exactly one'],
            ],
            'required' => ['path', 'old_string', 'new_string'],
        ];
    }

    public function summarize(array $args): string
    {
        $path = is_string($args['path'] ?? null) ? $args['path'] : '?';
        $old = is_string($args['old_string'] ?? null) ? $args['old_string'] : '';

        return 'edit ' . $path . ': ' . Text::oneLine($old, 60);
    }

    public function forcesApproval(array $args): bool
    {
        return is_string($args['path'] ?? null) && SecretFiles::matches($args['path']);
    }

    public function validate(array $args, ToolContext $context): void
    {
        $context->workspace->resolveWritable(Args::string($args, 'path'));
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $path = Args::string($args, 'path');
        $old = Args::string($args, 'old_string');
        $new = Args::string($args, 'new_string');
        $replaceAll = Args::bool($args, 'replace_all', false);

        if ($old === '') {
            return ToolResult::fail('old_string is empty; use write to create or replace a whole file');
        }
        if ($old === $new) {
            return ToolResult::fail('old_string and new_string are identical; nothing to change');
        }

        $file = $context->workspace->resolveWritable($path);
        if (!is_file($file)) {
            return ToolResult::fail("not a file: {$path}");
        }
        $text = file_get_contents($file);
        if ($text === false) {
            return ToolResult::fail("cannot read {$path}");
        }
        if (Text::isBinary(substr($text, 0, 8192))) {
            return ToolResult::fail("binary file: {$path}");
        }

        $change = self::replace($text, $old, $new, $replaceAll);
        if ($change['count'] === 0) {
            return ToolResult::fail("old_string not found in {$path}. " . self::hint($text, $old));
        }
        if ($change['count'] > 1 && !$replaceAll) {
            return ToolResult::fail("old_string matches {$change['count']} places in {$path}; add surrounding lines to make it unique, or set replace_all to true");
        }

        $updated = $change['updated'];
        $line = $change['line'];
        $message = 'edited ' . $path . ': replaced ' . ($replaceAll ? $change['count'] : 1) . " occurrence(s), first at line {$line}\n"
            . self::excerpt($updated, $line, $change['span']);

        try {
            $context->checkpoints?->record($context->workspace->relative($file), $text);
        } catch (PhycodeException $error) {
            return ToolResult::fail("cannot keep the content of {$path} for undo; nothing was written: " . $error->getMessage());
        }
        $result = SyntaxCheck::commit($file, $updated, $text, (int) fileperms($file), $message, $context);
        if ($result->ok) {
            $context->changes->add($context->workspace->relative($file));

            return $result->withDiff(TextDiff::change($context->workspace->relative($file), $text, $updated));
        }

        return $result;
    }

    public function preview(array $args, ToolContext $context): ?array
    {
        try {
            $file = $context->workspace->resolveWritable(Args::string($args, 'path'));
            $old = Args::string($args, 'old_string');
            $new = Args::string($args, 'new_string');
            $replaceAll = Args::bool($args, 'replace_all', false);
        } catch (PhycodeException) {
            return null;
        }
        $text = is_file($file) ? @file_get_contents($file) : false;
        if (!is_string($text) || $old === '' || $old === $new || Text::isBinary(substr($text, 0, 8192))) {
            return null;
        }
        $change = self::replace($text, $old, $new, $replaceAll);
        if ($change['count'] === 0 || ($change['count'] > 1 && !$replaceAll)) {
            return null;
        }

        return TextDiff::change($context->workspace->relative($file), $text, $change['updated']);
    }

    private static function replace(string $text, string $old, string $new, bool $replaceAll): array
    {
        $needle = $old;
        $replacement = $new;
        if (str_contains($text, "\r\n")) {
            $needle = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $needle));
            $replacement = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $replacement));
        }

        $count = substr_count($text, $needle);
        if ($count === 0) {
            return ['count' => 0, 'line' => 0, 'span' => 0, 'updated' => $text];
        }

        $position = (int) strpos($text, $needle);

        return [
            'count' => $count,
            'line' => substr_count(substr($text, 0, $position), "\n") + 1,
            'span' => substr_count($replacement, "\n") + 1,
            'updated' => $replaceAll
                ? str_replace($needle, $replacement, $text)
                : substr_replace($text, $replacement, $position, strlen($needle)),
        ];
    }

    private static function excerpt(string $text, int $firstLine, int $span): string
    {
        $all = explode("\n", $text);
        $from = max(1, $firstLine - 2);
        $to = min(count($all), $firstLine + $span + 1);
        $rows = [];
        for ($number = $from; $number <= $to; $number++) {
            $rows[] = sprintf('%6d  %s', $number, rtrim($all[$number - 1], "\r"));
        }

        return implode("\n", $rows);
    }

    private static function hint(string $text, string $old): string
    {
        $wanted = array_values(array_filter(array_map(static fn (string $line): string => self::squash($line), preg_split('/\R/', $old) ?: []), static fn (string $line): bool => $line !== ''));
        if ($wanted === []) {
            return 'Read the file again and copy the exact text.';
        }
        $lines = preg_split('/\R/', $text) ?: [];
        $squashed = array_map(self::squash(...), $lines);

        $block = self::block($squashed, $wanted);
        if ($block !== null) {
            [$from, $to] = $block;
            $shown = [];
            foreach (array_slice($lines, $from, min($to - $from + 1, 12), true) as $number => $line) {
                $shown[] = sprintf('%6d  %s', $number + 1, rtrim($line, "\r"));
            }

            return 'The same text is at lines ' . ($from + 1) . '-' . ($to + 1) . ' with other spacing (indentation, tabs or trailing spaces). Copy it exactly:' . "\n" . implode("\n", $shown);
        }

        $first = trim((string) (preg_split('/\R/', ltrim($old)) ?: [''])[0]);
        foreach ($lines as $number => $line) {
            if ($first !== '' && str_contains($line, $first)) {
                return 'The first line of old_string appears at line ' . ($number + 1) . ': '
                    . Text::oneLine($line, 120) . '. Copy the text exactly, including indentation.';
            }
        }

        $best = null;
        $bestPercent = 0.0;
        foreach (array_slice($squashed, 0, 20000, true) as $number => $line) {
            if ($line === '') {
                continue;
            }
            similar_text(substr($wanted[0], 0, 200), substr($line, 0, 200), $percent);
            if ($percent > $bestPercent) {
                [$best, $bestPercent] = [$number, $percent];
            }
        }
        if ($best !== null && $bestPercent >= 70.0) {
            return 'The nearest line is line ' . ($best + 1) . ': ' . Text::oneLine(rtrim($lines[$best], "\r"), 120) . '. Read that part of the file and copy the exact text.';
        }

        return 'Read the file again with read and copy the exact text, including indentation.';
    }

    private static function squash(string $line): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $line));
    }

    private static function block(array $squashed, array $wanted): ?array
    {
        $found = [];
        $count = count($squashed);
        for ($start = 0; $start < $count; $start++) {
            if ($squashed[$start] !== $wanted[0]) {
                continue;
            }
            $at = $start;
            foreach ($wanted as $line) {
                while ($at < $count && $squashed[$at] === '' && $line !== '') {
                    $at++;
                }
                if ($at >= $count || $squashed[$at] !== $line) {
                    continue 2;
                }
                $at++;
            }
            $found[] = [$start, $at - 1];
            if (count($found) > 1) {
                return null;
            }
        }

        return $found[0] ?? null;
    }
}
