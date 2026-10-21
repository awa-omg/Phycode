<?php

declare(strict_types=1);

namespace Phycode\Llm\Responses;

use Phycode\Llm\Image;

final class ResponsesInput
{
    public const EXTRA = 'openai-responses';

    public static function instructions(array $messages): ?string
    {
        $parts = [];
        foreach ($messages as $message) {
            $text = self::string($message['content'] ?? null);
            if (($message['role'] ?? null) === 'system' && $text !== '') {
                $parts[] = $text;
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    public static function items(array $messages, bool $vision = false): array
    {
        $items = [];
        $calls = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? null;
            if ($role === 'user') {
                $images = Image::of($message);
                if ($images !== [] && $vision) {
                    $parts = array_map(static fn (Image $image): array => ['type' => 'input_image', 'image_url' => 'data:' . $image->mime . ';base64,' . $image->data], $images);
                    $text = self::string($message['content'] ?? null);
                    $items[] = ['type' => 'message', 'role' => 'user', 'content' => [...($text === '' ? [] : [['type' => 'input_text', 'text' => $text]]), ...$parts]];
                    continue;
                }
                $text = $images === [] ? self::string($message['content'] ?? null) : Image::textWithPlaceholders($message);
                if ($text !== '') {
                    $items[] = ['type' => 'message', 'role' => 'user', 'content' => $text];
                }
            } elseif ($role === 'assistant') {
                $replay = is_array($message['extra'][self::EXTRA] ?? null) ? $message['extra'][self::EXTRA] : [];
                foreach ($replay as $item) {
                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }
                $text = self::string($message['content'] ?? null);
                if ($text !== '') {
                    $items[] = ['type' => 'message', 'role' => 'assistant', 'content' => $text];
                }
                foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
                    $function = is_array($call['function'] ?? null) ? $call['function'] : [];
                    $name = self::string($function['name'] ?? null);
                    $id = self::string($call['id'] ?? null);
                    if ($name === '' || $id === '') {
                        continue;
                    }
                    $arguments = self::string($function['arguments'] ?? null);
                    $items[] = ['type' => 'function_call', 'call_id' => $id, 'name' => $name, 'arguments' => $arguments === '' ? '{}' : $arguments];
                    $calls[$id] = true;
                }
            } elseif ($role === 'tool') {
                $id = self::string($message['tool_call_id'] ?? null);
                if (isset($calls[$id])) {
                    $items[] = ['type' => 'function_call_output', 'call_id' => $id, 'output' => self::string($message['content'] ?? null)];
                }
            }
        }

        return $items;
    }

    public static function tools(array $tools): array
    {
        $specs = [];
        foreach ($tools as $tool) {
            $function = is_array($tool['function'] ?? null) ? $tool['function'] : [];
            $parameters = is_array($function['parameters'] ?? null) ? $function['parameters'] : [];
            $parameters += ['type' => 'object', 'properties' => []];
            if ($parameters['properties'] === []) {
                $parameters['properties'] = new \stdClass();
            }
            $specs[] = [
                'type' => 'function',
                'name' => self::string($function['name'] ?? null),
                'description' => self::string($function['description'] ?? null),
                'parameters' => $parameters,
            ];
        }

        return $specs;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
