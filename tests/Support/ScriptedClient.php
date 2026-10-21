<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;
use Phycode\Llm\ToolCall;

final class ScriptedClient implements ChatClient
{
    public array $requests = [];

    public function __construct(private array $replies)
    {
    }

    public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
    {
        $this->requests[] = ['messages' => $messages, 'tools' => $tools, 'overrides' => $options->overrides];
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
        return [new ModelInfo('scripted', 'test', 'openai')];
    }

    public function describe(string $model): ModelInfo
    {
        return new ModelInfo($model, 'test', 'openai');
    }

    public static function text(string $content): LlmResponse
    {
        return new LlmResponse($content, '', [], FinishReason::STOP, new Usage());
    }

    public static function callRaw(string $tool, string $arguments, string $id = 'call_1'): LlmResponse
    {
        return new LlmResponse('', '', [new ToolCall($id, $tool, $arguments)], FinishReason::TOOL_CALLS, new Usage());
    }

    public static function call(string $tool, array $arguments, string $id = 'call_1'): LlmResponse
    {
        $call = new ToolCall($id, $tool, json_encode($arguments, JSON_THROW_ON_ERROR));

        return new LlmResponse('', '', [$call], FinishReason::TOOL_CALLS, new Usage());
    }
}
