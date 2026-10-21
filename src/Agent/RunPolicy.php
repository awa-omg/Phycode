<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Tools\ToolCategory;

final readonly class RunPolicy
{
    public const COMPANIONS = ['job' => 'bash'];

    public function __construct(
        public ?array $allowed,
        public array $excluded,
        public array $denied,
        public string $mode,
        public int $depth,
    ) {
    }

    public static function primary(AgentDefinition $agent, string $mode): self
    {
        return new self($agent->tools, $agent->excludedTools, $agent->deniedCategories, $mode, 0);
    }

    public static function child(self $parent, AgentDefinition $agent): self
    {
        $allowed = $parent->allowed;
        if ($agent->tools !== null) {
            $allowed = $allowed === null
                ? $agent->tools
                : array_values(array_intersect($allowed, $agent->tools));
        }

        return new self(
            $allowed,
            array_values(array_unique([...$parent->excluded, ...$agent->excludedTools])),
            array_values(array_unique([...$parent->denied, ...$agent->deniedCategories])),
            $parent->mode,
            $parent->depth + 1,
        );
    }

    public function withDenied(array $categories): self
    {
        return new self(
            $this->allowed,
            $this->excluded,
            array_values(array_unique([...$this->denied, ...$categories])),
            $this->mode,
            $this->depth,
        );
    }

    public function allows(string $tool): bool
    {
        $tool = self::COMPANIONS[$tool] ?? $tool;
        if ($tool === 'task' && $this->depth > 0) {
            return false;
        }
        if (in_array($tool, $this->excluded, true)) {
            return false;
        }

        return $this->allowed === null || in_array($tool, $this->allowed, true);
    }

    public function canDelegate(): bool
    {
        return $this->depth === 0 && $this->allows('task');
    }

    public function denies(ToolCategory $category): bool
    {
        return in_array($category->value, $this->denied, true);
    }
}
