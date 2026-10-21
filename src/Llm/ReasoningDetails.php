<?php

declare(strict_types=1);

namespace Phycode\Llm;

final class ReasoningDetails
{
    public static function join(array $details, array $pieces): array
    {
        foreach ($pieces as $piece) {
            if (!is_array($piece)) {
                continue;
            }
            $position = null;
            foreach ($details as $at => $detail) {
                if (is_int($piece['index'] ?? null) && ($detail['index'] ?? null) === $piece['index']) {
                    $position = $at;
                    break;
                }
            }
            if ($position === null) {
                $details[] = $piece;
            } else {
                $details[$position] = self::merged($details[$position], $piece);
            }
        }

        return array_values($details);
    }

    private static function merged(array $detail, array $piece): array
    {
        foreach ($piece as $key => $value) {
            if (is_string($value) && is_string($detail[$key] ?? null) && in_array($key, ['text', 'summary'], true)) {
                $detail[$key] .= $value;
            } elseif ($value !== null || !array_key_exists($key, $detail)) {
                $detail[$key] = $value;
            }
        }

        return $detail;
    }
}
