<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\Usage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FinishReasonTest extends TestCase
{
    public static function reasons(): iterable
    {
        yield 'openai stop' => ['stop', false, FinishReason::STOP];
        yield 'anthropic end_turn' => ['end_turn', false, FinishReason::STOP];
        yield 'anthropic stop_sequence' => ['stop_sequence', false, FinishReason::STOP];
        yield 'length' => ['length', false, FinishReason::LENGTH];
        yield 'anthropic max_tokens' => ['max_tokens', false, FinishReason::LENGTH];
        yield 'anthropic context exhausted' => ['model_context_window_exceeded', false, FinishReason::LENGTH];
        yield 'tool_calls' => ['tool_calls', true, FinishReason::TOOL_CALLS];
        yield 'anthropic tool_use' => ['tool_use', true, FinishReason::TOOL_CALLS];
        yield 'a known reason keeps its meaning with tool calls' => ['length', true, FinishReason::LENGTH];
        yield 'stop with tool calls stays stop' => ['stop', true, FinishReason::STOP];
        yield 'no reason, no tool calls' => [null, false, FinishReason::STOP];
        yield 'no reason, tool calls' => [null, true, FinishReason::TOOL_CALLS];
        yield 'empty reason counts as none' => ['', false, FinishReason::STOP];
        yield 'unknown reason' => ['content_filter', false, FinishReason::ERROR];
        yield 'unknown reason with tool calls' => ['content_filter', true, FinishReason::TOOL_CALLS];
    }

    #[DataProvider('reasons')]
    public function testRawReasonsAreMappedToTheCanonicalOnes(?string $raw, bool $hasToolCalls, string $expected): void
    {
        self::assertSame($expected, FinishReason::normalize($raw, $hasToolCalls));
    }

    public function testUsageToArrayKeepsEveryFieldEvenWhenUnknown(): void
    {
        self::assertSame(
            ['promptTokens' => 34, 'completionTokens' => 13, 'cachedTokens' => null, 'tokensPerSecond' => 17.5],
            (new Usage(promptTokens: 34, completionTokens: 13, tokensPerSecond: 17.5))->toArray(),
        );
    }
}
