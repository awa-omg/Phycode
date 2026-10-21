<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RpcStartupRedactionTest extends TestCase
{
    public static function keyAsAName(): iterable
    {
        yield '--profile' => ['--profile', "unknown profile '[redacted]'"];
        yield '--agent' => ['--agent', "no primary agent named '[redacted]'"];
    }

    #[DataProvider('keyAsAName')]
    public function testTheKeyOfTheConfigurationIsNotPrintedWhenTheStartFails(string $option, string $start): void
    {
        $key = 'fakekey-7f3a9c2e51b8d4';

        $run = PhycodeRun::run(
            ['rpc', '--cwd', Kit::folder('rpc-startup-cwd'), $option, $key],
            ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'api_key_env' => 'PHYCODE_STARTUP_KEY'],
            '',
            ['PHYCODE_STARTUP_KEY' => $key],
        );

        self::assertSame(1, $run->status, $run->stderr);
        $error = json_decode(trim($run->stdout), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('error', $error['type']);
        self::assertStringStartsWith($start, $error['message']);
        self::assertStringContainsString("phycode: {$start}", $run->stderr);
        self::assertStringNotContainsString($key, $run->stdout . $run->stderr);
    }
}
