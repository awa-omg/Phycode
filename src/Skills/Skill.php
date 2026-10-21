<?php

declare(strict_types=1);

namespace Phycode\Skills;

final readonly class Skill
{
    public function __construct(
        public string $name,
        public string $description,
        public string $path,
    ) {
    }
}
