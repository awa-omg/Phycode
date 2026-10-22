<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Schedule;

use Phycode\Schedule\CronExpression;
use Phycode\Support\PhycodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CronExpressionTest extends TestCase
{
    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public static function matching(): iterable
    {
        yield 'every minute' => ['* * * * *', '2026-10-21 14:57', true];
        yield 'exact minute' => ['30 8 * * *', '2026-10-21 08:30', true];
        yield 'exact minute wrong hour' => ['30 8 * * *', '2026-10-21 09:30', false];
        yield 'step' => ['*/15 * * * *', '2026-10-21 10:45', true];
        yield 'step miss' => ['*/15 * * * *', '2026-10-21 10:50', false];
        yield 'range with a step' => ['0-30/10 * * * *', '2026-10-21 10:20', true];
        yield 'range with a step miss' => ['0-30/10 * * * *', '2026-10-21 10:40', false];
        yield 'list' => ['5,10,15 * * * *', '2026-10-21 10:10', true];
        yield 'weekdays' => ['0 9 * * 1-5', '2026-10-21 09:00', true];
        yield 'weekdays on a sunday' => ['0 9 * * 1-5', '2026-10-25 09:00', false];
        yield 'sunday as 0' => ['0 9 * * 0', '2026-10-25 09:00', true];
        yield 'sunday as 7' => ['0 9 * * 7', '2026-10-25 09:00', true];
        yield 'day names' => ['0 9 * * mon,WED', '2026-10-21 09:00', true];
        yield 'month names' => ['0 0 1 jan,feb *', '2026-02-01 00:00', true];
        yield 'month names miss' => ['0 0 1 jan,feb *', '2026-03-01 00:00', false];
        yield 'day of month' => ['0 0 15 * *', '2026-10-15 00:00', true];
        yield 'both day fields restricted: either' => ['0 0 13 * 5', '2026-10-13 00:00', true];
        yield 'both day fields restricted: either, the weekday' => ['0 0 13 * 5', '2026-10-16 00:00', true];
        yield 'both day fields restricted: neither' => ['0 0 13 * 5', '2026-10-14 00:00', false];
        yield 'star step on the day of the month keeps the and' => ['0 0 */2 * 3', '2026-10-21 00:00', true];
        yield 'hourly alias' => ['@hourly', '2026-10-21 03:00', true];
        yield 'hourly alias miss' => ['@hourly', '2026-10-21 03:01', false];
        yield 'daily alias' => ['@daily', '2026-10-21 00:00', true];
        yield 'weekly alias' => ['@weekly', '2026-10-25 00:00', true];
        yield 'monthly alias' => ['@monthly', '2026-11-01 00:00', true];
        yield 'yearly alias' => ['@yearly', '2027-01-01 00:00', true];
        yield 'extra spaces' => ["  5   4  *  *  *  ", '2026-10-21 04:05', true];
    }

    #[DataProvider('matching')]
    public function testAnExpressionMatchesTheMinutesCronWould(string $expression, string $time, bool $expected): void
    {
        self::assertSame($expected, CronExpression::parse($expression)->matches(self::at($time)));
    }

    public static function following(): iterable
    {
        yield 'next minute' => ['* * * * *', '2026-10-21 14:57:30', '2026-10-21 14:58'];
        yield 'later today' => ['30 18 * * *', '2026-10-21 14:57', '2026-10-21 18:30'];
        yield 'tomorrow' => ['30 8 * * *', '2026-10-21 14:57', '2026-10-22 08:30'];
        yield 'not the same minute' => ['57 14 * * *', '2026-10-21 14:57', '2026-10-22 14:57'];
        yield 'monday' => ['0 9 * * 1', '2026-10-21 14:57', '2026-10-26 09:00'];
        yield 'end of month' => ['0 0 31 * *', '2026-11-01 00:00', '2026-12-31 00:00'];
        yield 'leap day' => ['0 0 29 2 *', '2026-03-01 00:00', '2028-02-29 00:00'];
        yield 'the year changes' => ['0 0 1 1 *', '2026-10-21 00:00', '2027-01-01 00:00'];
        yield 'steps across the hour' => ['*/20 * * * *', '2026-10-21 14:41', '2026-10-21 15:00'];
    }

    #[DataProvider('following')]
    public function testNextIsTheFirstMatchingMinuteAfterATime(string $expression, string $after, string $expected): void
    {
        $next = CronExpression::parse($expression)->next(self::at($after));

        self::assertSame($expected, $next?->format('Y-m-d H:i'));
    }

    public function testADayThatNeverComesHasNoNext(): void
    {
        self::assertNull(CronExpression::parse('0 0 31 2 *')->next(self::at('2026-01-01 00:00')));
    }

    public static function invalid(): iterable
    {
        yield 'four fields' => ['* * * *', 'it has five fields'];
        yield 'six fields' => ['* * * * * *', 'it has five fields'];
        yield 'empty' => ['', 'it has five fields'];
        yield 'minute too big' => ['60 * * * *', 'the minute field'];
        yield 'hour too big' => ['* 24 * * *', 'the hour field'];
        yield 'day zero' => ['* * 0 * *', 'the day of the month field'];
        yield 'month 13' => ['* * * 13 *', 'the month field'];
        yield 'weekday 8' => ['* * * * 8', 'the day of the week field'];
        yield 'unknown name' => ['* * * * funday', 'the day of the week field'];
        yield 'backwards range' => ['30-10 * * * *', 'runs backwards'];
        yield 'step zero' => ['*/0 * * * *', 'a step that is not a number from 1'];
        yield 'step word' => ['*/x * * * *', 'a step that is not a number from 1'];
        yield 'empty item' => ['1,,2 * * * *', 'has an empty item'];
        yield 'a sign' => ['-5 * * * *', 'the minute field'];
        yield 'unknown alias' => ['@sometimes', 'it has five fields'];
    }

    #[DataProvider('invalid')]
    public function testABadExpressionIsRefusedWithTheField(string $expression, string $message): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage($message);
        CronExpression::parse($expression);
    }
}
