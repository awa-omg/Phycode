<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class BangShellTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text']],
    ];

    private SessionHarness $harness;
    private RecordingUi $ui;
    private CountingProvider $provider;
    private ChatSession $session;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
        $this->ui = new RecordingUi();
        $this->provider = new CountingProvider(['local' => 'qwen']);
        $this->session = $this->harness->session($this->harness->kernel($this->provider, $this->ui));
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testACommandShowsItsOutputAndAsksNothingFromTheModel(): void
    {
        $before = $this->session->contextState()['messages'];

        self::assertTrue($this->session->handle('!echo hello'));

        self::assertContains(['output', "\$ echo hello\nhello"], $this->ui->events);
        self::assertSame([], $this->provider->sent, 'the model is not asked');
        self::assertSame($before + 1, $this->session->contextState()['messages'], 'the output joins the conversation');
        self::assertSame('completed', $this->session->lastStatus());
    }

    public function testTwoBangsShowTheOutputAndKeepItFromTheConversation(): void
    {
        $before = $this->session->contextState()['messages'];

        $this->session->handle('!!echo private');

        self::assertContains(['output', "\$ echo private\nprivate"], $this->ui->events);
        self::assertSame($before, $this->session->contextState()['messages']);
    }

    public function testTheCommandRunsInTheProjectRoot(): void
    {
        $this->session->handle('!pwd');

        self::assertContains(['output', '$ pwd' . "\n" . realpath($this->harness->cwd)], $this->ui->events);
    }

    public function testAFailingCommandReportsItsExitCodeAndItsErrorOutput(): void
    {
        $this->session->handle('!echo oops >&2; exit 3');

        self::assertContains(['output', "\$ echo oops >&2; exit 3\noops\nexit code: 3"], $this->ui->events);
        self::assertSame('completed', $this->session->lastStatus(), 'a command that fails is not a failed turn');
    }

    public function testASecretThatTheCommandPrintsDoesNotGoToTheModel(): void
    {
        $this->session->handle('!echo "token sk-abcdefghijkl1234"');

        $shown = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'output'));
        self::assertStringContainsString('sk-abcdefghijkl1234', $shown[0][1], 'the person sees what they ran');
        $this->session->handle('hello');
        $sent = json_encode($this->provider->sent, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sk-abcdefghijkl1234', $sent);
        self::assertStringContainsString('token [redacted]', $sent);
    }

    public function testABangWithNoCommandIsAWarning(): void
    {
        $this->session->handle('!');
        $this->session->handle('!!  ');

        $notices = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice'));
        self::assertCount(2, $notices);
        self::assertSame('warn', $notices[0][1]);
        self::assertSame([], array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'output'));
    }

    public function testALongOutputIsCutToTheToolLimit(): void
    {
        $this->session->handle('!yes line | head -c 400000');

        $shown = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'output'));
        self::assertLessThan(200000, strlen($shown[0][1]));
        self::assertStringStartsWith('$ yes line', $shown[0][1]);
    }
}
