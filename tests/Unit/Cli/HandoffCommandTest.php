<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Security\Decision;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class HandoffCommandTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
        ],
    ];

    private const MODELS = ['local' => 'qwen'];
    private const HANDOFF = "## Goal\nfinish the parser";

    private SessionHarness $harness;
    private RecordingUi $ui;
    private string $root;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
        $this->ui = new RecordingUi();
        $this->root = (string) realpath($this->harness->cwd);
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testAYesSavesTheHandoffAndStartsANewSessionThatBeginsWithIt(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [
            ScriptedClient::text('answer one'),
            ScriptedClient::text(self::HANDOFF),
            ScriptedClient::text('continuing'),
        ]);
        $this->ui->decisions = [Decision::AllowOnce];
        $session = $this->session($provider);
        $session->handle('question one');
        $old = $this->currentId($session);

        $session->handle('/handoff');

        self::assertStringContainsString('question one', (string) $provider->sent[1]['messages'][1]['content'], 'the handoff is written from the conversation');
        self::assertContains(['panel', 'Handoff', self::HANDOFF], $this->ui->events, 'the handoff is shown before the question');
        self::assertContains(['confirm', 'phycode', 'phycode'], $this->ui->events);
        self::assertSame(self::HANDOFF . "\n", file_get_contents($this->root . "/.phycode/handoffs/{$old}.md"));

        $new = $this->currentId($session);
        self::assertNotSame($old, $new, 'a new session is current');
        self::assertContains("info: started session {$new} with the handoff from {$old}, saved in .phycode/handoffs/{$old}.md", $this->notices());
        self::assertSame(2, $session->contextState()['messages'], 'the system message and the handoff');

        $session->handle('continue');

        $messages = $provider->sent[2]['messages'];
        self::assertSame(['system', 'user', 'user'], array_column($messages, 'role'));
        self::assertSame(self::HANDOFF, $messages[1]['content'], 'the handoff is the first user message of the new session');
        self::assertSame('continue', $messages[2]['content']);
    }

    public function testANoSavesNothingAndTheSessionGoesOn(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('answer one'), ScriptedClient::text(self::HANDOFF)]);
        $this->ui->decisions = [Decision::Deny];
        $session = $this->session($provider);
        $session->handle('question one');
        $id = $this->currentId($session);

        $session->handle('/handoff');

        self::assertContains(['panel', 'Handoff', self::HANDOFF], $this->ui->events);
        self::assertDirectoryDoesNotExist($this->root . '/.phycode/handoffs');
        self::assertSame($id, $this->currentId($session), 'the session does not change');
        self::assertSame(3, $session->contextState()['messages'], 'the system message, the question and the answer');
        self::assertContains('info: nothing was saved; the session goes on', $this->notices());
    }

    public function testWithoutATerminalNobodyIsAskedAndNothingIsSaved(): void
    {
        $this->ui->terminal = false;
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('answer one'), ScriptedClient::text(self::HANDOFF)]);
        $session = $this->session($provider);
        $session->handle('question one');
        $id = $this->currentId($session);

        $session->handle('/handoff');

        self::assertSame([], array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'confirm')));
        self::assertDirectoryDoesNotExist($this->root . '/.phycode/handoffs');
        self::assertSame($id, $this->currentId($session));
    }

    public function testAHandoffCannotStartDuringATurnAndTheTurnGoesOn(): void
    {
        $session = null;
        $provider = new CountingProvider(self::MODELS, replies: [
            static function () use (&$session): void {
                $session?->handle('/handoff');
            },
            ScriptedClient::text('done'),
        ]);
        $session = $this->session($provider);

        $session->handle('a question');

        self::assertSame(['error: a turn is running; try again when it finishes'], $this->notices());
        self::assertSame('completed', $session->lastStatus());
        self::assertCount(1, $provider->sent, 'the refused handoff asks the model for nothing');
        self::assertDirectoryDoesNotExist($this->root . '/.phycode/handoffs');
    }

    public function testAnEmptySessionHasNothingToHandOff(): void
    {
        $provider = new CountingProvider(self::MODELS);
        $session = $this->session($provider);

        $session->handle('/handoff');

        self::assertSame(['info: there is nothing to hand off yet: the conversation has no messages'], $this->notices());
        self::assertSame([], $provider->sent);
    }

    public function testAnEmptyHandoffFromTheModelIsRefusedAndNothingIsSaved(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('answer one'), ScriptedClient::text('   ')]);
        $this->ui->decisions = [Decision::AllowOnce];
        $session = $this->session($provider);
        $session->handle('question one');

        $session->handle('/handoff');

        self::assertContains('error: turn ended (error): the model gave an empty handoff; nothing was saved', $this->notices());
        self::assertSame('error', $session->lastStatus());
        self::assertSame([], array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'confirm')), 'there is nothing to approve');
        self::assertDirectoryDoesNotExist($this->root . '/.phycode/handoffs');
    }

    public function testHandoffIsACommandOnlyWhenItStandsAlone(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        self::assertTrue($session->isCommand('/handoff'));
        self::assertFalse($session->isCommand('/handoff now'));
        self::assertContains('/handoff', array_column($session->commands(), 'name'));
    }

    private function session(CountingProvider $provider): ChatSession
    {
        return $this->harness->session($this->harness->kernel($provider, $this->ui));
    }

    private function currentId(ChatSession $session): string
    {
        foreach ($session->listSessions() as $saved) {
            if ($saved['current']) {
                return $saved['id'];
            }
        }
        self::fail('no current session is saved');
    }

    private function notices(): array
    {
        return array_values(array_map(
            static fn (array $event): string => $event[1] . ': ' . $event[2],
            array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice'),
        ));
    }
}
