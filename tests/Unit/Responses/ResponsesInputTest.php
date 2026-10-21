<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Responses;

use Phycode\Llm\Responses\ResponsesInput;
use PHPUnit\Framework\TestCase;

final class ResponsesInputTest extends TestCase
{
    private static function callAndResult(): array
    {
        return [
            ['role' => 'user', 'content' => 'Weather?'],
            ['role' => 'assistant', 'content' => 'Checking.', 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Madrid"}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"temp":21}'],
        ];
    }

    public function testTheSystemMessagesAreTheInstructionsInOrderAndNoSystemMessageGivesNull(): void
    {
        self::assertSame("Be brief.\n\nUse tools.", ResponsesInput::instructions([
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'system', 'content' => 'Use tools.'],
        ]));
        self::assertNull(ResponsesInput::instructions([['role' => 'user', 'content' => 'hi']]));
    }

    public function testATurnBecomesMessageItemsAndACallAndItsResultBecomeFunctionItems(): void
    {
        self::assertSame([
            ['type' => 'message', 'role' => 'user', 'content' => 'Weather?'],
            ['type' => 'message', 'role' => 'assistant', 'content' => 'Checking.'],
            ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'get_weather', 'arguments' => '{"city":"Madrid"}'],
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"temp":21}'],
        ], ResponsesInput::items(self::callAndResult()));
    }

    public function testSystemMessagesAreNotItems(): void
    {
        self::assertSame(
            [['type' => 'message', 'role' => 'user', 'content' => 'hi']],
            ResponsesInput::items([['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'hi']]),
        );
    }

    public function testAnEmptyAssistantTextGivesNoMessageItemAndEmptyArgumentsBecomeAnEmptyObject(): void
    {
        self::assertSame(
            [['type' => 'function_call', 'call_id' => 'call_2', 'name' => 'list', 'arguments' => '{}']],
            ResponsesInput::items([
                ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                    ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'list', 'arguments' => '']],
                ]],
            ]),
        );
    }

    public function testTheReasoningItemsOfAReplyGoBackBeforeItsTextAndItsCalls(): void
    {
        $messages = self::callAndResult();
        $messages[1]['extra'] = ['openai-responses' => [['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'ENC']]];

        $items = ResponsesInput::items($messages);

        self::assertSame(['message', 'reasoning', 'message', 'function_call', 'function_call_output'], array_column($items, 'type'));
        self::assertSame(['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'ENC'], $items[1]);
    }

    public function testAResultWhoseCallIsNotInTheHistoryIsDropped(): void
    {
        self::assertSame(
            [['type' => 'message', 'role' => 'user', 'content' => 'a'], ['type' => 'message', 'role' => 'assistant', 'content' => 'b']],
            ResponsesInput::items([
                ['role' => 'user', 'content' => 'a'],
                ['role' => 'tool', 'tool_call_id' => 'gone', 'content' => 'x'],
                ['role' => 'assistant', 'content' => 'b'],
            ]),
        );
    }

    public function testToolsBecomeFunctionObjectsAndAToolWithoutParametersGetsAnEmptyObject(): void
    {
        $tools = [
            ['type' => 'function', 'function' => ['name' => 'get_weather', 'description' => 'Get it.', 'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']]]],
            ['type' => 'function', 'function' => ['name' => 'now', 'description' => 'Time.', 'parameters' => ['type' => 'object', 'properties' => []]]],
        ];

        self::assertSame(
            '[{"type":"function","name":"get_weather","description":"Get it.","parameters":{"type":"object","properties":{"city":{"type":"string"}},"required":["city"]}},'
            . '{"type":"function","name":"now","description":"Time.","parameters":{"type":"object","properties":{}}}]',
            json_encode(ResponsesInput::tools($tools), JSON_THROW_ON_ERROR),
        );
    }
}
