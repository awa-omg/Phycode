<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Session;

use Phycode\Agent\Conversation;
use Phycode\Llm\OpenAiClient;
use Phycode\Llm\ToolCall;
use Phycode\Session\SessionStore;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class ReplayDataTest extends TestCase
{
    public function testTheReplayDataOfAReplyIsSavedWithItsMessageAndReadBackWhenTheSessionResumes(): void
    {
        $store = new SessionStore(Kit::folder('session-replay'));
        $conversation = new Conversation();
        $conversation->addUser('Read a.txt.');
        $conversation->addAssistant('', [new ToolCall('call_1', 'read', '{"path":"a.txt"}')], [OpenAiClient::EXTRA => ['reasoning_content' => 'I should read it.']]);
        $conversation->addToolResult('call_1', 'hello');

        $now = SessionStore::now();
        $store->save([
            'id' => 'replay-1', 'title' => 'Read a.txt', 'createdAt' => $now, 'updatedAt' => $now,
            'profile' => 'deepseek', 'model' => 'deepseek-v4-pro', 'thinking' => 'high', 'messages' => $conversation->messages(),
        ]);

        self::assertSame($conversation->messages(), $store->load('replay-1')['messages']);
    }
}
