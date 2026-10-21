<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Support\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BtwRunTest extends TestCase
{
    private const PROFILE = ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen35-2b'];

    public static function notAnObject(): iterable
    {
        yield 'text' => ['not json'];
        yield 'a list' => ['[1, 2]'];
        yield 'nothing' => [''];
    }

    #[DataProvider('notAnObject')]
    public function testInputThatIsNotAJsonObjectGivesAnErrorAnswer(string $stdin): void
    {
        $run = PhycodeRun::run(['btw-run'], self::PROFILE, $stdin);

        self::assertSame(1, $run->status, $run->stderr);
        self::assertSame(['error' => 'btw-run reads one JSON object on stdin'], Json::object($run->stdout));
        self::assertSame('', $run->stderr, 'the answer is the only output');
    }

    public function testTheCommandIsNotInTheUsage(): void
    {
        $run = PhycodeRun::run(['help'], self::PROFILE);

        self::assertSame(0, $run->status);
        self::assertStringNotContainsString('btw-run', $run->stdout);
    }
}
