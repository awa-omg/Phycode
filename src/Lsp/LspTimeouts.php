<?php

declare(strict_types=1);

namespace Phycode\Lsp;

final readonly class LspTimeouts
{
    public function __construct(
        public float $initializeSeconds = 30.0,
        public float $requestSeconds = 20.0,
        public float $diagnosticsSeconds = 10.0,
        public float $diagnosticsQuietSeconds = 0.5,
        public float $warmupSeconds = 3.0,
        public float $shutdownSeconds = 2.0,
    ) {
    }
}
