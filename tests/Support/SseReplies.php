<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

final class SseReplies
{
    public static function text(string $text): array
    {
        return ['body' => self::chunk(['role' => 'assistant', 'content' => $text]) . self::chunk([], 'stop') . "data: [DONE]\n\n", 'type' => 'text/event-stream'];
    }

    public static function call(string $tool, array $arguments, string $id = 'call_1'): array
    {
        $call = ['tool_calls' => [['index' => 0, 'id' => $id, 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => json_encode((object) $arguments, JSON_THROW_ON_ERROR)]]]];

        return ['body' => self::chunk($call) . self::chunk([], 'tool_calls') . "data: [DONE]\n\n", 'type' => 'text/event-stream'];
    }

    private static function chunk(array $delta, ?string $finish = null): string
    {
        return 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]], JSON_THROW_ON_ERROR) . "\n\n";
    }
}
