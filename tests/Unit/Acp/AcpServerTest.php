<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Acp;

use Phycode\Tests\Support\AcpProcess;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\Pictures;
use Phycode\Tests\Support\SseReplies;
use Phycode\Tests\Unit\Llama\LlamaFake;
use Phycode\Tests\Unit\Mcp\FakeMcpServer;
use PHPUnit\Framework\TestCase;

final class AcpServerTest extends TestCase
{
    private ?LlamaFake $server = null;
    private ?AcpProcess $agent = null;
    private string $home;
    private string $project;

    protected function setUp(): void
    {
        $this->home = Kit::folder('acp-home');
        $this->project = (string) realpath(Kit::folder('acp-project'));
    }

    protected function tearDown(): void
    {
        $this->agent?->close();
        $this->server?->stop();
    }

    private function start(array $replies = [], array $more = [], array $profile = [], array $arguments = []): AcpProcess
    {
        $this->server = new LlamaFake(['POST /v1/chat/completions' => $replies === [] ? [SseReplies::text('unused')] : $replies]);
        AcpProcess::configure($this->home, $this->server->url(), $more, $profile);

        return $this->agent = AcpProcess::start($this->home, $arguments);
    }

    private function open(AcpProcess $agent, array $params = []): array
    {
        $agent->answer($agent->request('initialize', ['protocolVersion' => 1, 'clientCapabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test-editor', 'version' => '1']]));

        return $agent->answer($agent->request('session/new', $params + ['cwd' => $this->project, 'mcpServers' => []]))['result'];
    }

    private function chats(): array
    {
        return array_values(array_map(static fn (array $request): array => $request['json'], array_filter($this->server->requests(), static fn (array $request): bool => $request['method'] === 'POST')));
    }

    public function testInitializeAnswersWithTheProtocolVersionAndWhatTheAgentCanDo(): void
    {
        $agent = $this->start();

        $answer = $agent->answer($agent->request('initialize', ['protocolVersion' => 1, 'clientCapabilities' => ['fs' => ['readTextFile' => true], 'terminal' => true], 'clientInfo' => ['name' => 'zed', 'version' => '0.9']]));

        self::assertSame(1, $answer['result']['protocolVersion']);
        self::assertSame(['image' => true, 'audio' => false, 'embeddedContext' => true], $answer['result']['agentCapabilities']['promptCapabilities']);
        self::assertTrue($answer['result']['agentCapabilities']['loadSession']);
        self::assertSame(['http' => true, 'sse' => false], $answer['result']['agentCapabilities']['mcpCapabilities']);
        self::assertSame('phycode', $answer['result']['agentInfo']['name']);
        self::assertSame([], $answer['result']['authMethods']);
        self::assertSame(1, $agent->answer($agent->request('initialize', ['protocolVersion' => 7]))['result']['protocolVersion'], 'a newer client gets the latest version of the agent');
    }

    public function testANewSessionHasAnIdModesAndTheCommandsThatTheAgentUnderstands(): void
    {
        $agent = $this->start();

        $result = $this->open($agent);

        self::assertMatchesRegularExpression('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $result['sessionId']);
        self::assertSame('ask', $result['modes']['currentModeId']);
        self::assertSame(['ask', 'accept_edits', 'plan', 'yolo'], array_column($result['modes']['availableModes'], 'id'));
        $agent->next(2.0);
        $commands = $agent->updates('available_commands_update')[0]['availableCommands'] ?? [];
        $byName = array_column($commands, null, 'name');
        self::assertArrayHasKey('help', $byName);
        self::assertSame(['hint' => '[TASK]'], $byName['plan']['input']);
    }

    public function testASessionNeedsAnAbsoluteFolderAndAnUnknownSessionOrMethodIsAnError(): void
    {
        $agent = $this->start();
        $agent->answer($agent->request('initialize', ['protocolVersion' => 1]));

        $relative = $agent->answer($agent->request('session/new', ['cwd' => 'relative/path', 'mcpServers' => []]));
        $missing = $agent->answer($agent->request('session/new', ['cwd' => '/nonexistent/phycode-folder', 'mcpServers' => []]));
        $unknown = $agent->answer($agent->request('session/prompt', ['sessionId' => 'nope', 'prompt' => [['type' => 'text', 'text' => 'hi']]]));
        $method = $agent->answer($agent->request('session/frobnicate', []));

        self::assertSame(-32602, $relative['error']['code']);
        self::assertSame(-32602, $missing['error']['code']);
        self::assertSame(-32602, $unknown['error']['code']);
        self::assertSame(-32601, $method['error']['code']);
        self::assertStringContainsString('session/frobnicate', $method['error']['message']);
    }

    public function testAPromptStreamsTheAnswerAndEndsWithAStopReason(): void
    {
        $agent = $this->start([SseReplies::text('Hello from the model.')]);
        $session = $this->open($agent)['sessionId'];

        $answer = $agent->answer($agent->request('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Say hello.']]]));

        self::assertSame(['stopReason' => 'end_turn'], $answer['result']);
        self::assertSame('Hello from the model.', trim($agent->agentText()));
        $chunk = array_values(array_filter($agent->log, static fn (array $message): bool => ($message['params']['update']['sessionUpdate'] ?? null) === 'agent_message_chunk'))[0];
        self::assertSame($session, $chunk['params']['sessionId']);
        self::assertSame('Say hello.', $this->chats()[0]['messages'][1]['content']);
    }

    public function testAToolCallAsksTheEditorAndRunsOnlyWhenItIsAllowed(): void
    {
        $agent = $this->start([SseReplies::call('write', ['path' => 'notes.txt', 'content' => "hi\n"]), SseReplies::text('Written.')]);
        $session = $this->open($agent)['sessionId'];
        $asked = [];

        $answer = $agent->answer($agent->request('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Write notes.txt']]]), function (array $request) use (&$asked): array {
            $asked[] = $request;

            return ['outcome' => ['outcome' => 'selected', 'optionId' => 'allow-once']];
        });

        self::assertSame('end_turn', $answer['result']['stopReason']);
        self::assertCount(1, $asked);
        self::assertSame('session/request_permission', $asked[0]['method']);
        self::assertSame($session, $asked[0]['params']['sessionId']);
        self::assertSame(['allow_once', 'allow_always', 'reject_once'], array_column($asked[0]['params']['options'], 'kind'));
        self::assertSame('call_1', $asked[0]['params']['toolCall']['toolCallId']);
        self::assertSame("hi\n", file_get_contents($this->project . '/notes.txt'));
        $start = $agent->updates('tool_call')[0];
        self::assertSame(['call_1', 'write', 'edit', 'in_progress'], [$start['toolCallId'], $start['name'], $start['kind'], $start['status']]);
        self::assertStringContainsString('notes.txt', $start['title']);
        $end = $agent->updates('tool_call_update')[0];
        self::assertSame(['call_1', 'completed'], [$end['toolCallId'], $end['status']]);
        self::assertSame('Written.', trim($agent->agentText()));
    }

    public function testARejectedToolCallIsRefusedAndTheModelIsToldSo(): void
    {
        $agent = $this->start([SseReplies::call('write', ['path' => 'no.txt', 'content' => 'x']), SseReplies::text('Understood.')]);
        $session = $this->open($agent)['sessionId'];

        $answer = $agent->answer($agent->request('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Write no.txt']]]), static fn (array $request): array => ['outcome' => ['outcome' => 'selected', 'optionId' => 'reject-once']]);

        self::assertSame('end_turn', $answer['result']['stopReason']);
        self::assertFileDoesNotExist($this->project . '/no.txt');
        self::assertSame('failed', $agent->updates('tool_call_update')[0]['status']);
        $tool = array_values(array_filter($this->chats()[1]['messages'], static fn (array $message): bool => ($message['role'] ?? '') === 'tool'))[0]['content'];
        self::assertStringContainsString('the user denied this action', $tool);
    }

    public function testAnApprovalForTheSessionIsRememberedAndAnAnswerOfCancelledRefuses(): void
    {
        $agent = $this->start([
            SseReplies::call('write', ['path' => 'a.txt', 'content' => 'a'], 'c1'), SseReplies::text('one'),
            SseReplies::call('write', ['path' => 'b.txt', 'content' => 'b'], 'c2'), SseReplies::text('two'),
        ]);
        $session = $this->open($agent)['sessionId'];
        $asked = 0;
        $allow = function (array $request) use (&$asked): array {
            $asked++;

            return ['outcome' => ['outcome' => 'selected', 'optionId' => 'allow-session']];
        };

        $agent->answer($agent->request('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'first']]]), $allow);
        $agent->answer($agent->request('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'second']]]), $allow);

        self::assertSame(1, $asked, 'the second write is not asked about again');
        self::assertFileExists($this->project . '/b.txt');
    }

    public function testACancelStopsARunningCommandAndTheTurnEndsAsCancelled(): void
    {
        $agent = $this->start([SseReplies::call('bash', ['command' => 'sleep 30']), SseReplies::text('never')], arguments: ['--yolo']);
        $session = $this->open($agent)['sessionId'];
        $started = microtime(true);

        $id = $agent->request('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'wait']]]);
        while (($message = $agent->next(15.0)) !== null && ($message['params']['update']['sessionUpdate'] ?? null) !== 'tool_call') {
        }
        $agent->notify('session/cancel', ['sessionId' => $session]);
        $answer = $agent->answer($id);

        self::assertSame(['stopReason' => 'cancelled'], $answer['result']);
        self::assertLessThan(15.0, microtime(true) - $started);
    }

    public function testTheModeChangesTheAgentAndThePermissionsAndIsAnnounced(): void
    {
        $agent = $this->start([SseReplies::text('A plan.')]);
        $session = $this->open($agent)['sessionId'];

        $plan = $agent->answer($agent->request('session/set_mode', ['sessionId' => $session, 'modeId' => 'plan']));
        $agent->answer($agent->request('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'plan it']]]));

        self::assertSame([], (array) $plan['result']);
        self::assertSame('plan', $agent->updates('current_mode_update')[0]['currentModeId']);
        $names = array_map(static fn (array $tool): string => $tool['function']['name'] ?? '', $this->chats()[0]['tools'] ?? []);
        self::assertNotContains('write', $names, 'the plan mode has no tool that changes files');
        self::assertContains('read', $names);

        $agent->answer($agent->request('session/set_mode', ['sessionId' => $session, 'modeId' => 'accept_edits']));
        self::assertSame('accept_edits', $agent->updates('current_mode_update')[1]['currentModeId']);
        $bad = $agent->answer($agent->request('session/set_mode', ['sessionId' => $session, 'modeId' => 'wild']));
        self::assertSame(-32602, $bad['error']['code']);
    }

    public function testASavedSessionCanBeLoadedAndItsConversationIsReplayed(): void
    {
        $agent = $this->start([SseReplies::text('The answer is 42.')]);
        $session = $this->open($agent)['sessionId'];
        $agent->answer($agent->request('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'What is the answer?']]]));
        $agent->close();

        $second = $this->agent = AcpProcess::start($this->home);
        $second->answer($second->request('initialize', ['protocolVersion' => 1]));
        $loaded = $second->answer($second->request('session/load', ['sessionId' => $session, 'cwd' => $this->project, 'mcpServers' => []]));

        self::assertSame([], (array) $loaded['result']);
        self::assertSame('What is the answer?', $second->updates('user_message_chunk')[0]['content']['text']);
        self::assertSame('The answer is 42.', trim($second->agentText()));
        $unknown = $second->answer($second->request('session/load', ['sessionId' => '20990101-000000-abcdef', 'cwd' => $this->project, 'mcpServers' => []]));
        self::assertSame(-32602, $unknown['error']['code']);
    }

    public function testEmbeddedFilesAndLinksBecomeTextAndAnImageGoesAlongWhenTheModelSeesImages(): void
    {
        $agent = $this->start([SseReplies::text('ok')], profile: ['vision' => true]);
        $session = $this->open($agent)['sessionId'];

        $agent->answer($agent->request('session/prompt', ['sessionId' => $session, 'prompt' => [
            ['type' => 'text', 'text' => 'Review these.'],
            ['type' => 'resource', 'resource' => ['uri' => 'file:///work/app/main.py', 'mimeType' => 'text/x-python', 'text' => 'print(1)']],
            ['type' => 'resource_link', 'uri' => 'file:///work/app/notes.md', 'name' => 'notes.md'],
            ['type' => 'image', 'data' => Pictures::base64(), 'mimeType' => 'image/png', 'uri' => 'file:///work/shot.png'],
        ]]));

        $user = $this->chats()[0]['messages'][1]['content'];
        self::assertSame('text', $user[0]['type']);
        self::assertStringContainsString("Review these.\n\nContent of /work/app/main.py:\n```\nprint(1)\n```\n\nReferenced: /work/app/notes.md", $user[0]['text']);
        self::assertSame('image_url', $user[1]['type']);
        self::assertSame('data:image/png;base64,' . Pictures::base64(), $user[1]['image_url']['url']);
    }

    public function testAnImageForAModelThatCannotSeeIsNamedInTheTextInstead(): void
    {
        $agent = $this->start([SseReplies::text('ok')]);
        $session = $this->open($agent)['sessionId'];

        $agent->answer($agent->request('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Look.'], ['type' => 'image', 'data' => Pictures::base64(), 'mimeType' => 'image/png']]]));

        self::assertStringContainsString('[image image attached, but this model cannot see images', $this->chats()[0]['messages'][1]['content']);
    }

    public function testTheMcpServersTheEditorListsGiveTheirToolsAndABadOneIsReported(): void
    {
        $agent = $this->start([SseReplies::text('ok')]);
        $argv = FakeMcpServer::argv();
        $result = $this->open($agent, ['mcpServers' => [
            ['name' => 'fake', 'command' => $argv[0], 'args' => array_slice($argv, 1), 'env' => [['name' => 'FOO', 'value' => 'bar']]],
            ['type' => 'http', 'name' => 'plain', 'url' => 'http://example.com/mcp', 'headers' => []],
            ['type' => 'sse', 'name' => 'old', 'url' => 'https://example.com/sse', 'headers' => []],
        ]]);

        $agent->answer($agent->request('session/prompt', ['sessionId' => $result['sessionId'], 'prompt' => [['type' => 'text', 'text' => 'what tools do you have?']]]));

        $names = array_map(static fn (array $tool): string => $tool['function']['name'] ?? '', $this->chats()[0]['tools']);
        self::assertContains('mcp__fake__echo', $names);
        $warnings = implode("\n", array_map(static fn (array $update): string => (string) ($update['content']['text'] ?? ''), $agent->updates('agent_message_chunk')));
        self::assertStringContainsString('the url must be https', $warnings);
        self::assertStringContainsString('the MCP server old of the editor uses a transport that phycode does not speak', $warnings);
    }

    public function testWhenTheEditorClosesTheInputTheAgentEndsCleanly(): void
    {
        $agent = $this->start();
        $this->open($agent);

        $status = $agent->close();
        $this->agent = null;

        self::assertSame(0, $status);
    }
}
