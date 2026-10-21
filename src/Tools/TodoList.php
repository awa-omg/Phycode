<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class TodoList
{
    public const STATUSES = ['pending', 'in_progress', 'completed'];
    public const MAX_ITEMS = 40;
    private const MAX_CONTENT = 200;
    private const SYNONYMS = ['todo' => 'pending', 'open' => 'pending', 'not_started' => 'pending', 'doing' => 'in_progress', 'active' => 'in_progress', 'in-progress' => 'in_progress', 'started' => 'in_progress', 'done' => 'completed', 'complete' => 'completed', 'finished' => 'completed'];

    private array $items = [];

    public function replace(array $items): void
    {
        if (!array_is_list($items) || count($items) > self::MAX_ITEMS) {
            throw new PhycodeException('todos must be a list of at most ' . self::MAX_ITEMS . ' tasks');
        }
        $clean = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || !is_string($item['content'] ?? null) || trim($item['content']) === '') {
                throw new PhycodeException('task ' . ($index + 1) . ' needs a content, the text of the task');
            }
            $status = strtolower(trim((string) ($item['status'] ?? 'pending')));
            $status = self::SYNONYMS[$status] ?? $status;
            if (!in_array($status, self::STATUSES, true)) {
                throw new PhycodeException('task ' . ($index + 1) . ' has the status ' . Text::oneLine((string) ($item['status'] ?? ''), 30) . '; use pending, in_progress or completed');
            }
            $clean[] = ['content' => Text::oneLine(trim($item['content']), self::MAX_CONTENT), 'status' => $status];
        }
        $this->items = $clean;
    }

    public function items(): array
    {
        return $this->items;
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function counts(): array
    {
        return ['done' => count(array_filter($this->items, static fn (array $item): bool => $item['status'] === 'completed')), 'total' => count($this->items)];
    }

    public function current(): ?string
    {
        foreach ($this->items as $item) {
            if ($item['status'] === 'in_progress') {
                return $item['content'];
            }
        }
        foreach ($this->items as $item) {
            if ($item['status'] === 'pending') {
                return $item['content'];
            }
        }

        return null;
    }

    public function text(): string
    {
        if ($this->items === []) {
            return 'no tasks yet';
        }
        $marks = ['completed' => '[x]', 'in_progress' => '[>]', 'pending' => '[ ]'];

        return implode("\n", array_map(static fn (array $item, int $index): string => ($index + 1) . '. ' . $marks[$item['status']] . ' ' . $item['content'], $this->items, array_keys($this->items)));
    }

    public function toArray(): array
    {
        return $this->items;
    }

    public function restore(mixed $saved): void
    {
        try {
            $this->replace(is_array($saved) ? $saved : []);
        } catch (PhycodeException) {
            $this->items = [];
        }
    }
}
