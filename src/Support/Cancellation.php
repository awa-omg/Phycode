<?php

declare(strict_types=1);

namespace Phycode\Support;

final class Cancellation
{
    private const POLL_SECONDS = 0.2;

    private static ?\Closure $poll = null;
    private static float $polledAt = 0.0;

    private bool $cancelled = false;
    private ?string $reason = null;

    public function __construct(
        private readonly ?self $parent = null,
        private readonly ?float $deadline = null,
    ) {
    }

    public static function setPoll(?\Closure $poll): void
    {
        self::$poll = $poll;
        self::$polledAt = 0.0;
    }

    public function cancel(string $reason = 'cancelled'): void
    {
        $this->cancelled = true;
        $this->reason ??= $reason;
    }

    public function child(?float $deadline = null): self
    {
        return new self($this, $deadline);
    }

    public function reason(): ?string
    {
        if ($this->cancelled) {
            return $this->reason;
        }
        if ($this->parent !== null && $this->parent->isCancelled()) {
            return $this->parent->reason();
        }
        if ($this->deadline !== null && microtime(true) >= $this->deadline) {
            return 'timeout';
        }

        return null;
    }

    public function isCancelled(): bool
    {
        self::pollDue();

        return $this->reason() !== null;
    }

    private static function pollDue(): void
    {
        if (self::$poll === null) {
            return;
        }
        $now = microtime(true);
        if ($now - self::$polledAt < self::POLL_SECONDS) {
            return;
        }
        self::$polledAt = $now;
        (self::$poll)();
    }
}
