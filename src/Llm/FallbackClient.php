<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Support\Redaction;

final class FallbackClient implements ChatClient
{
    private int $current = 0;

    public function __construct(private readonly array $names, private readonly array $clients)
    {
    }

    public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
    {
        $last = count($this->clients) - 1;
        while ($this->current < $last) {
            try {
                return $this->clients[$this->current]->chat($messages, $tools, $options);
            } catch (LlmException $error) {
                if (!str_starts_with($error->getMessage(), 'HTTP 429: ')) {
                    throw $error;
                }
            }

            $from = $this->names[$this->current];
            $this->current++;
            $to = $this->names[$this->current];
            $options->emit('notice', Redaction::text("profile {$from} answered HTTP 429 (too many requests); this turn continues with the fallback profile {$to}"));
        }

        return $this->clients[$last]->chat($messages, $tools, $options);
    }

    public function listModels(): array
    {
        return $this->clients[0]->listModels();
    }

    public function describe(string $model): ModelInfo
    {
        return $this->clients[0]->describe($model);
    }
}
