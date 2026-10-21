<?php

declare(strict_types=1);

namespace Phycode\Llm\Responses;

use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\ToolCall;
use Phycode\Support\Json;

final class ResponsesReply
{
    private array $calls = [];
    private string $text = '';
    private string $reasoning = '';
    private array $replay = [];
    private array $streamed = [];

    private ?string $status = null;
    private ?string $reason = null;
    private ?array $usage = null;
    private ?string $error = null;

    public function consume(string $payload, ChatOptions $options): bool
    {
        $event = Json::object($payload);
        if ($event === null) {
            return true;
        }

        switch (self::string($event['type'] ?? null)) {
            case 'response.output_item.added':
                $item = self::object($event['item'] ?? null);
                if (($item['type'] ?? null) === 'function_call') {
                    $this->updateCall(self::string($item['id'] ?? null), ['call_id' => self::string($item['call_id'] ?? null), 'name' => self::string($item['name'] ?? null), 'arguments' => '']);
                }
                break;
            case 'response.output_text.delta':
                $text = self::string($event['delta'] ?? null);
                $this->streamed[self::string($event['item_id'] ?? null)] = true;
                $this->text .= $text;
                if ($text !== '') {
                    $options->emit('text', $text);
                }
                break;
            case 'response.reasoning_summary_text.delta':
            case 'response.reasoning_text.delta':
                $reasoning = self::string($event['delta'] ?? null);
                $this->reasoning .= $reasoning;
                if ($reasoning !== '') {
                    $options->emit('reasoning', $reasoning);
                }
                break;
            case 'response.function_call_arguments.delta':
                $id = self::string($event['item_id'] ?? null);
                $this->updateCall($id, ['arguments' => ($this->calls[$id]['arguments'] ?? '') . self::string($event['delta'] ?? null)]);
                break;
            case 'response.function_call_arguments.done':
                $this->updateCall(self::string($event['item_id'] ?? null), ['arguments' => self::string($event['arguments'] ?? null)]);
                break;
            case 'response.output_item.done':
                $this->done(self::object($event['item'] ?? null), $options);
                break;
            case 'response.completed':
                $this->finish('completed', self::object($event['response'] ?? null));
                break;
            case 'response.incomplete':
                $this->finish('incomplete', self::object($event['response'] ?? null));
                break;
            case 'response.failed':
                $error = self::object(self::object($event['response'] ?? null)['error'] ?? null);
                $this->error = self::string($error['message'] ?? null) ?: 'the response failed';

                return false;
            case 'error':
                $this->error = self::string($event['message'] ?? null) ?: 'unknown error';

                return false;
        }

        return true;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function reasoning(): string
    {
        return $this->reasoning;
    }

    public function calls(): array
    {
        $calls = [];
        foreach ($this->calls as $id => $call) {
            if ($call['name'] === '') {
                continue;
            }
            $calls[] = new ToolCall(
                $call['call_id'] !== '' ? $call['call_id'] : (string) $id,
                $call['name'],
                $call['arguments'] !== '' ? $call['arguments'] : '{}',
            );
        }

        return $calls;
    }

    public function replay(): array
    {
        return $this->replay;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function usage(): Usage
    {
        $usage = $this->usage ?? [];
        $details = self::object($usage['input_tokens_details'] ?? null);

        return new Usage(
            promptTokens: is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            completionTokens: is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
            cachedTokens: is_int($details['cached_tokens'] ?? null) ? $details['cached_tokens'] : null,
        );
    }

    private function done(?array $item, ChatOptions $options): void
    {
        $id = self::string($item['id'] ?? null);
        switch ($item['type'] ?? null) {
            case 'function_call':
                $arguments = self::string($item['arguments'] ?? null);
                $fields = ['call_id' => self::string($item['call_id'] ?? null), 'name' => self::string($item['name'] ?? null)];
                if ($arguments !== '') {
                    $fields['arguments'] = $arguments;
                }
                $this->updateCall($id, $fields);
                break;
            case 'reasoning':
                $this->replay[] = $item;
                break;
            case 'message':
                if (!isset($this->streamed[$id])) {
                    foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                        $text = self::string(is_array($part) ? ($part['text'] ?? null) : null);
                        $this->text .= $text;
                        if ($text !== '') {
                            $options->emit('text', $text);
                        }
                    }
                }
                break;
        }
    }

    private function finish(string $status, ?array $response): void
    {
        $this->status = $status;
        $details = self::object($response['incomplete_details'] ?? null);
        $this->reason = is_string($details['reason'] ?? null) ? $details['reason'] : null;
        $usage = $response['usage'] ?? null;
        $this->usage = is_array($usage) ? $usage : null;
    }

    private function updateCall(string $id, array $fields): void
    {
        $this->calls[$id] = array_merge($this->calls[$id] ?? ['call_id' => '', 'name' => '', 'arguments' => ''], $fields);
    }

    private static function object(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
