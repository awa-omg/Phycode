<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Agent\Hooks;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class McpPrompts
{
    private array $commands = [];

    public function __construct(array $connections, array $prompts, array $taken = [])
    {
        foreach ($prompts as $server => $list) {
            foreach ($list as $prompt) {
                $word = '/mcp__' . self::safe($server) . '__' . self::safe($prompt['name']);
                if (!in_array($word, $taken, true) && !isset($this->commands[$word])) {
                    $this->commands[$word] = ['connection' => $connections[$server], 'prompt' => $prompt];
                }
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->commands === [];
    }

    public function commands(): array
    {
        $commands = [];
        foreach ($this->commands as $word => $entry) {
            $arguments = array_map(static fn (array $argument): string => $argument['required'] ? strtoupper($argument['name']) : '[' . strtoupper($argument['name']) . ']', $entry['prompt']['arguments']);
            $description = $entry['prompt']['description'] !== '' ? $entry['prompt']['description'] : 'prompt of the MCP server ' . $entry['connection']->name();
            $commands[] = ['name' => trim($word . ' ' . implode(' ', $arguments)), 'description' => Text::oneLine($description, 120)];
        }

        return $commands;
    }

    public function match(string $line): ?string
    {
        $word = (string) strtok($line, " \t");

        return isset($this->commands[$word]) ? $word : null;
    }

    public function render(string $line): string
    {
        $word = $this->match($line) ?? throw new PhycodeException('not a prompt of an MCP server');
        $entry = $this->commands[$word];
        $arguments = self::arguments(trim(substr($line, strlen($word))), $entry['prompt']['arguments']);
        foreach ($entry['prompt']['arguments'] as $declared) {
            if ($declared['required'] && !isset($arguments[$declared['name']])) {
                throw new PhycodeException("{$word} needs the argument {$declared['name']}: {$word} " . $declared['name'] . '=VALUE');
            }
        }
        $result = $entry['connection']->getPrompt($entry['prompt']['name'], $arguments);
        $parts = [];
        foreach ($result['messages'] as $message) {
            $text = self::content($message['content']);
            if ($text !== '') {
                $parts[] = $message['role'] === 'assistant' ? "[the assistant said]\n{$text}" : $text;
            }
        }
        if ($parts === []) {
            throw new PhycodeException("the prompt {$entry['prompt']['name']} of the MCP server {$entry['connection']->name()} has no text");
        }

        return implode("\n\n", $parts);
    }

    private static function arguments(string $text, array $declared): array
    {
        if ($text === '') {
            return [];
        }
        $names = array_column($declared, 'name');
        $named = [];
        $loose = [];
        foreach (Hooks::split($text) as $word) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_.-]*)=(.*)$/s', $word, $match) === 1 && in_array($match[1], $names, true)) {
                $named[$match[1]] = $match[2];
            } else {
                $loose[] = $word;
            }
        }
        $free = array_values(array_filter($names, static fn (string $name): bool => !isset($named[$name])));
        foreach ($free as $index => $name) {
            if ($loose === []) {
                break;
            }
            $named[$name] = $index === count($free) - 1 ? implode(' ', $loose) : array_shift($loose);
            if ($index === count($free) - 1) {
                $loose = [];
            }
        }

        return $named;
    }

    private static function content(array $block): string
    {
        $type = $block['type'] ?? '';
        if ($type === 'text' && is_string($block['text'] ?? null)) {
            return $block['text'];
        }
        if ($type === 'resource' && is_array($block['resource'] ?? null)) {
            $resource = $block['resource'];
            $uri = is_string($resource['uri'] ?? null) ? $resource['uri'] : 'resource';

            return is_string($resource['text'] ?? null) ? "[{$uri}]\n{$resource['text']}" : "[{$uri}: not text, not shown]";
        }
        if ($type === 'resource_link' && is_string($block['uri'] ?? null)) {
            return '[resource: ' . $block['uri'] . ']';
        }
        if (in_array($type, ['image', 'audio'], true)) {
            return "[{$type}" . (is_string($block['mimeType'] ?? null) ? ': ' . $block['mimeType'] : '') . ', not sent]';
        }

        return '';
    }

    private static function safe(string $part): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $part);
    }
}
