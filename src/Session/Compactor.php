<?php

declare(strict_types=1);

namespace Phycode\Session;

use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class Compactor
{
    public const PREFIX = 'Summary of the earlier conversation:';

    private const KEPT_TURNS = 2;
    private const CLIP_BYTES = 4000;

    public static function compact(array $messages, ChatClient $client, Cancellation $cancel): ?array
    {
        $head = ($messages[0]['role'] ?? null) === 'system' ? 1 : 0;
        $rest = array_slice($messages, $head);
        $starts = ContextBudget::turnStarts($rest);
        if (count($starts) <= self::KEPT_TURNS) {
            return null;
        }
        $cut = $starts[count($starts) - self::KEPT_TURNS];
        $response = $client->chat(self::request(array_slice($rest, 0, $cut)), [], new ChatOptions(null, $cancel));
        $summary = trim($response->content);
        if ($summary === '') {
            throw new PhycodeException('the model gave an empty summary; the conversation is unchanged');
        }

        return [
            ...array_slice($messages, 0, $head),
            ['role' => 'user', 'content' => self::PREFIX . "\n" . $summary],
            ...array_slice($rest, $cut),
        ];
    }

    private static function request(array $older): array
    {
        return [
            ['role' => 'system', 'content' => 'You summarise a conversation between a user and a coding agent. Reply with the summary only.'],
            [
                'role' => 'user',
                'content' => "Summarise the conversation below in one short paragraph. Keep the user's goals, the decisions, the files that changed and what is still open.\n\n"
                    . implode("\n", array_map(self::line(...), $older)),
            ],
        ];
    }

    private static function line(array $message): string
    {
        $text = (string) ($message['role'] ?? '') . ': ' . Text::clip((string) ($message['content'] ?? ''), self::CLIP_BYTES);
        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            $text .= ' [called ' . (string) ($function['name'] ?? '') . ' ' . Text::clip((string) ($function['arguments'] ?? ''), 500) . ']';
        }

        return $text;
    }
}
