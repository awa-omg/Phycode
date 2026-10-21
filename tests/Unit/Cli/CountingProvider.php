<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\ClientProvider;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;

final class CountingProvider implements ClientProvider
{
    public int $probes = 0;

    public array $sent = [];

    private array $replies;

    public function __construct(
        private readonly array $models,
        private array $modes = ['text'],
        private readonly array $levels = [],
        array $replies = [],
    ) {
        $this->replies = $replies;
    }

    public function forProfile(string $name, ?string $model = null, ?string $thinking = null): ChatClient
    {
        $provider = $this;

        return new class ($provider, $name, $model ?? $this->models[$name], $thinking ?? 'off') implements ChatClient {
            public function __construct(
                private readonly CountingProvider $provider,
                private readonly string $profile,
                private readonly string $model,
                private readonly string $thinking,
            ) {
            }

            public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
            {
                return $this->provider->answer($this->profile, $this->model, $this->thinking, $messages, $tools, $options->cacheKey);
            }

            public function listModels(): array
            {
                return [];
            }

            public function describe(string $model): ModelInfo
            {
                return $this->provider->describe($this->profile, $model);
            }
        };
    }

    public function toolMode(string $name, ?string $model = null, ?string $thinking = null): string
    {
        $this->probes++;
        $answer = count($this->modes) > 1 ? array_shift($this->modes) : $this->modes[0];
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }

    public function answer(string $profile, string $model, string $thinking, array $messages, array $tools = [], ?string $cacheKey = null): LlmResponse
    {
        $this->sent[] = ['profile' => $profile, 'model' => $model, 'thinking' => $thinking, 'messages' => $messages, 'tools' => $tools, 'cacheKey' => $cacheKey];
        while ($this->replies !== [] && $this->replies[0] instanceof \Closure) {
            ($this->replies[0])();
            array_shift($this->replies);
        }
        if ($this->replies === []) {
            throw new LlmException('script exhausted: no reply left for this request');
        }
        $next = array_shift($this->replies);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function describe(string $profile, string $model): ModelInfo
    {
        $answer = $this->levels[$model] ?? [];
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return new ModelInfo($model, $profile, 'openai', null, null, $answer);
    }
}
