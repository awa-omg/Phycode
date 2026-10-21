<?php

declare(strict_types=1);

namespace Phycode\Llm;

final class SseParser
{
    private string $buffer = '';

    private array $data = [];

    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];
        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $newline), "\r");
            $this->buffer = (string) substr($this->buffer, $newline + 1);
            if ($line === '') {
                if ($this->data !== []) {
                    $events[] = implode("\n", $this->data);
                    $this->data = [];
                }
                continue;
            }
            $this->absorb($line);
        }

        return $events;
    }

    public function flush(): array
    {
        if ($this->buffer !== '') {
            $line = rtrim($this->buffer, "\r");
            $this->buffer = '';
            $this->absorb($line);
        }
        if ($this->data === []) {
            return [];
        }
        $event = implode("\n", $this->data);
        $this->data = [];

        return [$event];
    }

    private function absorb(string $line): void
    {
        if ($line[0] === ':' || !str_starts_with($line, 'data:')) {
            return;
        }
        $value = substr($line, 5);
        $this->data[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
    }
}
