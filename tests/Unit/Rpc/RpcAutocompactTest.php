<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Session\SessionStore;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;

final class RpcAutocompactTest extends RpcTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ]]);
    }

    public function testTheContextEventFollowsTheAutocompactNoticeInsideTheTurn(): void
    {
        $this->savedLongSession('ses-long');
        $spy = new RequestSpy(['local' => 'qwen'], [], [ScriptedClient::text('the summary'), $this->endingInput(), ScriptedClient::text('Four.')]);

        $result = $this->play([
            ['type' => 'session_switch', 'id' => 'ses-long'],
            ['type' => 'set_context', 'max' => 8000],
            ['type' => 'input', 'text' => 'what is two plus two'],
        ], $spy);

        $types = self::framing($result['events']);
        self::assertSame(['turn_start', 'notice', 'context', 'turn_end'], array_slice($types, array_search('turn_start', $types, true)));
        self::assertStringStartsWith('autocompact: context at ', self::first($result['events'], 'notice')['message']);
        $contexts = self::of($result['events'], 'context');
        $context = $contexts[array_key_last($contexts)];
        self::assertSame([8000, 7800, 4, true], [$context['limit'], $context['threshold'], $context['messages'], $context['autoCompact']], 'the window, the threshold at the window less the buffer, the kept messages, and autocompaction on');
        self::assertSame(['type', 'used', 'limit', 'threshold', 'percent', 'messages', 'breakdown', 'autoCompact', 'autoCompactSwitch'], array_keys($context), 'the fields of /context');
        self::assertGreaterThanOrEqual(array_sum(array_column($context['breakdown'], 'tokens')), $context['used'], 'used counts every category, the tools too');
        self::assertCount(2, $spy->sent, 'the summary is asked for, then the turn');
        self::assertStringContainsString('question 7', (string) $spy->sent[0]['messages'][1]['content']);
        self::assertStringNotContainsString('question 8', (string) $spy->sent[0]['messages'][1]['content'], 'the last turn is kept');
    }

    public function testATurnWithoutAnAutocompactSendsNoNoticeAndNoContextEventAroundIt(): void
    {
        $spy = new RequestSpy(['local' => 'qwen'], [], [$this->endingInput(), ScriptedClient::text('Four.')]);

        $result = $this->play([['type' => 'input', 'text' => 'what is two plus two']], $spy);

        self::assertSame(['turn_start', 'turn_end'], self::framing($result['events']));
        self::assertCount(1, $spy->sent, 'no summary is asked for');
    }

    private static function framing(array $events): array
    {
        return array_values(array_filter(
            self::types($events),
            static fn (string $type): bool => in_array($type, ['turn_start', 'notice', 'context', 'turn_end'], true),
        ));
    }

    private function savedLongSession(string $id): void
    {
        $messages = [];
        for ($turn = 1; $turn <= 8; $turn++) {
            $messages[] = ['role' => 'user', 'content' => "question {$turn} " . str_repeat('q', 2400)];
            $messages[] = ['role' => 'assistant', 'content' => "answer {$turn} " . str_repeat('a', 2400)];
        }
        (new SessionStore($this->harness->cwd))->save([
            'id' => $id, 'title' => 'eight turns', 'createdAt' => SessionStore::now(), 'updatedAt' => SessionStore::now(),
            'profile' => 'local', 'model' => 'qwen', 'thinking' => 'off', 'messages' => $messages,
        ]);
    }
}
