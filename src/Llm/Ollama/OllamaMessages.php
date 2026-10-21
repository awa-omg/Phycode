<?php

declare(strict_types=1);

namespace Phycode\Llm\Ollama;

use Phycode\Llm\Image;

final class OllamaMessages
{
    public static function convert(array $messages, bool $vision = false): array
    {
        $names = [];
        $converted = [];
        foreach ($messages as $message) {
            $role = is_string($message['role'] ?? null) ? $message['role'] : 'user';
            $entry = ['role' => $role, 'content' => is_string($message['content'] ?? null) ? $message['content'] : ''];
            $images = $role === 'user' ? Image::of($message) : [];
            if ($images !== [] && $vision) {
                $entry['images'] = array_map(static fn (Image $image): string => $image->data, $images);
            } elseif ($images !== []) {
                $entry['content'] = Image::textWithPlaceholders($message);
            }

            if (is_array($message['tool_calls'] ?? null)) {
                $calls = [];
                foreach ($message['tool_calls'] as $call) {
                    $function = is_array($call['function'] ?? null) ? $call['function'] : [];
                    $name = is_string($function['name'] ?? null) ? $function['name'] : '';
                    if ($name === '') {
                        continue;
                    }
                    $names[is_string($call['id'] ?? null) ? $call['id'] : ''] = $name;
                    $calls[] = ['function' => ['name' => $name, 'arguments' => self::arguments($function['arguments'] ?? null)]];
                }
                if ($calls !== []) {
                    $entry['tool_calls'] = $calls;
                }
            }

            $callId = is_string($message['tool_call_id'] ?? null) ? $message['tool_call_id'] : '';
            if ($role === 'tool' && isset($names[$callId])) {
                $entry['tool_name'] = $names[$callId];
            }
            $converted[] = $entry;
        }

        return $converted;
    }

    private static function arguments(mixed $arguments): \stdClass
    {
        $decoded = is_string($arguments) ? json_decode($arguments) : null;

        return $decoded instanceof \stdClass ? $decoded : new \stdClass();
    }
}
