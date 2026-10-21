<?php

declare(strict_types=1);

namespace Phycode\Llm\Canonical;

final class FinishReason
{
    public const STOP = 'stop';
    public const LENGTH = 'length';
    public const TOOL_CALLS = 'tool_calls';
    public const ERROR = 'error';

    private const ALIASES = [
        'stop' => self::STOP,
        'end_turn' => self::STOP,
        'stop_sequence' => self::STOP,
        'length' => self::LENGTH,
        'max_tokens' => self::LENGTH,
        'model_context_window_exceeded' => self::LENGTH,
        'tool_calls' => self::TOOL_CALLS,
        'tool_use' => self::TOOL_CALLS,
    ];

    public static function normalize(?string $raw, bool $hasToolCalls): string
    {
        if ($raw === null || $raw === '') {
            return $hasToolCalls ? self::TOOL_CALLS : self::STOP;
        }

        return self::ALIASES[$raw] ?? ($hasToolCalls ? self::TOOL_CALLS : self::ERROR);
    }
}
