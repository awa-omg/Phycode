<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Security\Decision;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use Phycode\Tests\Unit\Gentle\FakeGentleAi;
use PHPUnit\Framework\TestCase;

final class SkillManageTurnTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ],
    ];

    private SessionHarness $harness;
    private RecordingUi $ui;

    private string $bin;

    protected function setUp(): void
    {
        $this->bin = FakeGentleAi::install(['skills' => '[]']);
        $this->harness = new SessionHarness(self::CONFIG + ['gentle_ai' => ['bin' => $this->bin]]);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testASkillSavedInOneTurnIsLoadedAndListedInTheNextWithoutARestart(): void
    {
        $this->configure(['skills' => ['autonomy' => 'auto']]);
        $spy = new ToolSpy('native', [
            ScriptedClient::call('skill_manage', ['action' => 'create', 'name' => 'go-testing', 'description' => 'Write Go tests.', 'body' => "Use table tests.\n"]),
            ScriptedClient::text('saved'),
            ScriptedClient::call('skill', ['name' => 'go-testing'], 'call_2'),
            ScriptedClient::text('loaded'),
        ]);
        $session = $this->harness->session($this->harness->kernel($spy, $this->ui));

        $session->handle('save how we write Go tests');
        $session->handle('follow the go-testing skill');

        self::assertSame([], $this->ui->requests, 'with skills.autonomy auto the save runs without asking, and reading a skill never asks');
        self::assertStringStartsWith('created ', $this->lastToolResult($spy->sent[1]['messages']));
        self::assertStringContainsString('Use table tests.', $this->lastToolResult($spy->sent[3]['messages']));
        self::assertStringContainsString('- go-testing [own]: Write Go tests.', (string) $spy->sent[2]['messages'][0]['content'], 'the next prompt lists the skill that was just saved');

        $session->handle('/skill');
        $listed = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'list_panel' && $event[1] === 'Skills'));
        self::assertSame([['phycode', 'go-testing']], array_map(static fn (array $item): array => [$item['category'], $item['title']], $listed[0][2]));
    }

    public function testWithSkillsAutonomyAskTheSaveIsAskedFirstAndADenialWritesNothing(): void
    {
        $this->configure(['skills' => ['autonomy' => 'ask']]);
        $this->ui->decisions = [Decision::Deny];
        $spy = new ToolSpy('native', [
            ScriptedClient::call('skill_manage', ['action' => 'create', 'name' => 'go-testing', 'description' => 'Write Go tests.', 'body' => "Use table tests.\n"]),
            ScriptedClient::text('not saved'),
        ]);
        $session = $this->harness->session($this->harness->kernel($spy, $this->ui));

        $session->handle('save how we write Go tests');

        self::assertSame(['skill_manage'], array_column($this->ui->requests, 'tool'));
        self::assertNotNull($this->ui->requests[0]->preview, 'the approval shows the SKILL.md');
        self::assertStringStartsWith('ERROR: permission denied: the user denied this action', $this->lastToolResult($spy->sent[1]['messages']));
        self::assertDirectoryDoesNotExist(dirname($this->harness->configFile) . '/skills/go-testing');
    }

    private function configure(array $settings): void
    {
        file_put_contents($this->harness->configFile, json_encode($settings + self::CONFIG + ['gentle_ai' => ['bin' => $this->bin]], JSON_THROW_ON_ERROR));
    }

    private function lastToolResult(array $messages): string
    {
        $results = array_values(array_filter($messages, static fn (array $message): bool => ($message['role'] ?? null) === 'tool'));

        return (string) $results[array_key_last($results)]['content'];
    }
}
