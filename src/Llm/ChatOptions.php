<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Support\Cancellation;

final readonly class ChatOptions
{
    public function __construct(
        public ?\Closure $onEvent = null,
        public ?Cancellation $cancel = null,
        public array $overrides = [],
        public ?string $cacheKey = null,
    ) {
    }

    public function emit(string $type, string $text): void
    {
        if ($this->onEvent !== null) {
            ($this->onEvent)($type, $text);
        }
    }
}
