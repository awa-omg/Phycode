<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\SessionHarness;

final class RpcTurnCommandsTest extends RpcTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ]]);
    }

    public function testOnlyGoalInitHandoffAndTheTemplatesCarryTheTurnFlag(): void
    {
        $prompts = $this->harness->cwd . '/.phycode/prompts';
        mkdir($prompts, 0700, true);
        file_put_contents($prompts . '/triage.md', "Triage the open issues.\n");
        file_put_contents($prompts . '/help.md', "Help me.\n");

        $ready = self::first($this->play([], new RequestSpy(['local' => 'qwen']))['events'], 'ready');

        $flagged = [];
        foreach ($ready['commands'] as $command) {
            if (array_key_exists('turn', $command)) {
                self::assertTrue($command['turn'], $command['name']);
                $flagged[] = $command['name'];
            }
        }
        self::assertSame(['/goal [--check COMMAND] [--max N] CONDITION', '/handoff', '/init', '/triage [ARGUMENTS]'], $flagged, 'in the order the session lists them');
    }
}
