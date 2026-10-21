<?php

declare(strict_types=1);

namespace Phycode\Session;

use Phycode\Llm\Image;

final class ContextBudget
{
    public static function estimate(array $messages): int
    {
        return intdiv(array_sum(array_map(self::size(...), $messages)), 4);
    }

    public static function turnStarts(array $messages): array
    {
        $starts = [];
        foreach ($messages as $index => $message) {
            if (($message['role'] ?? null) !== 'user' || self::isTextResult($message)) {
                continue;
            }
            if ($index > 0 && self::inTurn($messages[$index - 1])) {
                continue;
            }
            $starts[] = $index;
        }

        return $starts;
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

    private static function isTextResult(array $message): bool
    {
        return ($message['role'] ?? null) === 'user' && str_starts_with((string) ($message['content'] ?? ''), '<tool_response>');
    }

    private static function inTurn(array $message): bool
    {
        return match ($message['role'] ?? null) {
            'tool' => true,
            'assistant' => ($message['tool_calls'] ?? []) !== [] || str_contains((string) ($message['content'] ?? ''), '<tool_call>'),
            'user' => self::isTextResult($message),
            default => false,
        };
    }
}
