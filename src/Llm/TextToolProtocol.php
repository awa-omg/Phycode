<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Support\Json;

final class TextToolProtocol
{
    private const PATTERN = '#<tool_call>\s*(.*?)\s*</tool_call>#s';

    public static function parse(string $text): array
    {
        $calls = [];
        $errors = [];
        preg_match_all(self::PATTERN, $text, $matches);

        foreach ($matches[1] as $number => $json) {
            $data = Json::object($json) ?? Json::object(self::repair($json));
            $name = $data['name'] ?? null;
            if (!is_string($name) || $name === '') {
                $errors[] = 'tool call #' . ($number + 1) . ' is not valid JSON with a "name" field';
                continue;
            }

            $arguments = $data['arguments'] ?? $data['parameters'] ?? [];
            if (is_string($arguments)) {
                $arguments = Json::object($arguments) ?? Json::object(self::repair($arguments));
            }
            if (!is_array($arguments)) {
                $errors[] = "the arguments of tool call '{$name}' must be a JSON object";
                continue;
            }
            $calls[] = new ToolCall('text_' . bin2hex(random_bytes(4)), $name, Json::encode($arguments));
        }

        if (substr_count($text, '<tool_call>') > substr_count($text, '</tool_call>')) {
            $errors[] = 'a <tool_call> block is not closed with </tool_call>';
        }

        return ['calls' => $calls, 'errors' => $errors];
    }

    public static function stripCalls(string $text): string
    {
        return trim((string) preg_replace(self::PATTERN, '', $text));
    }

    public static function instructions(string $signatures): string
    {
        return "To use a tool, reply with exactly one block per call, in this form:\n"
            . "<tool_call>\n"
            . "{\"name\": \"read\", \"arguments\": {\"path\": \"README.md\"}}\n"
            . "</tool_call>\n"
            . "Stop after the block and wait. The result comes back inside a <tool_response> block.\n"
            . "Tools:\n"
            . $signatures;
    }

    public static function formatResult(string $name, string $content): string
    {
        return "<tool_response>\n{$name}:\n{$content}\n</tool_response>";
    }

    private static function repair(string $json): string
    {
        return (string) preg_replace('/,\s*([}\]])/', '$1', $json);
    }
}
