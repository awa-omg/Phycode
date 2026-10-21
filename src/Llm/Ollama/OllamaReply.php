<?php

declare(strict_types=1);

namespace Phycode\Llm\Ollama;

use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\ToolCall;
use Phycode\Support\Json;

final class OllamaReply
{
    private string $content = '';
    private string $reasoning = '';
    private array $calls = [];
    private ?\stdClass $final = null;
    private ?string $error = null;

    public function consume(string $line, ChatOptions $options): bool
    {
        try {
            $event = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return true;
        }
        if (!$event instanceof \stdClass) {
            return true;
        }
        if (isset($event->error)) {
            $this->error = is_string($event->error) ? $event->error : 'unknown error';

            return false;
        }

        $message = self::field($event, 'message');
        if ($message instanceof \stdClass) {
            $this->absorb($message, $options);
        }
        if (self::field($event, 'done') === true) {
            $this->final = $event;
        }

        return true;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function reasoning(): string
    {
        return $this->reasoning;
    }

    public function calls(): array
    {
        return $this->calls;
    }

    public function done(): bool
    {
        return $this->final !== null;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function doneReason(): ?string
    {
        $reason = self::field($this->final, 'done_reason');

        return is_string($reason) ? $reason : null;
    }

    public function usage(): Usage
    {
        $completion = self::integer($this->final, 'eval_count');
        $duration = self::integer($this->final, 'eval_duration');
        $speed = $completion !== null && $duration !== null && $completion > 0 && $duration > 0
            ? $completion / ($duration / 1e9)
            : null;

        return new Usage(
            promptTokens: self::integer($this->final, 'prompt_eval_count'),
            completionTokens: $completion,
            cachedTokens: self::integer($this->final, 'prompt_eval_cached_count'),
            tokensPerSecond: $speed,
        );
    }

    private function absorb(\stdClass $message, ChatOptions $options): void
    {
        $text = self::field($message, 'content');
        if (is_string($text) && $text !== '') {
            $this->content .= $text;
            $options->emit('text', $text);
        }
        $thinking = self::field($message, 'thinking');
        if (is_string($thinking) && $thinking !== '') {
            $this->reasoning .= $thinking;
            $options->emit('reasoning', $thinking);
        }
        $calls = self::field($message, 'tool_calls');
        if (is_array($calls)) {
            foreach ($calls as $call) {
                $this->addCall($call);
            }
        }
    }

    private function addCall(mixed $call): void
    {
        $function = $call instanceof \stdClass ? self::field($call, 'function') : null;
        $name = $function instanceof \stdClass ? self::field($function, 'name') : null;
        if (!is_string($name) || $name === '') {
            return;
        }

        $arguments = self::field($function, 'arguments');
        $text = match (true) {
            is_string($arguments) => $arguments,
            $arguments instanceof \stdClass => Json::encode($arguments),
            default => '{}',
        };
        $id = $call instanceof \stdClass ? self::field($call, 'id') : null;
        $this->calls[] = new ToolCall(
            is_string($id) && $id !== '' ? $id : 'call_' . (count($this->calls) + 1),
            $name,
            $text,
        );
    }

    private static function field(?\stdClass $object, string $key): mixed
    {
        return $object?->{$key} ?? null;
    }

    private static function integer(?\stdClass $object, string $key): ?int
    {
        $value = self::field($object, $key);

        return is_int($value) ? $value : null;
    }
}
