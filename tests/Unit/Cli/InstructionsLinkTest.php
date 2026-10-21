<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Llama\LlamaFake;
use PHPUnit\Framework\TestCase;

final class InstructionsLinkTest extends TestCase
{
    private ?LlamaFake $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testTheLinkedKeyIsNotSentToTheModelAndTheUserIsTold(): void
    {
        $key = 'sk-test-fakekey-' . bin2hex(random_bytes(6));
        $outside = Kit::folder('instructions-outside') . '/key.md';
        Kit::write($outside, "api_key: {$key}\n");
        $project = Kit::folder('instructions-project');
        symlink($outside, $project . '/AGENTS.md');
        $this->server = new LlamaFake(['POST /v1/chat/completions' => [['fixture' => 'text.sse']]]);

        $run = PhycodeRun::run(
            ['run', '--cwd', $project, 'Say hello in one word.'],
            ['base_url' => $this->server->url(), 'model' => 'qwen35-2b', 'tool_mode' => 'native'],
        );

        self::assertSame(0, $run->status, $run->stderr);
        self::assertStringContainsString("phycode warn: {$project}/AGENTS.md: skipped", $run->stderr);
        $chats = array_values(array_filter($this->server->requests(), static fn (array $request): bool => $request['method'] === 'POST'));
        self::assertNotEmpty($chats);
        foreach ($chats as $chat) {
            self::assertStringNotContainsString($key, $chat['body'], 'the request carries no text of the file outside the project');
        }
    }
}
