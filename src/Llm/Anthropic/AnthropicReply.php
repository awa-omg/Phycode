<?php

declare(strict_types=1);

namespace Phycode\Llm\Anthropic;

use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\ToolCall;
use Phycode\Support\Json;

final class AnthropicReply
{
    private array $blocks = [];

    private ?string $stopReason = null;
    private ?string $explanation = null;
    private bool $ended = false;
    private ?string $error = null;

    private array $counts = ['input' => null, 'cacheCreation' => null, 'cacheRead' => null, 'output' => null];

    public function consume(string $payload, ChatOptions $options): bool
    {
        $event = Json::object($payload);
        if ($event === null) {
            return true;
        }

        switch ($event['type'] ?? null) {
            case 'message_start':
                $message = is_array($event['message'] ?? null) ? $event['message'] : [];
                $this->count(is_array($message['usage'] ?? null) ? $message['usage'] : []);
                break;
            case 'content_block_start':
                $this->start(self::index($event), is_array($event['content_block'] ?? null) ? $event['content_block'] : []);
                break;
            case 'content_block_delta':
                $this->delta(self::index($event), is_array($event['delta'] ?? null) ? $event['delta'] : [], $options);
                break;
            case 'content_block_stop':
                $this->stop(self::index($event));
                break;
            case 'message_delta':
                $delta = is_array($event['delta'] ?? null) ? $event['delta'] : [];
                if (is_string($delta['stop_reason'] ?? null)) {
                    $this->stopReason = $delta['stop_reason'];
                }
                $details = is_array($delta['stop_details'] ?? null) ? $delta['stop_details'] : [];
                $this->explanation = is_string($details['explanation'] ?? null) ? $details['explanation'] : null;
                $this->count(is_array($event['usage'] ?? null) ? $event['usage'] : []);
                break;
            case 'message_stop':
                $this->ended = true;
                break;
            case 'error':
                $error = is_array($event['error'] ?? null) ? $event['error'] : [];
                $this->error = is_string($error['message'] ?? null) ? $error['message'] : 'unknown error';

                return false;
        }

        return true;
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    public function refusal(): ?string
    {
        return $this->explanation;
    }

    public function ended(): bool
    {
        return $this->ended;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function text(): string
    {
        $text = '';
        foreach ($this->ordered() as $block) {
            if ($block['type'] === 'text') {
                $text .= self::string($block['text'] ?? null);
            }
        }

        return $text;
    }

    public function thinking(): string
    {
        $thinking = '';
        foreach ($this->ordered() as $block) {
            if ($block['type'] === 'thinking') {
                $thinking .= self::string($block['thinking'] ?? null);
            }
        }

        return $thinking;
    }

    public function calls(): array
    {
        $calls = [];
        foreach ($this->ordered() as $block) {
            if ($block['type'] === 'tool_use' && self::string($block['name'] ?? null) !== '') {
                $calls[] = new ToolCall(self::string($block['id'] ?? null), self::string($block['name'] ?? null), self::arguments($block));
            }
        }

        return $calls;
    }

    public function blocks(): array
    {
        return array_map(self::wire(...), $this->ordered());
    }

    public function replay(): array
    {
        return array_values(array_filter(
            $this->blocks(),
            static fn (array $block): bool => !in_array($block['type'], ['text', 'tool_use'], true),
        ));
    }

    public function usage(): Usage
    {
        $inputs = array_filter(
            [$this->counts['input'], $this->counts['cacheCreation'], $this->counts['cacheRead']],
            static fn (?int $count): bool => $count !== null,
        );

        return new Usage(
            promptTokens: $inputs === [] ? null : array_sum($inputs),
            completionTokens: $this->counts['output'],
            cachedTokens: $this->counts['cacheRead'],
            cacheWriteTokens: $this->counts['cacheCreation'],
        );
    }

    private function count(array $usage): void
    {
        $fields = ['input' => 'input_tokens', 'cacheCreation' => 'cache_creation_input_tokens', 'cacheRead' => 'cache_read_input_tokens', 'output' => 'output_tokens'];
        foreach ($fields as $key => $field) {
            if (is_int($usage[$field] ?? null)) {
                $this->counts[$key] = $usage[$field];
            }
        }
    }

    private function start(int $index, array $block): void
    {
        if ($index < 0) {
            return;
        }
        $type = self::string($block['type'] ?? null);
        $this->blocks[$index] = match ($type) {
            'text' => ['type' => 'text', 'text' => self::string($block['text'] ?? null)],
            'thinking' => ['type' => 'thinking', 'thinking' => '', 'signature' => ''],
            'tool_use', 'server_tool_use' => [
                'type' => $type,
                'id' => self::string($block['id'] ?? null),
                'name' => self::string($block['name'] ?? null),
                'input' => $block['input'] ?? [],
                'json' => '',
            ],
            default => $block,
        };
    }

    private function delta(int $index, array $delta, ChatOptions $options): void
    {
        if (!isset($this->blocks[$index])) {
            return;
        }
        $type = self::string($this->blocks[$index]['type'] ?? null);
        $kind = self::string($delta['type'] ?? null);

        if ($kind === 'text_delta' && $type === 'text') {
            $text = self::string($delta['text'] ?? null);
            $this->blocks[$index]['text'] .= $text;
            if ($text !== '') {
                $options->emit('text', $text);
            }
        } elseif ($kind === 'thinking_delta' && $type === 'thinking') {
            $thinking = self::string($delta['thinking'] ?? null);
            $this->blocks[$index]['thinking'] .= $thinking;
            if ($thinking !== '') {
                $options->emit('reasoning', $thinking);
            }
        } elseif ($kind === 'signature_delta' && $type === 'thinking') {
            $this->blocks[$index]['signature'] = self::string($delta['signature'] ?? null);
        } elseif ($kind === 'input_json_delta' && in_array($type, ['tool_use', 'server_tool_use'], true)) {
            $this->blocks[$index]['json'] .= self::string($delta['partial_json'] ?? null);
        }
    }

    private function stop(int $index): void
    {
        $block = $this->blocks[$index] ?? null;
        if ($block !== null && in_array($block['type'] ?? null, ['tool_use', 'server_tool_use'], true)) {
            $this->blocks[$index]['input'] = self::input($block);
        }
    }

    private function ordered(): array
    {
        $blocks = $this->blocks;
        ksort($blocks);

        return array_values(array_filter($blocks, static fn (array $block): bool => self::string($block['type'] ?? null) !== ''));
    }

    private static function wire(array $block): array
    {
        return match ($block['type']) {
            'text' => ['type' => 'text', 'text' => self::string($block['text'] ?? null)],
            'thinking' => ['type' => 'thinking', 'thinking' => self::string($block['thinking'] ?? null), 'signature' => self::string($block['signature'] ?? null)],
            'tool_use', 'server_tool_use' => ['type' => $block['type'], 'id' => self::string($block['id'] ?? null), 'name' => self::string($block['name'] ?? null), 'input' => self::input($block)],
            default => $block,
        };
    }

    private static function input(array $block): \stdClass
    {
        $json = self::string($block['json'] ?? null);
        if ($json !== '') {
            $decoded = json_decode($json);

            return $decoded instanceof \stdClass ? $decoded : new \stdClass();
        }
        $start = $block['input'] ?? null;

        return is_array($start) && $start !== [] ? (object) $start : new \stdClass();
    }

    private static function arguments(array $block): string
    {
        $json = self::string($block['json'] ?? null);

        return $json !== '' ? $json : Json::encode(self::input($block));
    }

    private static function index(array $event): int
    {
        return is_int($event['index'] ?? null) ? $event['index'] : -1;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
