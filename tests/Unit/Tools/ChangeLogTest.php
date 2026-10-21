<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Tools\ChangeLog;
use PHPUnit\Framework\TestCase;

final class ChangeLogTest extends TestCase
{
    public function testAChangeMadeAgainInALaterTurnIsReportedForThatTurn(): void
    {
        $log = new ChangeLog();
        $log->add('a.txt');
        $turn = $log->mark();

        $log->add('a.txt');
        $log->add('a.txt');

        self::assertSame(['a.txt'], $log->since($turn), 'once, although it changed twice in the turn');
        self::assertSame([], $log->since($log->mark()), 'nothing changed after the last mark');
    }
}
