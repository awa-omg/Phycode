<?php

declare(strict_types=1);

namespace Phycode\Schedule;

use Phycode\Support\PhycodeException;

final class CronExpression
{
    private const ALIASES = ['@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *', '@weekly' => '0 0 * * 0', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *'];
    private const FIELDS = [
        'minute' => [0, 59, []],
        'hour' => [0, 23, []],
        'day of the month' => [1, 31, []],
        'month' => [1, 12, ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12]],
        'day of the week' => [0, 7, ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6]],
    ];
    private const SEARCH_DAYS = 3000;

    private function __construct(
        public readonly string $source,
        private readonly array $minutes,
        private readonly array $hours,
        private readonly array $days,
        private readonly array $months,
        private readonly array $weekdays,
        private readonly bool $anyDay,
        private readonly bool $anyWeekday,
    ) {
    }

    public static function parse(string $expression): self
    {
        $source = trim(preg_replace('/\s+/', ' ', $expression) ?? '');
        $fields = explode(' ', self::ALIASES[strtolower($source)] ?? $source);
        if (count($fields) !== 5) {
            throw new PhycodeException("'{$source}' is not a cron expression: it has five fields, minute hour day-of-month month day-of-week, such as 30 8 * * 1-5, or an alias such as @daily");
        }
        $sets = [];
        $index = 0;
        foreach (self::FIELDS as $label => [$min, $max, $names]) {
            $sets[] = self::set($fields[$index++], $label, $min, $max, $names);
        }
        $weekdays = array_values(array_unique(array_map(static fn (int $day): int => $day % 7, $sets[4])));
        sort($weekdays);

        return new self($source, $sets[0], $sets[1], $sets[2], $sets[3], $weekdays, str_starts_with($fields[2], '*'), str_starts_with($fields[4], '*'));
    }

    public function matches(\DateTimeInterface $time): bool
    {
        return in_array((int) $time->format('i'), $this->minutes, true)
            && in_array((int) $time->format('G'), $this->hours, true)
            && $this->dayMatches($time);
    }

    public function next(\DateTimeImmutable $after): ?\DateTimeImmutable
    {
        $start = $after->setTime((int) $after->format('G'), (int) $after->format('i'))->modify('+1 minute');
        $day = $start->setTime(0, 0);
        for ($offset = 0; $offset < self::SEARCH_DAYS; $offset++, $day = $day->modify('+1 day')) {
            if (!$this->dayMatches($day)) {
                continue;
            }
            foreach ($this->hours as $hour) {
                foreach ($this->minutes as $minute) {
                    $candidate = $day->setTime($hour, $minute);
                    if ($candidate >= $start && $candidate->format('G') == $hour) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    private function dayMatches(\DateTimeInterface $time): bool
    {
        if (!in_array((int) $time->format('n'), $this->months, true)) {
            return false;
        }
        $monthDay = in_array((int) $time->format('j'), $this->days, true);
        $weekday = in_array((int) $time->format('w'), $this->weekdays, true);

        return $this->anyDay || $this->anyWeekday ? $monthDay && $weekday : $monthDay || $weekday;
    }

    private static function set(string $field, string $label, int $min, int $max, array $names): array
    {
        $values = [];
        foreach (explode(',', $field) as $part) {
            if ($part === '') {
                throw new PhycodeException("the {$label} field '{$field}' has an empty item");
            }
            [$range, $step] = array_pad(explode('/', $part, 2), 2, null);
            if ($step !== null && (preg_match('/^[1-9][0-9]*$/D', $step) !== 1)) {
                throw new PhycodeException("the {$label} field '{$field}' has a step that is not a number from 1");
            }
            if ($range === '*') {
                [$from, $to] = [$min, $max === 7 ? 6 : $max];
            } elseif (str_contains($range, '-')) {
                [$low, $high] = explode('-', $range, 2);
                [$from, $to] = [self::number($low, $label, $field, $min, $max, $names), self::number($high, $label, $field, $min, $max, $names)];
                if ($from > $to) {
                    throw new PhycodeException("the {$label} field '{$field}' has a range that runs backwards");
                }
            } else {
                $from = self::number($range, $label, $field, $min, $max, $names);
                $to = $step === null ? $from : ($max === 7 ? 6 : $max);
            }
            for ($value = $from; $value <= $to; $value += (int) ($step ?? 1)) {
                $values[$value] = $value;
            }
        }
        ksort($values);

        return array_values($values);
    }

    private static function number(string $text, string $label, string $field, int $min, int $max, array $names): int
    {
        $value = $names[strtolower($text)] ?? (preg_match('/^[0-9]{1,2}$/D', $text) === 1 ? (int) $text : null);
        if ($value === null || $value < $min || $value > $max) {
            throw new PhycodeException("the {$label} field '{$field}' needs " . ($names === [] ? '' : 'a name or ') . "a number from {$min} to {$max}");
        }

        return $value;
    }
}
