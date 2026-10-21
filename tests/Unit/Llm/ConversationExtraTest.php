<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Agent\Conversation;
use Phycode\Llm\ToolCall;
use PHPUnit\Framework\TestCase;

final class ConversationExtraTest extends TestCase
{
    public function testTheExtraOfAReplyIsKeptUnderTheExtraKeyOfItsAssistantMessage(): void
    {
        $conversation = new Conversation();
        $extra = ['anthropic' => [['type' => 'thinking', 'thinking' => 'Think.', 'signature' => 'SIG']]];

        $conversation->addAssistant('Checking.', [], $extra);

        self::assertSame([['role' => 'assistant', 'content' => 'Checking.', 'extra' => $extra]], $conversation->messages());
    }

    public function testTheExtraFollowsTheToolCallsOfTheSameMessage(): void
    {
        $conversation = new Conversation();

        $conversation->addAssistant('', [new ToolCall('call_1', 'get_weather', '{"city":"Madrid"}')], ['openai-responses' => [['type' => 'reasoning', 'id' => 'rs_1']]]);

        self::assertSame(
            ['role', 'content', 'tool_calls', 'extra'],
            array_keys($conversation->messages()[0]),
        );
        self::assertSame('call_1', $conversation->messages()[0]['tool_calls'][0]['id']);
    }

    public function testAnAssistantMessageWithoutExtraHasNoExtraKey(): void
    {
        $conversation = new Conversation();

        $conversation->addAssistant('Hi.');

        self::assertSame([['role' => 'assistant', 'content' => 'Hi.']], $conversation->messages());
    }
}
