<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Llm\LlmException;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class ToolModeProbeTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen'],
            'fixed' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
            'cloud' => ['base_url' => 'https://cloud.example', 'model' => 'gpt', 'tool_mode' => 'none'],
        ],
    ];

    private const MODELS = ['local' => 'qwen', 'fixed' => 'qwen', 'cloud' => 'gpt'];

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

    public function testTheToolModeIsAutoAndNothingIsProbedBeforeATurnNeedsTools(): void
    {
        $provider = new CountingProvider(self::MODELS, ['native']);
        $session = $this->session($provider);

        self::assertSame('auto', $session->toolMode());
        self::assertStringContainsString('tools: auto', $session->banner());
        $session->handle('/status');

        self::assertSame(0, $provider->probes);
    }

    public function testAFixedToolModeIsTheModeOfTheSessionWithoutAnyTurn(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        $session->switchModel('fixed', null, 'off');

        self::assertSame('text', $session->toolMode());
    }

    public function testSwitchingTheModelProbesNothing(): void
    {
        $provider = new CountingProvider(self::MODELS, ['native']);
        $session = $this->session($provider);

        $session->switchModel('local', 'mistral', 'on');
        $session->switchModel('local', 'llama', 'off');

        self::assertSame(0, $provider->probes);
        self::assertSame('auto', $session->toolMode());
    }

    public function testAGreetingNeedsNoProbe(): void
    {
        $provider = new CountingProvider(self::MODELS, ['native'], replies: [ScriptedClient::text('hola!')]);
        $session = $this->session($provider);

        $session->handle('hola');

        self::assertSame('completed', $session->lastStatus());
        self::assertSame(0, $provider->probes);
        self::assertSame('auto', $session->toolMode());
    }

    public function testTheFirstTurnThatNeedsToolsProbesOnceAndLaterTurnsKeepTheAnswer(): void
    {
        $provider = new CountingProvider(self::MODELS, ['native'], replies: [
            ScriptedClient::text('one'),
            ScriptedClient::text('two'),
            ScriptedClient::text('three'),
        ]);
        $session = $this->session($provider);

        $session->handle('list the files');
        self::assertSame(['native', 1], [$session->toolMode(), $provider->probes]);

        $session->handle('and the tests');
        self::assertSame(['native', 1], [$session->toolMode(), $provider->probes], 'the answer is kept for the session');

        $session->switchModel('local', 'mistral', 'off');
        self::assertSame(['auto', 1], [$session->toolMode(), $provider->probes], 'a switch forgets the answer and asks nothing');

        $session->handle('and the notes');
        self::assertSame(['native', 2], [$session->toolMode(), $provider->probes], 'the first turn with the new model probes it');
    }

    public function testAFailedProbeLeavesTheModeUnresolvedAndTheNextTurnAsksAgain(): void
    {
        $provider = new CountingProvider(
            self::MODELS,
            [new LlmException('connection to the model server failed: refused'), 'text'],
            replies: [ScriptedClient::text('fine')],
        );
        $session = $this->session($provider);

        $session->handle('list the files');

        self::assertSame('error', $session->lastStatus());
        self::assertSame(['auto', 1], [$session->toolMode(), $provider->probes]);

        $session->handle('list the files again');

        self::assertSame('completed', $session->lastStatus());
        self::assertSame(['text', 2], [$session->toolMode(), $provider->probes]);
        self::assertSame(['system', 'user'], array_column($provider->sent[0]['messages'], 'role'), 'the failed turn left no message behind');
    }

    public function testAProfileThatCannotCallToolsIsNoticedByItsFirstTurnNotBySwitching(): void
    {
        $provider = new CountingProvider(self::MODELS, ['none'], replies: [ScriptedClient::text('plain')]);
        $session = $this->session($provider);

        $session->switchModel('cloud', null, 'off');

        self::assertNotContains('warn: profile cloud cannot call tools; the agent runs as a plain chat', $this->notices());

        $session->handle('list the files');

        self::assertContains('warn: profile cloud cannot call tools; the agent runs as a plain chat', $this->notices());
        self::assertSame('none', $session->toolMode());
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
