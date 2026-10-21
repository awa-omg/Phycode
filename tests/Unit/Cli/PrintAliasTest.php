<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Support\Json;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Llama\LlamaFake;
use PHPUnit\Framework\TestCase;

final class PrintAliasTest extends TestCase
{
    private ?LlamaFake $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testDashPAndPrintRunTheTaskLikeRun(): void
    {
        $body = json_encode(['error' => ['message' => 'no model is loaded']], JSON_THROW_ON_ERROR);
        $this->server = new LlamaFake(['POST /v1/chat/completions' => [
            ['status' => 400, 'body' => $body],
            ['status' => 400, 'body' => $body],
            ['status' => 400, 'body' => $body],
        ]]);
        $profile = ['base_url' => $this->server->url(), 'model' => 'qwen35-2b', 'tool_mode' => 'native'];
        $cwd = Kit::folder('print-alias');

        $run = PhycodeRun::run(['run', '--cwd', $cwd, '--json', 'Say hello.'], $profile);
        $dashP = PhycodeRun::run(['-p', '--cwd', $cwd, '--json', 'Say hello.'], $profile);
        $print = PhycodeRun::run(['--print', '--cwd', $cwd, '--json', 'Say hello.'], $profile);

        self::assertSame(2, $run->status, $run->stderr);
        foreach ([$dashP, $print] as $alias) {
            self::assertSame($run->status, $alias->status, $alias->stderr);
            $result = Json::object($alias->stdout) ?? [];
            self::assertSame('error', $result['status'] ?? null);
            self::assertSame('HTTP 400: no model is loaded', $result['reason'] ?? null);
            self::assertStringContainsString('phycode: run ended (error): HTTP 400: no model is loaded', $alias->stderr);
        }
    }

    public function testThePromptCanComeFromStdinAsWithRun(): void
    {
        $body = json_encode(['error' => ['message' => 'no model is loaded']], JSON_THROW_ON_ERROR);
        $this->server = new LlamaFake(['POST /v1/chat/completions' => [['status' => 400, 'body' => $body]]]);
        $cwd = Kit::folder('print-stdin');

        $run = PhycodeRun::run(['-p', '--cwd', $cwd, '--json'], ['base_url' => $this->server->url(), 'model' => 'qwen35-2b', 'tool_mode' => 'native'], "Say hello.\n");

        self::assertSame(2, $run->status, $run->stderr);
        self::assertSame('error', Json::object($run->stdout)['status'] ?? null);
    }

    public function testWithoutATaskDashPFailsLikeRunWithoutOne(): void
    {
        $cwd = Kit::folder('print-empty');
        $profile = ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen35-2b'];

        $run = PhycodeRun::run(['run', '--cwd', $cwd], $profile);
        $dashP = PhycodeRun::run(['-p', '--cwd', $cwd], $profile);

        self::assertSame(1, $dashP->status);
        self::assertSame($run->stderr, $dashP->stderr);
        self::assertSame("phycode: give the task in quotes: phycode run \"describe the change\"\n", $dashP->stderr);
    }

    public function testDashPCannotFollowAnotherCommand(): void
    {
        $run = PhycodeRun::run(['chat', '-p'], ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen35-2b']);

        self::assertSame(1, $run->status);
        self::assertSame("phycode: -p is an alias of run: write phycode -p \"task\", or phycode run \"task\"\n", $run->stderr);
    }
}
