<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class KernelCommandsTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ],
    ];

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

    public function testReviewIsRegisteredNextToTheGentleCommands(): void
    {
        $names = array_column($this->harness->kernel(new RequestSpy(['local' => 'qwen']), $this->ui)->commands->commands(), 'name');

        self::assertContains('/review [base-ref]', $names);
        self::assertContains('/persona [gentleman|neutral|off]', $names);
    }

    public function testReviewModeStatusIsAnsweredByTheSession(): void
    {
        $this->harness->session($this->harness->kernel(new RequestSpy(['local' => 'qwen']), $this->ui))->handle('/review mode status');

        self::assertSame(['info: reviews are on (review.mode is not set, so the default applies)'], $this->notices());
    }

    public function testTheReviewModeInTheConfigSwitchesReviewOffWithoutAnyTurn(): void
    {
        file_put_contents($this->harness->configFile, json_encode(self::CONFIG + ['review' => ['mode' => 'disabled']], JSON_THROW_ON_ERROR));
        $spy = new RequestSpy(['local' => 'qwen']);
        $session = $this->harness->session($this->harness->kernel($spy, $this->ui));

        $session->handle('/review');

        self::assertSame(['info: reviews are off (review.mode is disabled), so /review did nothing. /review mode on turns them on.'], $this->notices());
        self::assertSame([], $spy->sent, 'no reviewer is asked');
    }

    private function notices(): array
    {
        return array_values(array_map(
            static fn (array $event): string => "{$event[1]}: {$event[2]}",
            array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice'),
        ));
    }
}
