<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\ClientProvider;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;

final class DescribingProvider implements ClientProvider, ChatClient
{
    public array $described = [];

    public function __construct(
        private array $answers,
        private array $replies = [],
    ) {
    }

    public function forProfile(string $name, ?string $model = null, ?string $thinking = null): ChatClient
    {
        return $this;
    }

    public function toolMode(string $name, ?string $model = null, ?string $thinking = null): string
    {
        return 'text';
    }

    public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
    {
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

    public function listModels(): array
    {
        return [];
    }

    public function describe(string $model): ModelInfo
    {
        $this->described[] = $model;
        $answer = count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }
}
