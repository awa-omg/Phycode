<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Support\Json;

final class LoopGuard
{
    private const WINDOW = 12;

    private array $recent = [];

    public function __construct(private readonly int $limit)
    {
    }

    public function record(string $tool, string $arguments): int
    {
        $signature = $tool . '|' . self::canonical($arguments);
        $this->recent[] = $signature;
        $this->recent = array_slice($this->recent, -self::WINDOW);

        return count(array_keys($this->recent, $signature, true));
    }

    public function reset(): void
    {
        $this->recent = [];
    }

    public function limit(): int
    {
        return $this->limit;
    }

    private static function canonical(string $arguments): string
    {
        $data = Json::object($arguments);
        if ($data === null) {
            return $arguments;
        }
        self::sortKeys($data);

        return Json::encode($data);
    }

    private static function sortKeys(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                self::sortKeys($value);
            }
        }
        unset($value);
    }
}
