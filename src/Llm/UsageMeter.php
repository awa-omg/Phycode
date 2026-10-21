<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Llm\Canonical\Usage;

final class UsageMeter
{
    private array $rows = [];

    public function __construct(private readonly \Closure $modelOf)
    {
    }

    public function record(string $profile, ?string $model, ?Usage $usage): void
    {
        $id = ($this->modelOf)($profile, $model);
        $key = $profile . "\0" . $id;
        $row = $this->rows[$key] ?? ['profile' => $profile, 'model' => $id, 'requests' => 0, 'prompt' => 0, 'completion' => 0, 'cached' => 0, 'cacheWrite' => 0, 'unreported' => 0];
        $row['requests']++;
        if ($usage === null || ($usage->promptTokens === null && $usage->completionTokens === null)) {
            $row['unreported']++;
        } else {
            $row['prompt'] += $usage->promptTokens ?? 0;
            $row['completion'] += $usage->completionTokens ?? 0;
            $row['cached'] += $usage->cachedTokens ?? 0;
            $row['cacheWrite'] += $usage->cacheWriteTokens ?? 0;
        }
        $this->rows[$key] = $row;
    }

    public function rows(Pricing $pricing): array
    {
        $rows = [];
        foreach ($this->rows as $row) {
            $rows[] = $row + ['cost' => $pricing->cost($row['model'], $row['prompt'], $row['completion'], $row['cached'], $row['cacheWrite'])];
        }

        return $rows;
    }

    public function totals(Pricing $pricing): array
    {
        $total = ['requests' => 0, 'prompt' => 0, 'completion' => 0, 'cached' => 0, 'cost' => null, 'priced' => true, 'unreported' => 0];
        foreach ($this->rows($pricing) as $row) {
            $total['requests'] += $row['requests'];
            $total['prompt'] += $row['prompt'];
            $total['completion'] += $row['completion'];
            $total['cached'] += $row['cached'];
            $total['unreported'] += $row['unreported'];
            if ($row['cost'] === null) {
                $total['priced'] = false;
            } else {
                $total['cost'] = ($total['cost'] ?? 0.0) + $row['cost'];
            }
        }

        return $total;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function text(Pricing $pricing): string
    {
        if ($this->rows === []) {
            return 'No model requests yet in this session.';
        }
        $lines = [];
        foreach ($this->rows($pricing) as $row) {
            $lines[] = sprintf(
                '%s (%s): %d requests, %s in (%s cached), %s out, %s',
                $row['model'],
                $row['profile'],
                $row['requests'],
                self::tokens($row['prompt']),
                self::tokens($row['cached']),
                self::tokens($row['completion']),
                $row['cost'] === null ? 'no price' : self::money($row['cost']),
            );
            if ($row['unreported'] > 0) {
                $lines[] = "  {$row['unreported']} of the requests came without token counts from the server";
            }
        }
        $total = $this->totals($pricing);
        $lines[] = '';
        $lines[] = sprintf('total: %d requests, %s in (%s cached), %s out, %s', $total['requests'], self::tokens($total['prompt']), self::tokens($total['cached']), self::tokens($total['completion']), $total['cost'] === null ? 'no cost' : self::money($total['cost']) . ($total['priced'] ? '' : ' (without the models that have no price)'));
        if ($pricing->isEmpty()) {
            $lines[] = 'Set "pricing" in the config to see costs: {"pricing": {"MODEL": {"input": 3, "output": 15}}}, in dollars for a million tokens.';
        }

        return implode("\n", $lines);
    }

    public static function tokens(int $count): string
    {
        return $count < 1000 ? (string) $count : ($count < 1_000_000 ? round($count / 1000, 1) . 'k' : round($count / 1_000_000, 2) . 'M');
    }

    public static function money(float $dollars): string
    {
        return '$' . number_format($dollars, $dollars < 1 ? 4 : 2);
    }
}
