<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Support\Json;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Llama\LlamaFake;
use PHPUnit\Framework\TestCase;

final class RunRedactionTest extends TestCase
{
    private ?LlamaFake $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testTheReasonHasNoTokenAndNoKeyInItsJsonOrOnStderr(): void
    {
        $key = 'key-' . bin2hex(random_bytes(8));
        $body = json_encode(['error' => ['message' => "bad token Bearer abc123 for {$key}"]], JSON_THROW_ON_ERROR);
        $this->server = new LlamaFake(['POST /v1/chat/completions' => [['status' => 400, 'body' => $body]]]);

        $run = PhycodeRun::run(
            ['run', '--cwd', Kit::folder('run-redaction'), '--json', 'Say hello.'],
            ['base_url' => $this->server->url(), 'model' => 'qwen35-2b', 'tool_mode' => 'native', 'api_key_env' => 'PHYCODE_RUN_KEY'],
            '',
            ['PHYCODE_RUN_KEY' => $key],
        );

        self::assertSame(2, $run->status, $run->stderr);
        $result = Json::object($run->stdout) ?? [];
        self::assertSame('error', $result['status'] ?? null);
        self::assertSame('HTTP 400: bad token Bearer [redacted] for [redacted]', $result['reason'] ?? null);
        self::assertStringNotContainsString('abc123', $run->stdout . $run->stderr);
        self::assertStringNotContainsString($key, $run->stdout . $run->stderr);
        self::assertStringContainsString('phycode: run ended (error): HTTP 400: bad token Bearer [redacted] for [redacted]', $run->stderr);
    }
}
