<?php

declare(strict_types=1);

namespace Phycode\Deploy;

final readonly class DeployPlan
{
    public function __construct(
        public string $target,
        public string $action,
        public array $steps,
        public array $notes = [],
        public array $cleanup = [],
    ) {
    }

    public function commands(): array
    {
        return array_map(static fn (DeployStep $step): string => $step->display(), $this->steps);
    }
}
