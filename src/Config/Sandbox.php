<?php

declare(strict_types=1);

namespace Phycode\Config;

final readonly class Sandbox
{
    public function __construct(
        public string $default,
        public ?string $image,
        public string $network,
        public array $allowDomains = [],
    ) {
    }
}
