<?php

declare(strict_types=1);

namespace Phycode\Cli;

use Phycode\Llm\Image;
use Phycode\Session\ContextBudget;
use Phycode\Support\Text;

final class Compaction
{
    public const PREFIX = 'Summary of the earlier conversation:';
    public const SECTIONS = ['Goal', 'Progress', 'Decisions', 'Files touched', 'Pending'];

    private const KEPT_TURNS = 2;
    private const CLIP_BYTES = 4000;
    private const CLIP_CALL_BYTES = 500;
    private const MIN_BUFFER = 200;
    private const BUFFER_SHARE = 0.02;

    public static function cut(array $messages, ?int $keep): ?int
    {
        $head = self::headLength($messages);
        $rest = array_slice($messages, $head);
        $starts = ContextBudget::turnStarts($rest);
        if ($starts === []) {
            return null;
        }
        if ($keep === null) {
            if (count($starts) <= self::KEPT_TURNS) {
                return null;
            }
            $chosen = $starts[count($starts) - self::KEPT_TURNS];
        } else {
            $chosen = $starts[count($starts) - 1];
            foreach ($starts as $start) {
                if (ContextBudget::estimate(array_slice($rest, $start)) <= $keep) {
                    $chosen = $start;
                    break;
                }
            }
        }
        if ($chosen === 0 || (isset($starts[1]) && $chosen === $starts[1] && self::isSummary($rest[0]))) {
            return null;
        }

        return $head + $chosen;
    }

    public static function threshold(int $window, ?int $buffer): ?int
    {
        $room = $window - ($buffer ?? max(self::MIN_BUFFER, (int) round($window * self::BUFFER_SHARE)));

        return $room > 0 ? $room : null;
    }

    public static function request(array $older, ?string $instructions): array
    {
        $focus = $instructions === null || trim($instructions) === '' ? '' : 'Focus the summary on this: ' . trim($instructions) . "\n";
        $sections = implode(', ', self::SECTIONS);

        return [
            ['role' => 'system', 'content' => 'You summarise a conversation between a user and a coding agent, for the agent that goes on with it. '
                . "Reply with the summary only, in these sections, in this order: {$sections}. Write \"none\" under a section that has nothing."],
            [
                'role' => 'user',
                'content' => "Summarise the conversation below in those sections. Keep the goal, what is done, the decisions, the files that changed and what is still open.\n"
                    . $focus . "\n" . implode("\n", array_map(self::line(...), $older)),
            ],
        ];
    }

    public static function sharedRequest(array $upTo, ?string $instructions): array
    {
        $focus = $instructions === null || trim($instructions) === '' ? '' : "\n" . (self::isFocus($instructions) ? 'Focus the summary on this: ' : '') . trim($instructions);
        $sections = implode(', ', self::SECTIONS);

        return [
            ...$upTo,
            ['role' => 'user', 'content' => 'Stop here, and do not call any tool. Summarise the conversation above, for the agent that goes on with it. '
                . "Reply with the summary only, in these sections, in this order: {$sections}. Write \"none\" under a section that has nothing. "
                . 'Keep the goal, what is done, the decisions, the files that changed and what is still open.' . $focus],
        ];
    }

    private static function isFocus(string $instructions): bool
    {
        return !str_starts_with(trim($instructions), 'This summary is');
    }

    public static function apply(array $messages, int $cut, string $summary): array
    {
        return [
            ...array_slice($messages, 0, self::headLength($messages)),
            ['role' => 'user', 'content' => self::PREFIX . "\n" . $summary],
            ...array_slice($messages, $cut),
        ];
    }

    public static function isSummary(array $message): bool
    {
        return ($message['role'] ?? null) === 'user' && str_starts_with((string) ($message['content'] ?? ''), self::PREFIX);
    }

    public static function headLength(array $messages): int
    {
        return ($messages[0]['role'] ?? null) === 'system' ? 1 : 0;
    }

    private static function line(array $message): string
    {
        $text = (string) ($message['role'] ?? '') . ': ' . Text::clip(Image::textWithPlaceholders($message), self::CLIP_BYTES);
        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            $text .= ' [called ' . (string) ($function['name'] ?? '') . ' ' . Text::clip((string) ($function['arguments'] ?? ''), self::CLIP_CALL_BYTES) . ']';
        }

        return $text;
    }
}
