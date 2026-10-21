<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Diff;

final class DiffRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & 0x7fffffff;
    }

    public function int(int $min, int $max): int
    {
        $this->state = (1103515245 * $this->state + 12345) & 0x7fffffff;

        return $min + intdiv($this->state, 65536) % ($max - $min + 1);
    }
}
