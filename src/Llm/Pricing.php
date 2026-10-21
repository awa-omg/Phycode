<?php

declare(strict_types=1);

namespace Phycode\Llm;

final class Pricing
{
    public function __construct(private readonly array $prices = [])
    {
    }

    public function isEmpty(): bool
    {
        return $this->prices === [];
    }

    public function for(string $model): ?array
    {
        if (isset($this->prices[$model])) {
            return $this->prices[$model];
        }
        $best = null;
        foreach ($this->prices as $pattern => $price) {
            if (str_contains((string) $pattern, '*') && fnmatch((string) $pattern, $model) && ($best === null || strlen((string) $pattern) > strlen($best))) {
                $best = (string) $pattern;
            }
        }

        return $best === null ? null : $this->prices[$best];
    }

    public function cost(string $model, int $prompt, int $completion, int $cached, int $cacheWrite): ?float
    {
        $price = $this->for($model);
        if ($price === null) {
            return null;
        }
        $fresh = max(0, $prompt - $cached - $cacheWrite);

        return ($fresh * $price['input'] + $cached * ($price['cached_input'] ?? $price['input']) + $cacheWrite * ($price['cache_write'] ?? $price['input']) + $completion * $price['output']) / 1_000_000;
    }
}
