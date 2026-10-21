<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class InitCommandTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
        ],
    ];

    private const MODELS = ['local' => 'qwen'];

    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testInitIsANormalTurnThatAsksForAgentsMd(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('Wrote AGENTS.md.')]);
        $session = $this->session($provider);

        self::assertTrue($session->handle('/init'));

        self::assertSame('completed', $session->lastStatus());
        self::assertSame(['system', 'user'], array_column($provider->sent[0]['messages'], 'role'));
        self::assertSame(ChatSession::INIT_PROMPT, $provider->sent[0]['messages'][1]['content']);
        self::assertStringContainsString('AGENTS.md', ChatSession::INIT_PROMPT);
    }

    public function testWhenAgentsMdExistsInitAsksForAChangeAndNotAnOverwrite(): void
    {
        $root = (string) realpath($this->harness->cwd);
        file_put_contents($root . '/AGENTS.md', "# Old\n");
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('Proposed a change.')]);
        $session = $this->session($provider);

        $session->handle('/init');

        self::assertSame(ChatSession::INIT_PROMPT . ' ' . ChatSession::INIT_UPDATE, $provider->sent[0]['messages'][1]['content']);
        self::assertStringContainsString('do not overwrite it', ChatSession::INIT_UPDATE);
        self::assertSame("# Old\n", file_get_contents($root . '/AGENTS.md'));
    }

    public function testTheWriteNeedsApprovalAndIsNotDoneWhenItIsDenied(): void
    {
        $root = (string) realpath($this->harness->cwd);
        $provider = new CountingProvider(self::MODELS, ['native'], replies: [
            ScriptedClient::call('write', ['path' => 'AGENTS.md', 'content' => "# New\n"]),
            ScriptedClient::text('I did not write it.'),
        ]);
        $session = $this->session($provider);

        $session->handle('/init');

        $confirms = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'confirm'));
        self::assertSame([['confirm', 'write', 'build']], $confirms);
        self::assertFileDoesNotExist($root . '/AGENTS.md');
    }

    public function testInitCannotStartDuringATurnAndTheTurnGoesOn(): void
    {
        $session = null;
        $provider = new CountingProvider(self::MODELS, replies: [
            static function () use (&$session): void {
                $session?->handle('/init');
            },
            ScriptedClient::text('done'),
        ]);
        $session = $this->session($provider);

        $session->handle('a question');

        self::assertSame(['error: a turn is running; try again when it finishes'], $this->notices());
        self::assertSame('completed', $session->lastStatus());
    }

    public function testInitIsACommandOnlyWhenItStandsAlone(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        self::assertTrue($session->isCommand('/init'));
        self::assertFalse($session->isCommand('/init now'));
    }

    private function session(CountingProvider $provider): ChatSession
    {
        return $this->harness->session($this->harness->kernel($provider, $this->ui));
    }

    private function notices(): array
    {
        return array_values(array_map(
            static fn (array $event): string => $event[1] . ': ' . $event[2],
            array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice'),
        ));
    }
}
