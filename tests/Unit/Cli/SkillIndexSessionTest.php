<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use Phycode\Tests\Unit\Gentle\FakeGentleAi;
use PHPUnit\Framework\TestCase;

final class SkillIndexSessionTest extends TestCase
{
    private const BASE = [
        'default_profile' => 'local',
        'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text']],
    ];

    private SessionHarness $harness;
    private RecordingUi $ui;
    private string $bin;

    protected function setUp(): void
    {
        $pdf = Kit::folder('index-session-gentle') . '/pdf/SKILL.md';
        Kit::write($pdf, "---\nname: pdf\ndescription: PDFs.\n---\nFrom gentle-ai.\n");
        $this->bin = FakeGentleAi::install(['skills' => json_encode([['name' => 'pdf', 'scope' => 'user', 'description' => 'PDFs.', 'path' => $pdf]], JSON_THROW_ON_ERROR)]);
        $this->harness = new SessionHarness(self::BASE + ['gentle_ai' => ['bin' => $this->bin]]);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    private function configure(array $settings): void
    {
        file_put_contents($this->harness->configFile, json_encode($settings + self::BASE + ['gentle_ai' => ['bin' => $this->bin]], JSON_THROW_ON_ERROR));
    }

    private function system(array $messages): string
    {
        return (string) $messages[0]['content'];
    }

    public function testTheListingIsAskedWhenTheConversationStartsAndAgainOnlyWhenSkillsAreListed(): void
    {
        $spy = new ToolSpy('native', [ScriptedClient::text('one'), ScriptedClient::text('two'), ScriptedClient::text('three')]);
        $session = $this->harness->session($this->harness->kernel($spy, $this->ui));
        self::assertCount(1, FakeGentleAi::calls($this->bin), 'the conversation starts with one listing');

        $session->handle('hello');
        $session->handle('how are you');
        self::assertCount(1, FakeGentleAi::calls($this->bin), 'a turn does not ask again');
        self::assertStringContainsString("Available skills (load one with skill(name)", $this->system($spy->sent[0]['messages']));
        self::assertStringContainsString('- pdf: PDFs.', $this->system($spy->sent[1]['messages']));

        $session->handle('/skill');
        self::assertCount(2, FakeGentleAi::calls($this->bin), '/skill reads the listing again');

        $session->handle('thanks');
        self::assertCount(2, FakeGentleAi::calls($this->bin));
        self::assertStringContainsString('- pdf: PDFs.', $this->system($spy->sent[2]['messages']), 'the prompt lists what /skill just read');
    }

    public function testWithTheIndexOffNothingIsListedAndGentleAiIsNotAsked(): void
    {
        $this->configure(['skills' => ['index' => 'off']]);
        $spy = new ToolSpy('native', [ScriptedClient::text('one')]);
        $session = $this->harness->session($this->harness->kernel($spy, $this->ui));

        $session->handle('hello');

        self::assertCount(0, FakeGentleAi::calls($this->bin));
        self::assertStringNotContainsString('Available skills', $this->system($spy->sent[0]['messages']));
    }

    public function testWithoutTheSkillToolThereIsNoListingAndNoCall(): void
    {
        $this->configure(['skills' => ['enabled' => false]]);
        $spy = new ToolSpy('native', [ScriptedClient::text('one')]);
        $session = $this->harness->session($this->harness->kernel($spy, $this->ui));

        $session->handle('hello');

        self::assertCount(0, FakeGentleAi::calls($this->bin), 'nothing would list the skills, so gentle-ai is not asked');
        self::assertStringNotContainsString('Available skills', $this->system($spy->sent[0]['messages']));
    }
}
