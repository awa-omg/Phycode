<?php

declare(strict_types=1);

namespace Phycode\Agent;

final readonly class HookOutcome
{
    public function __construct(
        public ?string $block = null,
        public array $feedback = [],
        public array $context = [],
        public ?array $updatedInput = null,
    ) {
    }

    public function notes(): array
    {
        return [...$this->feedback, ...$this->context];
    }

    public function isEmpty(): bool
    {
        return $this->block === null && $this->feedback === [] && $this->context === [] && $this->updatedInput === null;
    }

    public function merge(self $other): self
    {
        return new self(
            $this->block ?? $other->block,
            [...$this->feedback, ...$other->feedback],
            [...$this->context, ...$other->context],
            $other->updatedInput ?? $this->updatedInput,
        );
    }
}
