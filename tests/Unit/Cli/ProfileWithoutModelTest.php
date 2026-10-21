<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class ProfileWithoutModelTest extends TestCase
{
    public function testATurnOnAProfileWithoutAModelSaysToChooseOneAndAsksNothing(): void
    {
        $harness = new SessionHarness(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'tool_mode' => 'text']]]);
        try {
            $ui = new RecordingUi();
            $provider = new CountingProvider(['local' => '']);
            $session = $harness->session($harness->kernel($provider, $ui));

            $session->handle('question');

            self::assertSame('', $session->model());
            self::assertContains(['notice', 'error', "turn ended (error): profile 'local' has no model: choose one in /models, or set its model in the config"], $ui->events);
            self::assertSame([], $provider->sent);
        } finally {
            $harness->end();
        }
    }
}
