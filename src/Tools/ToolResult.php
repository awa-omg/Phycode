<?php

declare(strict_types=1);

namespace Phycode\Tools;

final readonly class ToolResult
{
    public function __construct(
        public bool $ok,
        public string $content,
        public array $meta = [],
        public ?array $diff = null,
    ) {
    }

    public static function ok(string $content, array $meta = []): self
    {
        return new self(true, $content, $meta);
    }

    public static function fail(string $content, array $meta = []): self
    {
        return new self(false, $content, $meta);
    }

    public function withContent(string $content): self
    {
        return new self($this->ok, $content, $this->meta, $this->diff);
    }

    public function withDiff(?array $diff): self
    {
        return new self($this->ok, $this->content, $this->meta, $diff);
    }
}
