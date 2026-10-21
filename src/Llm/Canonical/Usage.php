<?php

declare(strict_types=1);

namespace Phycode\Llm\Canonical;

final readonly class Usage
{
    public function __construct(
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $cachedTokens = null,
        public ?float $tokensPerSecond = null,
        public ?int $cacheWriteTokens = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'promptTokens' => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
            'cachedTokens' => $this->cachedTokens,
            'tokensPerSecond' => $this->tokensPerSecond,
        ];
    }
}
