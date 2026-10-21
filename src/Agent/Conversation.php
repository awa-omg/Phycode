<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Llm\Image;
use Phycode\Llm\TextToolProtocol;
use Phycode\Llm\ToolCall;
use Phycode\Support\Json;

final class Conversation
{
    private array $messages = [];

    public function setSystem(string $content): void
    {
        $system = ['role' => 'system', 'content' => $content];
        if (isset($this->messages[0]) && $this->messages[0]['role'] === 'system') {
            $this->messages[0] = $system;

            return;
        }
        array_unshift($this->messages, $system);
    }

    public function addUser(string $content, array $images = []): void
    {
        $message = ['role' => 'user', 'content' => $content];
        if ($images !== []) {
            $message['images'] = array_map(static fn (Image $image): array => $image->toArray(), $images);
        }
        $this->messages[] = $message;
    }

    public function addAssistant(string $content, array $toolCalls = [], array $extra = []): void
    {
        $message = ['role' => 'assistant', 'content' => $content];
        if ($toolCalls !== []) {
            $message['tool_calls'] = array_map(
                static fn (ToolCall $call): array => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => ['name' => $call->name, 'arguments' => $call->arguments],
                ],
                $toolCalls,
            );
        }
        if ($extra !== []) {
            $message['extra'] = $extra;
        }
        $this->messages[] = $message;
    }

    public function addToolResult(string $toolCallId, string $content): void
    {
        $this->messages[] = ['role' => 'tool', 'tool_call_id' => $toolCallId, 'content' => $content];
    }

    public function messages(): array
    {
        return $this->messages;
    }

    public function replaceMessages(array $messages): void
    {
        $this->messages = array_values($messages);
    }

    public function unloadSkill(string $name, string $line, bool $native): int
    {
        $count = 0;
        if ($native) {
            $loads = [];
            foreach ($this->messages as $message) {
                foreach ($message['tool_calls'] ?? [] as $call) {
                    if (self::isLoadOf($call['function']['name'] ?? null, $call['function']['arguments'] ?? null, $name)) {
                        $loads[(string) $call['id']] = true;
                    }
                }
            }
            foreach ($this->messages as $index => $message) {
                if (($message['role'] ?? null) === 'tool' && isset($loads[$message['tool_call_id'] ?? '']) && !str_starts_with((string) $message['content'], 'ERROR: ')) {
                    $this->messages[$index]['content'] = $line;
                    $count++;
                }
            }

            return $count;
        }

        foreach ($this->messages as $index => $message) {
            if (($message['role'] ?? null) !== 'assistant' || !is_string($message['content'] ?? null)) {
                continue;
            }
            $position = 0;
            foreach (TextToolProtocol::parse($message['content'])['calls'] as $call) {
                $position++;
                $result = $index + $position;
                $head = "<tool_response>\n{$call->name}:\n";
                $content = $this->messages[$result]['content'] ?? null;
                if (!self::isLoadOf($call->name, $call->arguments, $name) || !is_string($content) || !str_starts_with($content, $head)
                    || str_starts_with(substr($content, strlen($head)), 'ERROR: ')) {
                    continue;
                }
                $this->messages[$result]['content'] = TextToolProtocol::formatResult('skill', $line);
                $count++;
            }
        }

        return $count;
    }

    private static function isLoadOf(mixed $tool, mixed $arguments, string $name): bool
    {
        $args = is_string($arguments) ? Json::object($arguments) : $arguments;

        return $tool === 'skill' && is_array($args) && ($args['name'] ?? null) === $name && ($args['unload'] ?? false) !== true;
    }
}
