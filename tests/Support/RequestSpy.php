<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\ClientProvider;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;

final class RequestSpy implements ClientProvider
{
    public array $sent = [];

    public array $listed = [];

    private array $replies;

    public function __construct(
        private readonly array $models,
        private readonly array $listings = [],
        array $replies = [],
        private readonly array $modes = [],
    ) {
        $this->replies = $replies;
    }

    public function forProfile(string $name, ?string $model = null, ?string $thinking = null): ChatClient
    {
        $spy = $this;

        return new class ($spy, $name, $model ?? $this->models[$name], $thinking ?? 'off') implements ChatClient {
            public function __construct(
                private readonly RequestSpy $spy,
                private readonly string $profile,
                private readonly string $model,
                private readonly string $thinking,
            ) {
            }

            public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
            {
                return $this->spy->answer($this->profile, $this->model, $this->thinking, $messages);
            }

            public function listModels(): array
            {
                return $this->spy->listing($this->profile);
            }

            public function describe(string $model): ModelInfo
            {
                return new ModelInfo($model, $this->profile, 'openai');
            }
        };
    }

    public function toolMode(string $name, ?string $model = null, ?string $thinking = null): string
    {
        return $this->modes[$name] ?? 'text';
    }

    public function answer(string $profile, string $model, string $thinking, array $messages): LlmResponse
    {
        $this->sent[] = ['profile' => $profile, 'model' => $model, 'thinking' => $thinking, 'messages' => $messages];
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

    public function listing(string $profile): array
    {
        $this->listed[] = $profile;
        $answer = $this->listings[$profile] ?? [new ModelInfo($this->models[$profile], $profile, 'openai')];
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }
}
