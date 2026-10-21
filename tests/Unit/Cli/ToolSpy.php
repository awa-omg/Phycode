<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\ClientProvider;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;

final class ToolSpy implements ClientProvider
{
    public array $sent = [];

    public function __construct(private readonly string $mode, private array $replies = [])
    {
    }

    public function forProfile(string $name, ?string $model = null, ?string $thinking = null): ChatClient
    {
        $spy = $this;

        return new class ($spy) implements ChatClient {
            public function __construct(private readonly ToolSpy $spy)
            {
            }

            public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
            {
                return $this->spy->answer($messages, $tools);
            }

            public function listModels(): array
            {
                return [];
            }

            public function describe(string $model): ModelInfo
            {
                return new ModelInfo($model, 'spy', 'openai');
            }
        };
    }

    public function toolMode(string $name, ?string $model = null, ?string $thinking = null): string
    {
        return $this->mode;
    }

    public function answer(array $messages, array $tools): LlmResponse
    {
        $this->sent[] = [
            'tools' => array_map(static fn (array $spec): string => (string) $spec['function']['name'], $tools),
            'messages' => $messages,
        ];
        if ($this->replies === []) {
            throw new LlmException('script exhausted: no reply left for this request');
        }
        $next = array_shift($this->replies);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
