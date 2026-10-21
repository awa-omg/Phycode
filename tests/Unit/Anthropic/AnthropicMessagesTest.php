<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Anthropic;

use Phycode\Llm\Anthropic\AnthropicMessages;
use PHPUnit\Framework\TestCase;

final class AnthropicMessagesTest extends TestCase
{
    private static function callAndResult(): array
    {
        return [
            ['role' => 'user', 'content' => 'Weather?'],
            ['role' => 'assistant', 'content' => 'Checking.', 'tool_calls' => [
                ['id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Madrid"}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => '{"temp":21}'],
        ];
    }

    public function testTheSystemMessagesAreJoinedAndNoSystemMessageGivesNull(): void
    {
        self::assertSame("Be brief.\n\nUse tools.", AnthropicMessages::system([
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'system', 'content' => 'Use tools.'],
        ]));
        self::assertNull(AnthropicMessages::system([['role' => 'user', 'content' => 'hi']]));
    }

    public function testAToolCallIsAToolUseBlockWithItsArgumentsAsAnObjectAndItsResultIsAToolResult(): void
    {
        self::assertSame(
            '[{"role":"user","content":[{"type":"text","text":"Weather?"}]},'
            . '{"role":"assistant","content":[{"type":"text","text":"Checking."},{"type":"tool_use","id":"toolu_1","name":"get_weather","input":{"city":"Madrid"}}]},'
            . '{"role":"user","content":[{"type":"tool_result","tool_use_id":"toolu_1","content":"{\"temp\":21}"}]}]',
            json_encode(AnthropicMessages::convert(self::callAndResult(), true), JSON_THROW_ON_ERROR),
        );
    }

    public function testAnEmptyOrMissingArgumentsBecomeAnEmptyObject(): void
    {
        $converted = AnthropicMessages::convert([
            ['role' => 'user', 'content' => 'x'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'a', 'type' => 'function', 'function' => ['name' => 'list', 'arguments' => '']],
                ['id' => 'b', 'type' => 'function', 'function' => ['name' => 'list', 'arguments' => '{}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'a', 'content' => 'one'],
            ['role' => 'tool', 'tool_call_id' => 'b', 'content' => 'two'],
        ], true);

        self::assertSame('{"type":"tool_use","id":"a","name":"list","input":{}}', json_encode($converted[1]['content'][0], JSON_THROW_ON_ERROR));
        self::assertSame('{"type":"tool_use","id":"b","name":"list","input":{}}', json_encode($converted[1]['content'][1], JSON_THROW_ON_ERROR));
    }

    public function testTheReplayedThinkingComesFirstWithItsSignatureAndBeforeTheTextAndTheCall(): void
    {
        $messages = self::callAndResult();
        $messages[1]['extra'] = ['anthropic' => [['type' => 'thinking', 'thinking' => 'Think first.', 'signature' => 'SIG-1']]];

        $converted = AnthropicMessages::convert($messages, true);

        self::assertSame(
            '[{"type":"thinking","thinking":"Think first.","signature":"SIG-1"},{"type":"text","text":"Checking."},{"type":"tool_use","id":"toolu_1","name":"get_weather","input":{"city":"Madrid"}}]',
            json_encode($converted[1]['content'], JSON_THROW_ON_ERROR),
        );
    }

    public function testConsecutiveToolResultsAreOneUserMessageAndAUserTextAfterThemIsMergedIntoIt(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Two things.'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'a', 'type' => 'function', 'function' => ['name' => 'x', 'arguments' => '{}']],
                ['id' => 'b', 'type' => 'function', 'function' => ['name' => 'y', 'arguments' => '{}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'a', 'content' => 'A'],
            ['role' => 'tool', 'tool_call_id' => 'b', 'content' => 'B'],
            ['role' => 'user', 'content' => 'Your last reply was empty.'],
        ];

        $converted = AnthropicMessages::convert($messages, true);

        self::assertSame(['user', 'assistant', 'user'], array_column($converted, 'role'));
        self::assertSame(
            ['tool_result', 'tool_result', 'text'],
            array_map(static fn (array $block): string => $block['type'], $converted[2]['content']),
        );
    }

    public function testAnEmptyAssistantTurnIsSkippedAndItsNeighboursAreMerged(): void
    {
        $converted = AnthropicMessages::convert([
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'assistant', 'content' => ''],
            ['role' => 'user', 'content' => 'b'],
        ], true);

        self::assertCount(1, $converted);
        self::assertSame('[{"type":"text","text":"a"},{"type":"text","text":"b"}]', json_encode($converted[0]['content'], JSON_THROW_ON_ERROR));
    }

    public function testAResultWhoseCallIsNotInTheHistoryIsDropped(): void
    {
        $converted = AnthropicMessages::convert([
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'tool', 'tool_call_id' => 'gone', 'content' => 'x'],
            ['role' => 'assistant', 'content' => 'b'],
        ], true);

        self::assertSame(['user', 'assistant'], array_column($converted, 'role'));
    }

    public function testTheHistoryStartsWithAUserMessage(): void
    {
        $converted = AnthropicMessages::convert([
            ['role' => 'assistant', 'content' => 'the cut-away start'],
            ['role' => 'user', 'content' => 'the question'],
        ], true);

        self::assertSame([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'the question']]]], $converted);
    }

    public function testWithoutToolsTheCallsAndTheirResultsAreWrittenAsText(): void
    {
        $converted = AnthropicMessages::convert(self::callAndResult(), false);

        self::assertSame(
            '[{"role":"user","content":[{"type":"text","text":"Weather?"}]},'
            . '{"role":"assistant","content":[{"type":"text","text":"Checking.\n[tool call get_weather {\"city\":\"Madrid\"}]"}]},'
            . '{"role":"user","content":[{"type":"text","text":"[tool result]\n{\"temp\":21}"}]}]',
            json_encode($converted, JSON_THROW_ON_ERROR),
        );
    }

    public function testToolsBecomeInputSchemasAndAToolWithoutParametersGetsAnEmptyObject(): void
    {
        $tools = [
            ['type' => 'function', 'function' => ['name' => 'get_weather', 'description' => 'Get it.', 'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']]]],
            ['type' => 'function', 'function' => ['name' => 'now', 'description' => 'Time.', 'parameters' => ['type' => 'object', 'properties' => []]]],
        ];

        self::assertSame(
            '[{"name":"get_weather","input_schema":{"type":"object","properties":{"city":{"type":"string"}},"required":["city"]},"description":"Get it."},'
            . '{"name":"now","input_schema":{"type":"object","properties":{}},"description":"Time."}]',
            json_encode(AnthropicMessages::tools($tools), JSON_THROW_ON_ERROR),
        );
    }
}
