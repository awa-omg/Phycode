<?php

declare(strict_types=1);

namespace Phycode\Config;

final class ProviderPresets
{
    public static function all(): array
    {
        return [
            ['name' => 'openai', 'protocol' => 'openai', 'baseUrl' => 'https://api.openai.com', 'apiKeyEnv' => 'OPENAI_API_KEY'],
            ['name' => 'anthropic', 'protocol' => 'anthropic', 'baseUrl' => 'https://api.anthropic.com', 'apiKeyEnv' => 'ANTHROPIC_API_KEY'],
            ['name' => 'openrouter', 'protocol' => 'openai', 'baseUrl' => 'https://openrouter.ai/api', 'apiKeyEnv' => 'OPENROUTER_API_KEY'],
            ['name' => 'groq', 'protocol' => 'openai', 'baseUrl' => 'https://api.groq.com/openai', 'apiKeyEnv' => 'GROQ_API_KEY'],
            ['name' => 'lmstudio', 'protocol' => 'openai', 'baseUrl' => 'http://127.0.0.1:1234', 'apiKeyEnv' => null],
            ['name' => 'vllm', 'protocol' => 'openai', 'baseUrl' => 'http://127.0.0.1:8000', 'apiKeyEnv' => null],
            ['name' => 'ollama-cloud', 'protocol' => 'ollama', 'baseUrl' => 'https://ollama.com', 'apiKeyEnv' => 'OLLAMA_API_KEY'],
        ];
    }
}
