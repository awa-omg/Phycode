<?php

declare(strict_types=1);

namespace Phycode\Llm\Anthropic;

use Phycode\Llm\Image;

final class AnthropicMessages
{
    public const EXTRA = 'anthropic';

    public static function system(array $messages): ?string
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

    public static function convert(array $messages, bool $withTools, bool $vision = false): array
    {
        $converted = [];
        $calls = [];
        foreach ($messages as $message) {
            [$role, $blocks] = match ($message['role'] ?? null) {
                'assistant' => ['assistant', self::assistantBlocks($message, $withTools, $calls)],
                'tool' => ['user', self::resultBlocks($message, $withTools, $calls)],
                'user' => ['user', self::userBlocks($message, $vision)],
                default => [null, []],
            };
            if ($role === null || $blocks === [] || ($converted === [] && $role !== 'user')) {
                continue;
            }

            $last = array_key_last($converted);
            if ($last !== null && $converted[$last]['role'] === $role) {
                $converted[$last]['content'] = [...$converted[$last]['content'], ...$blocks];
            } else {
                $converted[] = ['role' => $role, 'content' => $blocks];
            }
        }

        return $converted;
    }

    public static function tools(array $tools): array
    {
        $specs = [];
        foreach ($tools as $tool) {
            $function = is_array($tool['function'] ?? null) ? $tool['function'] : [];
            $schema = is_array($function['parameters'] ?? null) ? $function['parameters'] : [];
            $schema += ['type' => 'object', 'properties' => []];
            if ($schema['properties'] === []) {
                $schema['properties'] = new \stdClass();
            }
            $spec = ['name' => self::string($function['name'] ?? null), 'input_schema' => $schema];
            if (is_string($function['description'] ?? null)) {
                $spec['description'] = $function['description'];
            }
            $specs[] = $spec;
        }

        return $specs;
    }

    private static function assistantBlocks(array $message, bool $withTools, array &$calls): array
    {
        $replay = is_array($message['extra'][self::EXTRA] ?? null) ? $message['extra'][self::EXTRA] : [];
        $blocks = array_values(array_filter($replay, static fn (mixed $block): bool => is_array($block)));

        $text = self::string($message['content'] ?? null);
        $lines = $text === '' ? [] : [$text];
        $uses = [];
        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            $name = self::string($function['name'] ?? null);
            if ($name === '') {
                continue;
            }
            $arguments = self::string($function['arguments'] ?? null);
            $arguments = $arguments === '' ? '{}' : $arguments;
            $id = self::string($call['id'] ?? null);
            if ($withTools) {
                $uses[] = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => self::input($arguments)];
                $calls[$id] = true;
            } else {
                $lines[] = "[tool call {$name} {$arguments}]";
            }
        }
        if ($lines !== []) {
            $blocks[] = ['type' => 'text', 'text' => implode("\n", $lines)];
        }

        return [...$blocks, ...$uses];
    }

    private static function resultBlocks(array $message, bool $withTools, array $calls): array
    {
        $id = self::string($message['tool_call_id'] ?? null);
        $content = self::string($message['content'] ?? null);
        if (!$withTools) {
            return $content === '' ? [] : [['type' => 'text', 'text' => "[tool result]\n{$content}"]];
        }

        return isset($calls[$id]) ? [['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $content]] : [];
    }

    private static function userBlocks(array $message, bool $vision): array
    {
        $images = Image::of($message);
        if ($images === [] || !$vision) {
            return self::textBlock($images === [] ? ($message['content'] ?? null) : Image::textWithPlaceholders($message));
        }
        $blocks = array_map(static fn (Image $image): array => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image->mime, 'data' => $image->data]], $images);

        return [...$blocks, ...self::textBlock($message['content'] ?? null)];
    }

    private static function textBlock(mixed $text): array
    {
        $text = self::string($text);

        return $text === '' ? [] : [['type' => 'text', 'text' => $text]];
    }

    private static function input(string $arguments): \stdClass
    {
        $decoded = json_decode($arguments);

        return $decoded instanceof \stdClass ? $decoded : new \stdClass();
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
