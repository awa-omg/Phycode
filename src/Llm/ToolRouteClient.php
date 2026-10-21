<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Config\Profile;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\Responses\ResponsesClient;

final class ToolRouteClient implements ChatClient
{
    public function __construct(
        private readonly OpenAiClient $chatClient,
        private readonly ResponsesClient $responsesClient,
        private bool $onResponses = false,
        private readonly ?\Closure $onSwitch = null,
    ) {
    }

    public function profile(): Profile
    {
        return $this->chatClient->profile();
    }

    public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
    {
        if ($this->onResponses || EffortMapping::viaResponses($this->profile(), $tools !== [])) {
            return $this->responsesClient->chat($messages, $tools, $options);
        }
        try {
            return $this->chatClient->chat($messages, $tools, $options);
        } catch (LlmException $error) {
            if (!str_starts_with($error->getMessage(), 'HTTP 400: ') || !str_contains($error->getMessage(), OpenAiClient::FUNCTION_TOOLS_REFUSAL)) {
                throw $error;
            }
        }

        $this->onResponses = true;
        if ($this->onSwitch !== null) {
            ($this->onSwitch)();
        }
        $options->emit('progress', '/v1/chat/completions does not take function tools with a reasoning level: the request was sent to /v1/responses, and the rest of this session goes there too');

        return $this->responsesClient->chat($messages, $tools, $options);
    }

    public function listModels(): array
    {
        return $this->chatClient->listModels();
    }

    public function describe(string $model): ModelInfo
    {
        return $this->chatClient->describe($model);
    }
}
