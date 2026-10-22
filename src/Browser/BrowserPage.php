<?php

declare(strict_types=1);

namespace Phycode\Browser;

final class BrowserPage
{
    public const MAX_CONSOLE_ENTRIES = 200;
    public const MAX_BLOCKED_ENTRIES = 50;

    public ?string $sessionId = null;
    public array $started = [];
    public array $finished = [];
    public array $idle = [];
    public int $consoleOmitted = 0;
    public int $popups = 0;
    public array $console = [];
    public array $blocked = [];

    public function __construct(public readonly string $targetId)
    {
    }

    public function loading(): bool
    {
        return array_diff_key($this->started, $this->finished) !== [];
    }

    public function busy(): bool
    {
        return array_diff_key($this->started, $this->idle) !== [];
    }

    public function log(string $level, string $text): void
    {
        if (count($this->console) >= self::MAX_CONSOLE_ENTRIES) {
            $this->consoleOmitted++;

            return;
        }
        $this->console[] = ['level' => $level, 'text' => $text];
    }

    public function block(string $url, string $reason): void
    {
        if (count($this->blocked) < self::MAX_BLOCKED_ENTRIES && !isset($this->blocked[$url])) {
            $this->blocked[$url] = $reason;
        }
    }
}
