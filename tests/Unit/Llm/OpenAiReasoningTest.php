<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Agent\Conversation;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\EffortMapping;
use Phycode\Llm\LlmException;
use Phycode\Llm\OpenAiClient;
use Phycode\Llm\ToolCall;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\TestCase;

final class OpenAiReasoningTest extends TestCase
{
    private const MESSAGES = [['role' => 'system', 'content' => 'You are concise.'], ['role' => 'user', 'content' => 'Say hello.']];
    private const TOOLS = [['type' => 'function', 'function' => ['name' => 'read', 'description' => 'Reads a file.', 'parameters' => ['type' => 'object', 'properties' => []]]]];

    private ?ProtocolServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        EffortMapping::setTestHost('openrouter.ai', null);
        EffortMapping::setTestHost('api.deepseek.com', null);
    }

    private function serve(array $steps): ProtocolServer
    {
        return $this->server = new ProtocolServer(['s' => ['/v1/chat/completions' => $steps]]);
    }

    public function testAMistralStreamTakesItsThinkingPartsAsReasoningAndItsLastEventThatHasNoBlankLineAfterIt(): void
    {
        $server = $this->serve([['fixture' => 'mistral/content.sse']]);
        $events = [];
        $options = new ChatOptions(onEvent: static function (string $type, string $text) use (&$events): void {
            $events[] = [$type, $text];
        });

        $response = (new OpenAiClient($server->profile('s', ['model' => 'mistral-small-latest', 'effort_format' => 'openai', 'thinking' => 'high']), []))
            ->chat(self::MESSAGES, [], $options);

        self::assertSame('The answer is 391. Done.', $response->content);
        self::assertSame('17 * 23 = 391.', $response->reasoning);
        self::assertSame('stop', $response->finishReason);
        self::assertSame(20, $response->usage->completionTokens, 'the usage chunk comes after the last blank line, so it is read at the flush');
        self::assertSame([['reasoning', '17 * 23 = 391.'], ['text', 'The answer is 391.'], ['text', ' Done.']], $events);
    }

    public function testAReplyThatIsOneJsonObjectIsReadAsTheWholeChatCompletion(): void
    {
        $server = $this->serve([['fixture' => 'mistral/reply.json']]);

        $response = (new OpenAiClient($server->profile('s', ['model' => 'mistral-small-latest']), []))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame(['Four.', 'Two plus two.', 'stop', 6], [$response->content, $response->reasoning, $response->finishReason, $response->usage->completionTokens]);
    }

    public function testAReplyThatIsNeitherEventsNorAChatCompletionSaysSo(): void
    {
        $server = $this->serve([['body' => '<html>the gateway says no</html>', 'type' => 'text/html']]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('the reply is neither an event stream nor a chat completion');

        (new OpenAiClient($server->profile('s', ['model' => 'm']), []))->chat(self::MESSAGES, [], new ChatOptions());
    }

    public function testOpenRouterKeepsTheReasoningDetailsOfAReplyWithToolCallsAndSendsThemBackUnchanged(): void
    {
        EffortMapping::setTestHost('openrouter.ai', '127.0.0.1');
        $server = $this->serve([['fixture' => 'openrouter/tool-call.sse'], ['fixture' => 'llama/text.sse']]);
        $client = new OpenAiClient($server->profile('s', ['model' => 'openai/gpt-oss-120b', 'thinking' => 'low']), []);

        $first = $client->chat(self::MESSAGES, self::TOOLS, new ChatOptions());
        self::assertSame('The user asks for a file. I should read it.', $first->reasoning, 'the reasoning is read from its string field');
        $details = [
            ['type' => 'reasoning.text', 'text' => 'The user asks for a file. I should read it.', 'format' => 'unknown', 'index' => 0, 'signature' => 'sig-1'],
            ['type' => 'reasoning.encrypted', 'data' => 'ZW5j', 'format' => 'unknown', 'index' => 1],
        ];
        self::assertSame(['reasoning_details' => $details], $first->extra[OpenAiClient::EXTRA], 'the pieces of each detail are joined by its index');

        $conversation = new Conversation();
        $conversation->addUser('Read a.txt.');
        $conversation->addAssistant('', [new ToolCall('call_1', 'read', '{"path":"a.txt"}')], $first->extra);
        $conversation->addToolResult('call_1', 'hello');
        $client->chat($conversation->messages(), self::TOOLS, new ChatOptions());

        $sent = $server->requests('POST')[1]['json']['messages'];
        self::assertSame($details, $sent[1]['reasoning_details'], 'the reasoning goes back with its tool calls, as it came');
        self::assertArrayNotHasKey('extra', $sent[1]);
    }

    public function testOpenRouterKeepsNoReasoningDetailsOfAReplyWithoutToolCalls(): void
    {
        EffortMapping::setTestHost('openrouter.ai', '127.0.0.1');
        $server = $this->serve([['fixture' => 'llama/text.sse']]);

        $response = (new OpenAiClient($server->profile('s', ['model' => 'openai/gpt-oss-120b', 'thinking' => 'low']), []))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame([], $response->extra);
    }

    public function testDeepSeekKeepsItsReasoningContentAndSendsItOnEveryMessageOfARequestWithTools(): void
    {
        EffortMapping::setTestHost('api.deepseek.com', '127.0.0.1');
        $server = $this->serve([['fixture' => 'deepseek/tool-call.sse'], ['fixture' => 'llama/text.sse']]);
        $client = new OpenAiClient($server->profile('s', ['model' => 'deepseek-v4-pro', 'thinking' => 'high']), []);

        $first = $client->chat(self::MESSAGES, self::TOOLS, new ChatOptions());
        self::assertSame(['reasoning_content' => 'The user wants a file. I should read a.txt.'], $first->extra[OpenAiClient::EXTRA]);

        $conversation = new Conversation();
        $conversation->addUser('Read a.txt.');
        $conversation->addAssistant('', [new ToolCall('call_0', 'read', '{"path":"a.txt"}')], $first->extra);
        $conversation->addToolResult('call_0', 'hello');
        $conversation->addAssistant('It says hello.');
        $conversation->addUser('And b.txt?');
        $client->chat($conversation->messages(), self::TOOLS, new ChatOptions());

        $sent = $server->requests('POST')[1]['json']['messages'];
        self::assertSame('The user wants a file. I should read a.txt.', $sent[1]['reasoning_content']);
        self::assertSame('', $sent[3]['reasoning_content'], 'a message with no reasoning kept sends an empty one, which DeepSeek takes');
        self::assertArrayNotHasKey('reasoning_content', $sent[2], 'the tool result is not an assistant message');
    }

    public function testDeepSeekSendsNoReasoningContentWithoutTools(): void
    {
        EffortMapping::setTestHost('api.deepseek.com', '127.0.0.1');
        $server = $this->serve([['fixture' => 'llama/text.sse']]);
        $conversation = new Conversation();
        $conversation->addUser('Hi.');
        $conversation->addAssistant('Hello.', [], [OpenAiClient::EXTRA => ['reasoning_content' => 'Greet.']]);
        $conversation->addUser('Again.');

        (new OpenAiClient($server->profile('s', ['model' => 'deepseek-v4-pro', 'thinking' => 'high']), []))->chat($conversation->messages(), [], new ChatOptions());

        self::assertSame([['role' => 'user', 'content' => 'Hi.'], ['role' => 'assistant', 'content' => 'Hello.'], ['role' => 'user', 'content' => 'Again.']], $server->requests('POST')[0]['json']['messages']);
    }

    public function testAFormatThatTakesNoReplyDataSendsNoneBack(): void
    {
        $server = $this->serve([['fixture' => 'openrouter/tool-call.sse'], ['fixture' => 'llama/text.sse']]);
        $client = new OpenAiClient($server->profile('s', ['model' => 'm', 'effort_format' => 'chat_template', 'thinking' => 'low']), []);

        $first = $client->chat(self::MESSAGES, self::TOOLS, new ChatOptions());
        self::assertSame([], $first->extra, 'the switch format keeps no reply data');
        $conversation = new Conversation();
        $conversation->addAssistant('', [new ToolCall('call_1', 'read', '{}')], [OpenAiClient::EXTRA => ['reasoning_details' => [['text' => 'x']]]]);
        $client->chat($conversation->messages(), self::TOOLS, new ChatOptions());

        self::assertArrayNotHasKey('reasoning_details', $server->requests('POST')[1]['json']['messages'][0]);
    }
}
