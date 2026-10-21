<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\Kernel;
use Phycode\Security\Decision;
use Phycode\Session\Transcript;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use Phycode\Tests\Unit\Mcp\FakeMcpServer;
use PHPUnit\Framework\TestCase;

final class McpSessionTest extends TestCase
{
    private ?SessionHarness $harness = null;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness?->end();
    }

    private function harness(array $servers, string $mode = 'ask'): SessionHarness
    {
        return $this->harness = new SessionHarness([
            'default_profile' => 'local',
            'permissions' => ['mode' => $mode],
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'native']],
            'mcp_servers' => $servers,
        ]);
    }

    private function kernel(CountingProvider $provider, bool $mcp = true): Kernel
    {
        return new Kernel($this->harness->cwd, false, $this->ui, null, $provider, [], $mcp);
    }

    private function notices(): array
    {
        return array_values(array_map(
            static fn (array $event): array => [(string) $event[1], (string) $event[2]],
            array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice'),
        ));
    }

    public function testTheToolsOfAServerAreInTheRegistryAndAServerThatFailsIsReportedWithoutStoppingTheOthers(): void
    {
        $this->harness([
            'fake' => ['command' => FakeMcpServer::argv('modern-full')],
            'broken' => ['command' => ['/nonexistent/phycode-mcp']],
        ]);
        $kernel = $this->kernel(new CountingProvider(['local' => 'qwen']));

        self::assertNotNull($kernel->tools->get('mcp__fake__echo'));
        self::assertNotNull($kernel->tools->get('mcp_resource'));
        self::assertNull($kernel->tools->get('mcp__broken__echo'));
        $warned = array_filter($this->notices(), static fn (array $notice): bool => $notice[0] === 'warn' && str_starts_with($notice[1], 'MCP server broken did not start: '));
        self::assertCount(1, $warned);
        self::assertStringContainsString('Instructions from the MCP servers', $kernel->systemPrompt($kernel->primaryAgent(null), 'native', $kernel->policyFor($kernel->primaryAgent(null))));
    }

    public function testACommandThatRunsNoAgentStartsNoServer(): void
    {
        $this->harness(['fake' => ['command' => FakeMcpServer::argv()]]);
        $kernel = $this->kernel(new CountingProvider(['local' => 'qwen']), false);

        self::assertNull($kernel->mcp);
        self::assertNull($kernel->tools->get('mcp__fake__echo'));
    }

    public function testTheModelCallsAServerToolAfterTheUserApprovesIt(): void
    {
        $this->harness(['fake' => ['command' => FakeMcpServer::argv()]]);
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [
            ScriptedClient::call('mcp__fake__echo', ['text' => 'hello from the model']),
            ScriptedClient::text('done'),
        ]);
        $this->ui->decisions = [Decision::AllowOnce];
        $session = new \Phycode\Cli\ChatSession($this->kernel($provider), 'local', Kit::agent(), Transcript::memory());

        $session->handle('use the echo tool');

        self::assertSame(1, count(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'confirm')), 'a server tool asks by default');
        self::assertContains('mcp__fake__echo', array_map(static fn (array $tool): string => $tool['function']['name'] ?? $tool['name'] ?? '', $provider->sent[0]['tools']));
        $answer = array_values(array_filter($provider->sent[1]['messages'], static fn (array $message): bool => ($message['role'] ?? '') === 'tool'));
        self::assertSame('hello from the model', $answer[0]['content']);
    }

    public function testAServerWithApprovalAllowRunsItsToolsWithoutAsking(): void
    {
        $this->harness(['fake' => ['command' => FakeMcpServer::argv(), 'approval' => 'allow']]);
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [
            ScriptedClient::call('mcp__fake__echo', ['text' => 'no question']),
            ScriptedClient::text('done'),
        ]);
        $session = new \Phycode\Cli\ChatSession($this->kernel($provider), 'local', Kit::agent(), Transcript::memory());

        $session->handle('use the echo tool');

        self::assertSame([], array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'confirm'));
        $answer = array_values(array_filter($provider->sent[1]['messages'], static fn (array $message): bool => ($message['role'] ?? '') === 'tool'));
        self::assertSame('no question', $answer[0]['content']);
    }

    public function testArgumentsThatBreakTheSchemaOfTheServerNeverReachIt(): void
    {
        $this->harness(['fake' => ['command' => FakeMcpServer::argv(), 'approval' => 'allow']]);
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [
            ScriptedClient::call('mcp__fake__echo', ['texto' => 'wrong name']),
            ScriptedClient::text('done'),
        ]);
        $session = new \Phycode\Cli\ChatSession($this->kernel($provider), 'local', Kit::agent(), Transcript::memory());

        $session->handle('use the echo tool');

        $answer = array_values(array_filter($provider->sent[1]['messages'], static fn (array $message): bool => ($message['role'] ?? '') === 'tool'));
        self::assertStringContainsString("ERROR: invalid arguments: $: the property 'text' is required", $answer[0]['content']);
    }

    public function testThePromptsOfAServerAreCommandsThatRunAsTurns(): void
    {
        $this->harness(['fake' => ['command' => FakeMcpServer::argv('modern-full')]]);
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text('reviewed')]);
        $session = new \Phycode\Cli\ChatSession($this->kernel($provider), 'local', Kit::agent(), Transcript::memory());

        self::assertContains('/mcp__fake__review CODE [FOCUS]', array_column($session->commands(), 'name'));
        self::assertTrue($session->isCommand('/mcp__fake__review code=x'));
        self::assertFalse($session->isCommand('/mcp__fake__nothing'));

        self::assertTrue($session->handle('/mcp__fake__review code="echo 1" focus=style'));

        $messages = $provider->sent[0]['messages'];
        self::assertStringStartsWith("Please review: echo 1\n\n[the assistant said]\nFocus: style", (string) $messages[array_key_last($messages)]['content']);
        self::assertSame('completed', $session->lastStatus());
    }

    public function testAPromptThatLacksAnArgumentIsAnErrorAndNoTurnRuns(): void
    {
        $this->harness(['fake' => ['command' => FakeMcpServer::argv('modern-full')]]);
        $provider = new CountingProvider(['local' => 'qwen'], ['native']);
        $session = new \Phycode\Cli\ChatSession($this->kernel($provider), 'local', Kit::agent(), Transcript::memory());

        $session->handle('/mcp__fake__review focus=style');

        self::assertSame([], $provider->sent);
        self::assertContains(['error', '/mcp__fake__review needs the argument code: /mcp__fake__review code=VALUE'], $this->notices());
    }

    public function testThePanelShowsTheConnectedServers(): void
    {
        $this->harness(['fake' => ['command' => FakeMcpServer::argv('modern-full')]]);
        $session = new \Phycode\Cli\ChatSession($this->kernel(new CountingProvider(['local' => 'qwen'])), 'local', Kit::agent(), Transcript::memory());

        $session->handle('/mcp');

        $list = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'list_panel'))[0];
        self::assertSame('MCP servers', $list[1]);
        self::assertSame('connected', $list[2][0]['title']);
        self::assertStringContainsString('the tools of the connected servers are offered to the model', (string) $list[3]);
    }
}
