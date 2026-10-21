<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Support;

use Phycode\Support\Cancellation;
use PHPUnit\Framework\TestCase;

final class CancellationTest extends TestCase
{
    protected function tearDown(): void
    {
        Cancellation::setPoll(null);
    }

    public function testThePollRunsAtMostOncePerInterval(): void
    {
        $polls = 0;
        Cancellation::setPoll(static function () use (&$polls): void {
            $polls++;
        });
        $cancel = new Cancellation();

        $started = microtime(true);
        for ($check = 0; $check < 50; $check++) {
            $cancel->isCancelled();
        }
        $elapsed = microtime(true) - $started;
        self::assertGreaterThanOrEqual(1, $polls);
        self::assertLessThanOrEqual(1 + (int) ($elapsed / 0.2), $polls, 'at most one poll per 200 ms');

        $before = $polls;
        usleep(250000);
        $cancel->isCancelled();
        self::assertSame($before + 1, $polls, 'a check after the interval polls again');
    }

    public function testACancellationMadeByThePollIsSeenByTheSameCheck(): void
    {
        $cancel = new Cancellation();
        Cancellation::setPoll(static function () use ($cancel): void {
            $cancel->cancel('interrupted by the user');
        });

        self::assertTrue($cancel->isCancelled());
        self::assertSame('interrupted by the user', $cancel->reason());
    }

    public function testAChildSeesTheCancellationOfItsParentThroughThePoll(): void
    {
        $parent = new Cancellation();
        $child = $parent->child();
        Cancellation::setPoll(static function () use ($parent): void {
            $parent->cancel('interrupted by the user');
        });

        self::assertTrue($child->isCancelled());
    }

    public function testWithoutAPollACheckOnlyReadsTheFlag(): void
    {
        $cancel = new Cancellation();

        self::assertFalse($cancel->isCancelled());
        $cancel->cancel('stopped');
        self::assertTrue($cancel->isCancelled());
    }
}
