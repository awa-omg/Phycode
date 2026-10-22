<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Memory\MemoryStore;
use Phycode\Security\Decision;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class MemorySessionTest extends TestCase
{
    private SessionHarness $harness;

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    private function harness(array $memory = []): SessionHarness
    {
        return $this->harness = new SessionHarness([
            'default_profile' => 'local',
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'native']],
            'memory' => $memory,
        ]);
    }

    private function noteCall(): \Phycode\Llm\LlmResponse
    {
        return ScriptedClient::call('memory_save', ['action' => 'save', 'scope' => 'project', 'name' => 'test-command', 'description' => 'How the tests run', 'body' => 'vendor/bin/phpunit']);
    }

    private function noteFile(): string
    {
        return $this->harness->cwd . '/.phycode/memory/test-command.md';
    }

    public function testWithAskTheUserApprovesTheNoteBeforeItIsSaved(): void
    {
        $this->harness(['autonomy' => 'ask']);
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];
        $spy = new CountingProvider(['local' => 'qwen'], ['native'], replies: [$this->noteCall(), ScriptedClient::text('saved')]);
        $session = $this->harness->session($this->harness->kernel($spy, $ui));

        $session->handle('remember how the tests run');

        self::assertSame('completed', $session->lastStatus());
        self::assertCount(1, $ui->requests);
        self::assertSame('memory_save', $ui->requests[0]->tool);
        self::assertFileExists($this->noteFile());
    }

    public function testWhenTheUserDeniesTheNoteIsNotSaved(): void
    {
        $this->harness(['autonomy' => 'ask']);
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];
        $spy = new CountingProvider(['local' => 'qwen'], ['native'], replies: [$this->noteCall(), ScriptedClient::text('ok, not saved')]);
        $session = $this->harness->session($this->harness->kernel($spy, $ui));

        $session->handle('remember how the tests run');

        self::assertFileDoesNotExist($this->noteFile());
    }

    public function testWithAutoTheNoteIsSavedWithoutAsking(): void
    {
        $this->harness(['autonomy' => 'auto']);
        $ui = new RecordingUi();
        $spy = new CountingProvider(['local' => 'qwen'], ['native'], replies: [$this->noteCall(), ScriptedClient::text('saved')]);
        $session = $this->harness->session($this->harness->kernel($spy, $ui));

        $session->handle('remember how the tests run');

        self::assertSame([], $ui->requests);
        self::assertFileExists($this->noteFile());
    }

    public function testTheNextSessionSeesTheIndexInItsSystemPromptAndTheTools(): void
    {
        $this->harness();
        $kernel = $this->harness->kernel(new RequestSpy(['local' => 'qwen']), new RecordingUi());
        self::assertStringNotContainsString('Notes from earlier sessions', $kernel->systemPrompt(Kit::agent(), 'native', Kit::policy()));

        $kernel->memory->save('project', 'test-command', 'How the tests run', 'x');
        $kernel->memory->save('user', 'language', 'Answer in Spanish', 'x');
        $prompt = $kernel->systemPrompt(Kit::agent(), 'native', Kit::policy());

        self::assertStringContainsString("This project:\n- test-command: How the tests run", $prompt);
        self::assertStringContainsString('- language: Answer in Spanish', $prompt);
        self::assertContains('memory', $kernel->tools->available(Kit::policy()));
        self::assertContains('memory_save', $kernel->tools->available(Kit::policy()));
    }

    public function testWithMemoryOffThereAreNoToolsAndNoIndex(): void
    {
        $this->harness(['enabled' => false]);
        $kernel = $this->harness->kernel(new RequestSpy(['local' => 'qwen']), new RecordingUi());
        $kernel->memory->save('project', 'test-command', 'How the tests run', 'x');

        self::assertNotContains('memory', $kernel->tools->available(Kit::policy()));
        self::assertNotContains('memory_save', $kernel->tools->available(Kit::policy()));
        self::assertStringNotContainsString('Notes from earlier sessions', $kernel->systemPrompt(Kit::agent(), 'native', Kit::policy()));
    }

    public function testTheMemoryCommandListsTheNotesAndForgetsOne(): void
    {
        $this->harness();
        $ui = new RecordingUi();
        $session = $this->harness->session($this->harness->kernel(new RequestSpy(['local' => 'qwen']), $ui));

        $session->handle('/memory');
        $session->handle('/memory forget project/nothing');
        $session->handle('/memory forget everything');

        $store = new MemoryStore($this->harness->cwd, dirname($this->harness->configFile));
        $store->save('project', 'test-command', 'How the tests run', 'x');
        $session->handle('/memory');
        $session->handle('/memory forget project/test-command');

        $panels = array_values(array_filter($ui->events, static fn (array $event): bool => $event[0] === 'panel'));
        $notices = array_values(array_map(static fn (array $event): string => $event[2], array_filter($ui->events, static fn (array $event): bool => $event[0] === 'notice')));
        self::assertStringContainsString('No notes yet', $panels[0][2]);
        self::assertStringContainsString("This project:\n  test-command: How the tests run", $panels[1][2]);
        self::assertContains('no note named project/nothing', $notices);
        self::assertContains('usage: /memory, or /memory forget project/NAME or /memory forget user/NAME', $notices);
        self::assertContains('forgot project/test-command', $notices);
        self::assertFileDoesNotExist($this->noteFile());
    }
}
