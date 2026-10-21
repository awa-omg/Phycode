<?php

declare(strict_types=1);

namespace Phycode\Skills;

final readonly class SkillChange
{
    public function __construct(
        public string $action,
        public string $skill,
        public string $path,
        public string $relative,
        public ?string $before,
        public ?string $after,
        public int $occurrences = 0,
        public int $line = 0,
        public array $from = [],
        public ?string $source = null,
    ) {
    }
}
