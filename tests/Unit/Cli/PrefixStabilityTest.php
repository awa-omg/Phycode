<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class PrefixStabilityTest extends TestCase
{
    private SessionHarness $harness;

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    private function session(string $mode, array $replies): array
    {
        $this->harness = new SessionHarness([
            'default_profile' => 'local',
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => $mode, 'context_limit' => 8192]],
        ]);
        $provider = new CountingProvider(['local' => 'qwen'], [$mode], replies: $replies);

        return [$this->harness->session($this->harness->kernel($provider, new RecordingUi())), $provider];
    }

    public function testEveryRequestOfAConversationStartsAsThePreviousOneDid(): void
    {
        [$session, $provider] = $this->session('native', [ScriptedClient::text('one'), ScriptedClient::text('two'), ScriptedClient::text('three'), ScriptedClient::text('four')]);

        $session->handle('question one');
        $session->handle('/help');
        $session->handle('/status');
        $session->handle('question two');
        $session->handle('/effort high');
        $session->handle('/context');
        $session->handle('question three');
        $session->handle('/reload');
        $session->handle('question four');

        self::assertCount(4, $provider->sent);
        for ($i = 1; $i < 4; $i++) {
            $before = $provider->sent[$i - 1];
            $now = $provider->sent[$i];
            self::assertSame($before['tools'], $now['tools'], "request {$i}: the tools are the same");
            self::assertSame($before['messages'][0], $now['messages'][0], "request {$i}: so is the system message");
            self::assertSame($before['messages'], array_slice($now['messages'], 0, count($before['messages'])), "request {$i}: the messages before it are not rewritten");
            self::assertSame($before['cacheKey'], $now['cacheKey'], "request {$i}: the conversation has one key");
        }
        self::assertNotSame([], $provider->sent[0]['tools']);
    }

    public function testAGreetingInTheMiddleOfAConversationKeepsTheTools(): void
    {
        [$session, $provider] = $this->session('native', [ScriptedClient::text('one'), ScriptedClient::text('de nada'), ScriptedClient::text('three')]);

        $session->handle('question one');
        $session->handle('gracias');
        $session->handle('question three');

        self::assertSame($provider->sent[0]['tools'], $provider->sent[1]['tools'], 'a thanks is a turn like the others');
        self::assertSame($provider->sent[1]['tools'], $provider->sent[2]['tools']);
        self::assertSame(1, $provider->probes, 'and the mode was probed once');
    }

    public function testAGreetingThatOpensTheConversationStillRunsWithoutTools(): void
    {
        [$session, $provider] = $this->session('native', [ScriptedClient::text('hola!')]);

        $session->handle('hola');

        self::assertSame([], $provider->sent[0]['tools']);
        self::assertSame(0, $provider->probes, 'it needs no probe');
    }

    public function testInTextModeTheSystemMessageAndTheToolListingDoNotChangeEither(): void
    {
        [$session, $provider] = $this->session('text', [ScriptedClient::text('one'), ScriptedClient::text('two'), ScriptedClient::text('three')]);

        $session->handle('question one');
        $session->handle('gracias');
        $session->handle('question three');

        self::assertSame($provider->sent[0]['messages'][0], $provider->sent[1]['messages'][0]);
        self::assertSame($provider->sent[1]['messages'][0], $provider->sent[2]['messages'][0]);
        self::assertStringContainsString('tool_call', (string) $provider->sent[0]['messages'][0]['content']);
    }
}
