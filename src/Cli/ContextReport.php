<?php

declare(strict_types=1);

namespace Phycode\Cli;

use Phycode\Llm\Image;

final class ContextReport
{
    public const CATEGORIES = ['system', 'tools', 'messages', 'tool results', 'summaries'];

    public static function tokens(string $text): int
    {
        return intdiv(mb_strlen($text), 4);
    }

    public static function breakdown(array $messages, int $system, int $tools): array
    {
        $chars = ['messages' => 0, 'tool results' => 0, 'summaries' => 0];
        foreach ($messages as $message) {
            $role = $message['role'] ?? null;
            $size = self::size($message);
            if ($role === 'assistant') {
                $chars['messages'] += $size;
            } elseif ($role === 'tool') {
                $chars['tool results'] += $size;
            } elseif ($role === 'user') {
                $content = (string) ($message['content'] ?? '');
                if (Compaction::isSummary($message)) {
                    $chars['summaries'] += $size;
                } elseif (self::resultName($content) !== null) {
                    $chars['tool results'] += $size;
                } else {
                    $chars['messages'] += $size;
                }
            }
        }

        $tokens = [
            'system' => $system,
            'tools' => $tools,
            'messages' => intdiv($chars['messages'], 4),
            'tool results' => intdiv($chars['tool results'], 4),
            'summaries' => intdiv($chars['summaries'], 4),
        ];
        $rows = [];
        foreach (self::CATEGORIES as $category) {
            $rows[] = ['category' => $category, 'tokens' => $tokens[$category]];
        }

        return $rows;
    }

    public static function summaryRows(array $state): array
    {
        $count = $state['messages'];
        $rows = [
            'used' => self::usedText($state),
            'autocompact' => self::autocompactText($state),
            'conversation' => $count . ($count === 1 ? ' message' : ' messages'),
        ];
        if (($state['cache'] ?? null) !== null && $state['cache']['prompt'] > 0) {
            $rows['prompt cache'] = self::cacheText($state['cache']['cached'], $state['cache']['prompt']);
        }
        foreach ($state['breakdown'] as $part) {
            $rows[$part['category']] = self::tokensText($part['tokens']) . ' tokens';
        }

        return $rows;
    }

    public static function cacheText(int $cached, int $prompt): string
    {
        $percent = intdiv($cached * 100, max(1, $prompt));

        return "{$percent}% of the prompt, " . self::tokensText($cached) . ' of ' . self::tokensText($prompt) . ' tokens' . ($cached === 0 ? ' (a miss: the start of the request changed, or the cache expired)' : '');
    }

    public static function tokensText(int $tokens): string
    {
        if ($tokens < 1000) {
            return (string) $tokens;
        }

        return rtrim(rtrim(sprintf('%.1f', $tokens / 1000), '0'), '.') . 'k';
    }

    private static function usedText(array $state): string
    {
        $used = self::tokensText($state['used']);
        $text = $state['limit'] === null || $state['percent'] === null
            ? "{$used} tokens, the window is unknown"
            : "{$used} of " . self::tokensText($state['limit']) . " tokens, {$state['percent']}%";

        return $state['estimated'] ? $text . ' (estimated)' : $text;
    }

    private static function autocompactText(array $state): string
    {
        if ($state['threshold'] === null) {
            return $state['limit'] === null ? 'none: the window is unknown' : 'none: the buffer leaves no room in the window';
        }
        $at = 'at ' . self::tokensText($state['threshold']);

        if ($state['autoCompact']) {
            return "{$at}, on";
        }

        return $state['autoCompactSwitch'] ? "{$at}, off until context.max is set" : "{$at}, off (context.auto_compact is false)";
    }

    private static function size(array $message): int
    {
        $total = mb_strlen(is_string($message['content'] ?? null) ? $message['content'] : '') + 4 * Image::ESTIMATED_TOKENS * count(Image::of($message));
        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            $total += mb_strlen((string) ($function['name'] ?? '')) + mb_strlen((string) ($function['arguments'] ?? ''));
        }

        return $total;
    }

    private static function resultName(string $content): ?string
    {
        return preg_match('/^<tool_response>\n([A-Za-z][A-Za-z0-9_-]*):\n/', $content, $match) === 1 ? $match[1] : null;
    }
}
