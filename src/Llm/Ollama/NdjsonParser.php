<?php

declare(strict_types=1);

namespace Phycode\Llm\Ollama;

final class NdjsonParser
{
    private string $buffer = '';

    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $lines = [];
        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = trim(substr($this->buffer, 0, $newline));
            $this->buffer = substr($this->buffer, $newline + 1);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function flush(): array
    {
        $line = trim($this->buffer);
        $this->buffer = '';

        return $line === '' ? [] : [$line];
    }
}
