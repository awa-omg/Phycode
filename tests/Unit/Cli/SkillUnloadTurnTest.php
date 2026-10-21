<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Llm\TextToolProtocol;
use Phycode\Session\SessionStore;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use Phycode\Tests\Unit\Gentle\FakeGentleAi;
use PHPUnit\Framework\TestCase;

final class SkillUnloadTurnTest extends TestCase
{
    private const LINE = '[skill go-testing unloaded to save context; load it again with skill(name) if needed]';
    private const BODY = 'Use table tests.';

    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text']], 'gentle_ai' => ['bin' => FakeGentleAi::install(['skills' => '[]'])]]);
        $this->ui = new RecordingUi();
        Kit::write(dirname($this->harness->configFile) . '/skills/go-testing/SKILL.md', "---\nname: go-testing\ndescription: Write Go tests.\n---\n" . self::BODY . "\n");
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    private function toolResult(array $messages, string $callId): ?string
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $callId) {
                return (string) $message['content'];
            }
        }

        return null;
    }

    private function requestText(array $request): string
    {
        return implode("\n", array_map(static fn (array $message): string => (string) ($message['content'] ?? ''), $request['messages']));
    }

    private function currentId(\Phycode\Cli\ChatSession $session): string
    {
        $current = array_values(array_filter($session->listSessions(), static fn (array $item): bool => $item['current']));

        return (string) $current[0]['id'];
    }

    public function testANativeUnloadIsInTheNextRequestInTheSavedSessionAndAfterAResume(): void
    {
        $spy = new ToolSpy('native', [
            ScriptedClient::call('skill', ['name' => 'go-testing'], 'call_1'),
            ScriptedClient::text('loaded'),
            ScriptedClient::call('skill', ['name' => 'go-testing', 'unload' => true], 'call_2'),
            ScriptedClient::text('unloaded'),
            ScriptedClient::text('next'),
            ScriptedClient::text('again'),
        ]);
        $session = $this->harness->session($this->harness->kernel($spy, $this->ui));

        $session->handle('use go-testing');
        self::assertStringContainsString(self::BODY, (string) $this->toolResult($spy->sent[1]['messages'], 'call_1'));
        $session->handle('done with it');
        $session->handle('what next');

        self::assertSame(self::LINE, $this->toolResult($spy->sent[4]['messages'], 'call_1'), 'the next request carries the line, not the file');
        self::assertSame(self::LINE, $this->toolResult($spy->sent[4]['messages'], 'call_2'));
        self::assertStringNotContainsString(self::BODY, $this->requestText($spy->sent[4]));

        $saved = (new SessionStore($this->harness->cwd))->load($this->currentId($session));
        self::assertSame(self::LINE, $this->toolResult($saved['messages'], 'call_1'), 'the saved session has the line');

        $resumed = $this->harness->session($this->harness->kernel($spy, $this->ui));
        $resumed->switchSession($this->currentId($session));
        $resumed->handle('again?');
        self::assertStringNotContainsString(self::BODY, $this->requestText($spy->sent[5]), 'after a resume the skill is still unloaded');
    }

    public function testATextModeUnloadReplacesTheProtocolResultInTheNextRequest(): void
    {
        $spy = new ToolSpy('text', [
            ScriptedClient::text("<tool_call>\n{\"name\": \"skill\", \"arguments\": {\"name\": \"go-testing\"}}\n</tool_call>"),
            ScriptedClient::text('loaded'),
            ScriptedClient::text("<tool_call>\n{\"name\": \"skill\", \"arguments\": {\"name\": \"go-testing\", \"unload\": true}}\n</tool_call>"),
            ScriptedClient::text('unloaded'),
            ScriptedClient::text('next'),
        ]);
        $session = $this->harness->session($this->harness->kernel($spy, $this->ui));

        $session->handle('use go-testing');
        $session->handle('done with it');
        $session->handle('what next');

        $text = $this->requestText($spy->sent[4]);
        self::assertStringContainsString(TextToolProtocol::formatResult('skill', self::LINE), $text);
        self::assertStringNotContainsString(self::BODY, $text);
    }

    public function testAnUnloadWithNoEarlierLoadIsAnOkResult(): void
    {
        $spy = new ToolSpy('native', [
            ScriptedClient::call('skill', ['name' => 'go-testing', 'unload' => true], 'call_1'),
            ScriptedClient::text('done'),
        ]);
        $session = $this->harness->session($this->harness->kernel($spy, $this->ui));

        $session->handle('unload go-testing');

        self::assertSame(self::LINE, $this->toolResult($spy->sent[1]['messages'], 'call_1'));
        self::assertSame('completed', $session->lastStatus());
    }
}
