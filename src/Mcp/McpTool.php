<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Support\Json;
use Phycode\Support\JsonSchema;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;
use Phycode\Tools\BaseTool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolResult;

final class McpTool extends BaseTool
{
    private const MAX_NAME = 64;

    private readonly string $toolName;
    private ?bool $checkable = null;

    public function __construct(
        private readonly McpConnection $client,
        private readonly array $definition,
        private readonly bool $trusted = false,
    ) {
        $this->toolName = self::nameFor($client->name(), $definition['name']);
    }

    public function autoApproved(): bool
    {
        return $this->trusted;
    }

    public function definition(): array
    {
        return $this->definition;
    }

    public static function discover(McpConnection $client): array
    {
        return array_map(
            static fn (array $definition): self => new self($client, $definition),
            $client->listTools(),
        );
    }

    public static function nameFor(string $server, string $tool): string
    {
        $name = 'mcp__' . self::safe($server) . '__' . self::safe($tool);
        if (strlen($name) <= self::MAX_NAME) {
            return $name;
        }

        return substr($name, 0, self::MAX_NAME - 9) . '_' . substr(hash('sha256', $name), 0, 8);
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        $description = trim($this->definition['description']);

        return $description !== '' ? $description : "Tool {$this->definition['name']} of the MCP server {$this->client->name()}.";
    }

    public function parameters(): array
    {
        $schema = $this->definition['inputSchema'];
        $schema['type'] = 'object';
        if (!is_array($schema['properties'] ?? null) || $schema['properties'] === []) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Exec;
    }

    public function summarize(array $args): string
    {
        $parts = [];
        foreach ($args as $key => $value) {
            $parts[] = $key . '=' . Text::oneLine(is_string($value) ? $value : Json::encode($value), 80);
        }

        return Text::oneLine($this->name() . ($parts === [] ? '' : ' ' . implode(' ', $parts)), 300);
    }

    public function validate(array $args, ToolContext $context): void
    {
        $schema = $this->definition['inputSchema'];
        if ($this->checkable === null) {
            try {
                JsonSchema::check($schema);
                $this->checkable = $schema !== [];
            } catch (PhycodeException) {
                $this->checkable = false;
            }
        }
        if ($this->checkable) {
            $errors = JsonSchema::errors($args, $schema);
            if ($errors !== []) {
                throw new PhycodeException('invalid arguments: ' . implode('; ', $errors));
            }

            return;
        }
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($required as $key) {
            if (is_string($key) && !array_key_exists($key, $args)) {
                throw new PhycodeException("missing required argument '{$key}'");
            }
        }
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        try {
            $result = $this->client->callTool($this->definition['name'], self::arguments($args, $this->definition['inputSchema']));
        } catch (PhycodeException $error) {
            return ToolResult::fail($error->getMessage());
        }

        $text = self::text($result['content'] ?? null);
        if ($text === '' && array_key_exists('structuredContent', $result)) {
            $text = Json::encode($result['structuredContent']);
        }
        if (($result['isError'] ?? false) === true) {
            return ToolResult::fail($text === '' ? 'the MCP tool reported an error' : $text);
        }

        return ToolResult::ok($text);
    }

    private static function safe(string $part): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $part);
    }

    public static function text(mixed $content): string
    {
        if (!is_array($content)) {
            return '';
        }

        $lines = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            $lines[] = ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)
                ? $block['text']
                : self::summary($block);
        }

        return implode("\n", $lines);
    }

    private static function summary(array $block): string
    {
        $type = is_string($block['type'] ?? null) ? $block['type'] : 'unknown';
        $details = [];
        $sources = [$block, is_array($block['resource'] ?? null) ? $block['resource'] : []];
        foreach ($sources as $source) {
            foreach (['name', 'uri', 'mimeType'] as $key) {
                if (is_string($source[$key] ?? null) && $source[$key] !== '') {
                    $details[] = $source[$key];
                }
            }
        }
        if (is_string($block['data'] ?? null)) {
            $details[] = 'about ' . self::decodedSize($block['data']) . ' bytes';
        }

        $line = '[' . $type . ($details === [] ? '' : ': ' . implode(', ', $details)) . ']';

        return Text::oneLine((string) preg_replace('/\p{Cc}+/u', ' ', $line), 300);
    }

    private static function decodedSize(string $base64): int
    {
        return intdiv(strlen($base64) * 3, 4) - substr_count(substr($base64, -2), '=');
    }

    private static function arguments(array $args, array $schema): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($args as $key => $value) {
            if (is_array($properties[$key] ?? null)) {
                $args[$key] = self::shape($value, $properties[$key]);
            }
        }

        return $args;
    }

    private static function shape(mixed $value, array $schema): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $types = (array) ($schema['type'] ?? []);
        if (in_array('object', $types, true) || ($types === [] && is_array($schema['properties'] ?? null))) {
            if ($value === []) {
                return new \stdClass();
            }
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ($value as $key => $item) {
                if (is_array($properties[$key] ?? null)) {
                    $value[$key] = self::shape($item, $properties[$key]);
                }
            }

            return $value;
        }

        if (in_array('array', $types, true) && is_array($schema['items'] ?? null) && array_is_list($value)) {
            foreach ($value as $index => $item) {
                $value[$index] = self::shape($item, $schema['items']);
            }
        }

        return $value;
    }
}
