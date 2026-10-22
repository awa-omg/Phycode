<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Session\SessionStore;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class ForkSessionTest extends TestCase
{
    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'native']]]);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    private function session(array $replies): \Phycode\Cli\ChatSession
    {
        return $this->harness->session($this->harness->kernel(new CountingProvider(['local' => 'qwen'], ['native'], replies: $replies), $this->ui));
    }

    private function notices(): array
    {
        return array_values(array_map(static fn (array $event): string => $event[1] . ': ' . $event[2], array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice')));
    }

    public function testAForkIsANewSessionWithTheSameMessagesAndTheOriginalStaysAsItWas(): void
    {
        $session = $this->session([ScriptedClient::text('First answer.'), ScriptedClient::text('Second answer.')]);
        $session->handle('first question');
        $original = $session->id();
        $store = new SessionStore($this->harness->cwd);
        $originalMessages = $store->load($original)['messages'];

        $session->handle('/fork');
        $forked = $session->id();
        $session->handle('second question');

        self::assertNotSame($original, $forked);
        self::assertSame($originalMessages, $store->load($original)['messages']);
        $copy = $store->load($forked);
        self::assertSame($original, $copy['parent']);
        self::assertSame(3, $copy['forkAt']);
        self::assertSame(['first question', 'First answer.', 'second question', 'Second answer.'], array_slice(array_column($copy['messages'], 'content'), 1));
        self::assertSame('first question', $copy['title']);
        self::assertContains("info: forked {$original} into {$forked}, keeping 1 of your messages; {$original} stays as it was", $this->notices());
    }

    public function testForkWithANumberKeepsOnlyThatManyOfTheUserMessages(): void
    {
        $session = $this->session([ScriptedClient::text('A1.'), ScriptedClient::text('A2.'), ScriptedClient::text('A3.')]);
        $session->handle('q1');
        $session->handle('q2');
        $session->handle('q3');

        $session->handle('/fork 2');

        self::assertSame(['q1', 'A1.', 'q2', 'A2.'], array_slice(array_column($session->messages(), 'content'), 1));
        self::assertSame(5, (new SessionStore($this->harness->cwd))->load($session->id())['forkAt']);
    }

    public function testABadNumberIsRefusedAndNothingChanges(): void
    {
        $session = $this->session([ScriptedClient::text('A1.')]);
        $session->handle('q1');
        $id = $session->id();

        $session->handle('/fork 0');
        $session->handle('/fork two');

        self::assertSame($id, $session->id());
        self::assertSame(2, count(array_filter($this->notices(), static fn (string $notice): bool => $notice === 'error: usage: /fork [N], where N is how many of your messages the new session keeps')));
    }

    public function testTheTreeShowsTheForkUnderItsParentAndMarksTheCurrentSession(): void
    {
        $session = $this->session([ScriptedClient::text('A1.')]);
        $session->handle('q1');
        $parent = $session->id();
        $session->handle('/fork');
        $child = $session->id();

        $session->handle('/tree');

        $panel = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'panel' && $event[1] === 'Sessions tree'))[0];
        self::assertSame("{$parent}  q1  (3 messages)\n└─ {$child}  q1  (3 messages)  <- current", $panel[2]);
    }

    public function testASwitchToAForkGoesOnWithItsParentLink(): void
    {
        $session = $this->session([ScriptedClient::text('A1.'), ScriptedClient::text('A2.')]);
        $session->handle('q1');
        $parent = $session->id();
        $session->handle('/fork');
        $child = $session->id();
        $session->handle("/sessions switch {$parent}");
        $session->handle("/sessions switch {$child}");
        $session->handle('q2');

        $saved = (new SessionStore($this->harness->cwd))->load($child);

        self::assertSame($parent, $saved['parent']);
        self::assertSame(3, $saved['forkAt']);
        self::assertNull((new SessionStore($this->harness->cwd))->load($parent)['parent'] ?? null);
    }

    public function testANewSessionHasNoParent(): void
    {
        $session = $this->session([ScriptedClient::text('A1.')]);
        $session->handle('q1');
        $session->handle('/fork');
        $session->handle('/sessions new');

        self::assertNull((new SessionStore($this->harness->cwd))->load($session->id())['parent'] ?? null);
    }
}
