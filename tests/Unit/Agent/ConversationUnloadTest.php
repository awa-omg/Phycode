<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Agent;

use Phycode\Agent\Conversation;
use Phycode\Llm\TextToolProtocol;
use Phycode\Llm\ToolCall;
use PHPUnit\Framework\TestCase;

final class ConversationUnloadTest extends TestCase
{
    private const LINE = '[skill go-testing unloaded to save context; load it again with skill(name) if needed]';

    public function testInNativeModeTheResultsOfTheEarlierLoadsAreReplacedAndTheRestAreKept(): void
    {
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('go');
        $conversation->addAssistant('', [new ToolCall('c1', 'skill', '{"name":"go-testing"}')]);
        $conversation->addToolResult('c1', 'the go-testing body');
        $conversation->addAssistant('', [new ToolCall('c2', 'skill', '{"name":"api"}')]);
        $conversation->addToolResult('c2', 'the api body');
        $conversation->addAssistant('', [new ToolCall('c3', 'skill', '{"name":"go-testing"}')]);
        $conversation->addToolResult('c3', 'ERROR: no skill named go-testing');

        $count = $conversation->unloadSkill('go-testing', self::LINE, true);

        self::assertSame(1, $count, 'the failed load is not a loaded skill');
        $results = array_column(array_filter($conversation->messages(), static fn (array $m): bool => $m['role'] === 'tool'), 'content');
        self::assertSame([self::LINE, 'the api body', 'ERROR: no skill named go-testing'], $results);
    }

    public function testAnUnloadCallItselfIsNotALoadAndAnUnloadWithNoLoadChangesNothing(): void
    {
        $conversation = new Conversation();
        $conversation->addAssistant('', [new ToolCall('c1', 'skill', '{"name":"go-testing","unload":true}')]);
        $conversation->addToolResult('c1', self::LINE);
        $before = $conversation->messages();

        self::assertSame(0, $conversation->unloadSkill('go-testing', self::LINE, true));
        self::assertSame($before, $conversation->messages());
    }

    public function testInTextModeTheResultThatFollowsTheReplyIsReplacedInTheProtocolShape(): void
    {
        $conversation = new Conversation();
        $conversation->addAssistant("<tool_call>\n{\"name\": \"skill\", \"arguments\": {\"name\": \"go-testing\"}}\n</tool_call>");
        $conversation->addUser(TextToolProtocol::formatResult('skill', 'the go-testing body'));
        $conversation->addAssistant("<tool_call>\n{\"name\": \"skill\", \"arguments\": {\"name\": \"api\"}}\n</tool_call>");
        $conversation->addUser(TextToolProtocol::formatResult('skill', 'the api body'));

        self::assertSame(1, $conversation->unloadSkill('go-testing', self::LINE, false));

        $messages = $conversation->messages();
        self::assertSame(TextToolProtocol::formatResult('skill', self::LINE), $messages[1]['content']);
        self::assertSame(TextToolProtocol::formatResult('skill', 'the api body'), $messages[3]['content']);
    }

    public function testInTextModeTheResultOfTheRightCallInAReplyWithTwoCallsIsReplaced(): void
    {
        $conversation = new Conversation();
        $conversation->addAssistant(
            "<tool_call>\n{\"name\": \"skill\", \"arguments\": {\"name\": \"api\"}}\n</tool_call>\n"
            . "<tool_call>\n{\"name\": \"skill\", \"arguments\": {\"name\": \"go-testing\"}}\n</tool_call>",
        );
        $conversation->addUser(TextToolProtocol::formatResult('skill', 'the api body'));
        $conversation->addUser(TextToolProtocol::formatResult('skill', 'the go-testing body'));

        self::assertSame(1, $conversation->unloadSkill('go-testing', self::LINE, false));

        self::assertSame(TextToolProtocol::formatResult('skill', 'the api body'), $conversation->messages()[1]['content']);
        self::assertSame(TextToolProtocol::formatResult('skill', self::LINE), $conversation->messages()[2]['content']);
    }

    public function testInTextModeAFailedLoadIsNotReplaced(): void
    {
        $conversation = new Conversation();
        $conversation->addAssistant("<tool_call>\n{\"name\": \"skill\", \"arguments\": {\"name\": \"go-testing\"}}\n</tool_call>");
        $conversation->addUser(TextToolProtocol::formatResult('skill', 'ERROR: no skill named go-testing'));

        self::assertSame(0, $conversation->unloadSkill('go-testing', self::LINE, false));
    }
}
