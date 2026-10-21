<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Llm\LlmException;
use Phycode\Session\SessionStore;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;

final class RpcTurnFrameTest extends RpcTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ]]);
    }

    public function testInitIsFramedAsATurnAndAsksForTheInitPrompt(): void
    {
        $spy = new RequestSpy(['local' => 'qwen'], [], [$this->endingInput(), ScriptedClient::text('AGENTS.md is written')]);

        $result = $this->play([['type' => 'input', 'text' => '/init']], $spy);

        $types = self::types($result['events']);
        self::assertSame(['turn_start', 'turn_end'], array_values(array_intersect($types, ['turn_start', 'turn_end'])));
        self::assertSame('completed', self::first($result['events'], 'turn_end')['status']);
        self::assertSame([], self::of($result['events'], 'panel'), 'the answer of /init is the reply of the model');
        self::assertStringStartsWith('Explore this project', array_slice($spy->sent[0]['messages'], -1)[0]['content']);
    }

    public function testACommandThatCallsNoModelHasNoTurnEvents(): void
    {
        $result = $this->play([['type' => 'input', 'text' => '/status']], new RequestSpy(['local' => 'qwen']));

        self::assertSame([], array_values(array_intersect(self::types($result['events']), ['turn_start', 'turn_end'])));
        self::assertSame('Status', self::first($result['events'], 'panel')['title']);
    }

    public function testTheCompactLineIsFramedAndTheContextFollowsItsTurn(): void
    {
        $this->savedSession('ses-line');
        $spy = new RequestSpy(['local' => 'qwen'], [], [$this->endingInput(), ScriptedClient::text('a summary of the first question')]);

        $result = $this->play([['type' => 'session_switch', 'id' => 'ses-line'], ['type' => 'input', 'text' => '/compact']], $spy);

        self::assertSame(
            ['ready', 'sessions', 'model_changed', 'effort', 'context', 'turn_start', 'panel', 'notice', 'turn_end', 'context', 'bye'],
            self::types($result['events']),
        );
        self::assertSame('compact', self::first($result['events'], 'panel')['title']);
    }

    public function testTheCompactCommandTakesItsInstructionsIntoTheSummary(): void
    {
        $this->savedSession('ses-instructions');
        $spy = new RequestSpy(['local' => 'qwen'], [], [$this->endingInput(), ScriptedClient::text('a summary')]);

        $result = $this->play([
            ['type' => 'session_switch', 'id' => 'ses-instructions'],
            ['type' => 'compact', 'instructions' => 'keep the failing tests'],
        ], $spy);

        self::assertSame('completed', self::first($result['events'], 'turn_end')['status']);
        self::assertStringContainsString('Focus the summary on this: keep the failing tests', (string) $spy->sent[0]['messages'][1]['content']);
    }

    public function testCompactInstructionsThatAreNotTextAreANotice(): void
    {
        $result = $this->play([['type' => 'compact', 'instructions' => 5]], new RequestSpy(['local' => 'qwen']), fn () => ($this->endingInput())());

        self::assertSame(['ready', 'notice', 'bye'], self::types($result['events']));
        self::assertSame('compact needs instructions: a text, or null', $result['events'][1]['message']);
    }

    public function testACompactionThatTheEndOfTheInputCutsShortIsCancelledWithoutANotice(): void
    {
        $this->savedSession('ses-cut');
        $spy = new RequestSpy(['local' => 'qwen'], [], [$this->endingInput(), new LlmException('the model is gone')]);

        $result = $this->play([['type' => 'session_switch', 'id' => 'ses-cut'], ['type' => 'compact']], $spy);

        self::assertSame(['ready', 'sessions', 'model_changed', 'effort', 'context', 'turn_start', 'turn_end', 'context', 'bye'], self::types($result['events']));
        $end = self::first($result['events'], 'turn_end');
        self::assertSame(['cancelled', null], [$end['status'], $end['reason']]);
        self::assertSame(7, $this->session->contextState()['messages'], 'the system message and the six messages of the session');
    }

    private function savedSession(string $id): void
    {
        (new SessionStore($this->harness->cwd))->save([
            'id' => $id, 'title' => 'three turns', 'createdAt' => SessionStore::now(), 'updatedAt' => SessionStore::now(),
            'profile' => 'local', 'model' => 'qwen', 'thinking' => 'off',
            'messages' => [
                ['role' => 'user', 'content' => 'the first question'],
                ['role' => 'assistant', 'content' => 'the first answer'],
                ['role' => 'user', 'content' => 'the second question'],
                ['role' => 'assistant', 'content' => 'the second answer'],
                ['role' => 'user', 'content' => 'the third question'],
                ['role' => 'assistant', 'content' => 'the third answer'],
            ],
        ]);
    }
}
