<?php

declare(strict_types=1);

namespace Phycode\Tools;

final class ChangeLog
{
    private array $paths = [];

    public function add(string $relative): void
    {
        $this->paths[] = $relative;
    }

    public function mark(): int
    {
        return count($this->paths);
    }

    public function since(int $from): array
    {
        return array_values(array_unique(array_slice($this->paths, $from)));
    }
}
