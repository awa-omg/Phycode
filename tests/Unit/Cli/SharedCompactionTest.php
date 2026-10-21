<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Session\SessionStore;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class SharedCompactionTest extends TestCase
{
    private const MODELS = ['local' => 'qwen'];

    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    private function session(CountingProvider $provider, array $profile = [], string $mode = 'native'): ChatSession
    {
        $this->harness = new SessionHarness([
            'default_profile' => 'local',
            'profiles' => ['local' => $profile + ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => $mode, 'context_limit' => 4096, 'compact_mode' => 'shared']],
        ]);
        $this->ui = new RecordingUi();

        return $this->harness->session($this->harness->kernel($provider, $this->ui));
    }

    private function threeTurns(array $after, array $profile = [], string $mode = 'native'): array
    {
        $provider = new CountingProvider(self::MODELS, [$mode], replies: [ScriptedClient::text('first answer'), ScriptedClient::text('second answer'), ScriptedClient::text('third answer'), ...$after]);
        $session = $this->session($provider, $profile, $mode);
        $long = str_repeat('x', 6000);
        $session->handle("first {$long}");
        $session->handle("second {$long}");
        $session->handle('third question');

        return [$session, $provider];
    }

    private static function history(): array
    {
        $long = str_repeat('x', 6000);

        return [
            ['role' => 'user', 'content' => "first {$long}"],
            ['role' => 'assistant', 'content' => "first answer {$long}"],
            ['role' => 'user', 'content' => "second {$long}"],
            ['role' => 'assistant', 'content' => "second answer {$long}"],
            ['role' => 'user', 'content' => 'third question'],
            ['role' => 'assistant', 'content' => 'third answer'],
        ];
    }

    private function load(ChatSession $session): void
    {
        (new SessionStore($this->harness->cwd))->save([
            'id' => 'ses-history', 'title' => 'history', 'createdAt' => SessionStore::now(), 'updatedAt' => SessionStore::now(),
            'profile' => 'local', 'model' => 'qwen', 'thinking' => 'off',
            'messages' => self::history(),
        ]);
        $session->switchSession('ses-history');
    }

    public function testTheSummaryIsAskedOnTopOfTheConversationWithTheSameStartAsATurn(): void
    {
        [$session, $provider] = $this->threeTurns([ScriptedClient::text('GOAL: ship it')]);
        $turn = $provider->sent[2];

        $session->compact();

        $ask = $provider->sent[3];
        self::assertSame($turn['tools'], $ask['tools'], 'the same tool specs as a turn, so the tools stay cached');
        self::assertNotSame([], $ask['tools']);
        self::assertSame($turn['messages'][0], $ask['messages'][0], 'the same system message');
        self::assertSame(['system', 'user', 'assistant', 'user', 'assistant', 'user'], array_column($ask['messages'], 'role'), 'the older turns as they were, and then the ask');
        self::assertSame(array_slice($turn['messages'], 0, 5), array_slice($ask['messages'], 0, 5), 'the start of the request is the start of the conversation');
        self::assertStringEndsWith(str_repeat('x', 6000), (string) $ask['messages'][1]['content'], 'in full, not clipped');
        self::assertStringStartsWith('Stop here, and do not call any tool.', (string) $ask['messages'][5]['content']);
        self::assertStringContainsString('Goal, Progress, Decisions, Files touched, Pending', (string) $ask['messages'][5]['content']);
        self::assertSame($turn['cacheKey'], $ask['cacheKey'], 'routed with the same key as the conversation');
        self::assertNotNull($ask['cacheKey']);
        self::assertSame("Summary of the earlier conversation:\nGOAL: ship it", $this->summaryMessage($session));
    }

    private function summaryMessage(ChatSession $session): string
    {
        $property = (new \ReflectionClass($session))->getProperty('conversation');

        return (string) $property->getValue($session)->messages()[1]['content'];
    }

    public function testTheInstructionsOfTheCommandFocusTheSummary(): void
    {
        [$session, $provider] = $this->threeTurns([ScriptedClient::text('a summary')]);

        $session->compact('keep the names of the failing tests');

        $ask = (string) $provider->sent[3]['messages'][5]['content'];
        self::assertStringEndsWith("\nFocus the summary on this: keep the names of the failing tests", $ask);
    }

    public function testAReplyThatCallsAToolIsAskedAgainAsARequestOfItsOwn(): void
    {
        [$session, $provider] = $this->threeTurns([ScriptedClient::call('read', ['path' => 'a.txt']), ScriptedClient::text('the plain summary')]);

        $session->compact();

        self::assertCount(5, $provider->sent);
        self::assertSame(['system', 'user', 'assistant', 'user', 'assistant', 'user'], array_column($provider->sent[3]['messages'], 'role'));
        self::assertSame(['system', 'user'], array_column($provider->sent[4]['messages'], 'role'), 'the request of its own: the summariser, and the older turns as text');
        self::assertSame([], $provider->sent[4]['tools']);
        self::assertSame("Summary of the earlier conversation:\nthe plain summary", $this->summaryMessage($session));
    }

    public function testAnErrorOfTheServerOnTheSharedRequestIsAskedAgainAlone(): void
    {
        [$session, $provider] = $this->threeTurns([new \Phycode\Llm\LlmException('HTTP 400: the messages do not alternate'), ScriptedClient::text('the plain summary')]);

        $session->compact();

        self::assertCount(5, $provider->sent);
        self::assertSame(['system', 'user'], array_column($provider->sent[4]['messages'], 'role'));
        self::assertSame("Summary of the earlier conversation:\nthe plain summary", $this->summaryMessage($session));
    }

    public function testBeforeATurnHasFixedTheToolsThereIsNothingToShareAndTheRequestIsAlone(): void
    {
        $provider = new CountingProvider(self::MODELS, ['native'], replies: [ScriptedClient::text('the plain summary')]);
        $session = $this->session($provider);
        $this->load($session);

        $session->compact();

        self::assertCount(1, $provider->sent);
        self::assertSame(['system', 'user'], array_column($provider->sent[0]['messages'], 'role'));
    }

    public function testALocalProfileOnAutoKeepsTheRequestOfItsOwn(): void
    {
        [$session, $provider] = $this->threeTurns([ScriptedClient::text('the plain summary')], ['compact_mode' => 'auto']);

        $session->compact();

        self::assertSame(['system', 'user'], array_column($provider->sent[3]['messages'], 'role'), 'a 127.0.0.1 server is local, and a small model follows the summariser better');
    }

    public function testAHostedProfileOnAutoCompactsShared(): void
    {
        [$session, $provider] = $this->threeTurns([ScriptedClient::text('a summary')], ['compact_mode' => 'auto', 'base_url' => 'https://api.example-llm.com']);

        $session->compact();

        self::assertCount(6, $provider->sent[3]['messages']);
    }

    public function testTheProfileCanForceTheRequestOfItsOwn(): void
    {
        [$session, $provider] = $this->threeTurns([ScriptedClient::text('the plain summary')], ['compact_mode' => 'standalone', 'base_url' => 'https://api.example-llm.com']);

        $session->compact();

        self::assertSame(['system', 'user'], array_column($provider->sent[3]['messages'], 'role'));
    }

    public function testInTextModeTheSystemPromptOfTheAskIsTheOneOfTheTurns(): void
    {
        [$session, $provider] = $this->threeTurns([ScriptedClient::text('a summary')], [], 'text');
        $turn = $provider->sent[2];

        $session->compact();

        self::assertSame([], $provider->sent[3]['tools'], 'text mode lists the tools in the system prompt');
        self::assertStringContainsString('tool_call', (string) $provider->sent[3]['messages'][0]['content']);
        self::assertSame($turn['messages'][0]['content'], $provider->sent[3]['messages'][0]['content']);
    }

    public function testTheHandoffIsAskedTheSameWay(): void
    {
        [$session, $provider] = $this->threeTurns([ScriptedClient::text('HANDOFF TEXT')]);
        $this->ui->decisions = [\Phycode\Security\Decision::Deny];

        $session->handle('/handoff');

        $ask = $provider->sent[3];
        self::assertCount(8, $ask['messages'], 'the whole conversation, then the ask');
        self::assertStringStartsWith('Stop here, and do not call any tool.', (string) $ask['messages'][7]['content']);
        self::assertStringContainsString('This summary is the handoff to a new session', (string) $ask['messages'][7]['content']);
        self::assertStringNotContainsString('Focus the summary on this: This summary', (string) $ask['messages'][7]['content']);
    }
}
