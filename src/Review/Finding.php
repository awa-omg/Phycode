<?php

declare(strict_types=1);

namespace Phycode\Review;

final readonly class Finding
{
    public function __construct(
        public string $id,
        public ReviewLens $lens,
        public Severity $severity,
        public string $file,
        public ?int $line,
        public string $title,
        public string $detail,
        public string $evidence,
        public string $check = 'unchecked',
        public string $checkEvidence = '',
    ) {
    }

    public function checkedBy(string $outcome, string $evidence): self
    {
        return new self(
            $this->id,
            $this->lens,
            $this->severity,
            $this->file,
            $this->line,
            $this->title,
            $this->detail,
            $this->evidence,
            $outcome,
            $evidence,
        );
    }

    public function location(): string
    {
        return $this->line === null ? $this->file : "{$this->file}:{$this->line}";
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'lens' => $this->lens->value,
            'severity' => $this->severity->value,
            'file' => $this->file,
            'line' => $this->line,
            'title' => $this->title,
            'detail' => $this->detail,
            'evidence' => $this->evidence,
            'check' => $this->check,
            'check_evidence' => $this->checkEvidence,
        ];
    }
}
