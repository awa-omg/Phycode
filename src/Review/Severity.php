<?php

declare(strict_types=1);

namespace Phycode\Review;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Suggestion = 'suggestion';

    public static function parse(mixed $word): ?self
    {
        if (!is_string($word)) {
            return null;
        }

        return match (strtolower(trim($word))) {
            'error', 'blocker', 'critical' => self::Error,
            'warning', 'warn' => self::Warning,
            'suggestion', 'info', 'note' => self::Suggestion,
            default => null,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Error => 0,
            self::Warning => 1,
            self::Suggestion => 2,
        };
    }
}
