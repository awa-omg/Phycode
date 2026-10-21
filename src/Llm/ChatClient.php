<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Llm\Canonical\ModelInfo;

interface ChatClient
{
    public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse;

    public function listModels(): array;

    public function describe(string $model): ModelInfo;
}
