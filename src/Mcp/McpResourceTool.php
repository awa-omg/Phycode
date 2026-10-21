<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Support\PhycodeException;
use Phycode\Support\Text;
use Phycode\Tools\BaseTool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolResult;

final class McpResourceTool extends BaseTool
{
    private const MAX_LISTED = 200;

    public function __construct(private readonly array $connections, private readonly array $trusted = [])
    {
    }

    public function name(): string
    {
        return 'mcp_resource';
    }

    public function description(): string
    {
        return 'Lists or reads the resources that MCP servers offer (documents, files, records). action list shows the resources and the '
            . 'templates of the addresses of a server, or of all of them; action read returns the contents of the resource with the given uri. '
            . 'Servers with resources: ' . implode(', ', array_keys($this->connections)) . '.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'read'], 'description' => 'list the resources, or read one'],
                'server' => ['type' => 'string', 'description' => 'The MCP server; needed to read, optional to list'],
                'uri' => ['type' => 'string', 'description' => 'The uri of the resource to read, as list shows it'],
            ],
            'required' => ['action'],
        ];
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Exec;
    }

    public function autoApproved(): bool
    {
        foreach (array_keys($this->connections) as $server) {
            if (!($this->trusted[$server] ?? false)) {
                return false;
            }
        }

        return $this->connections !== [];
    }

    public function validate(array $args, ToolContext $context): void
    {
        $action = $args['action'] ?? null;
        if (!in_array($action, ['list', 'read'], true)) {
            throw new PhycodeException('action must be list or read');
        }
        $server = $args['server'] ?? null;
        if ($server !== null && (!is_string($server) || !isset($this->connections[$server]))) {
            throw new PhycodeException('unknown MCP server; servers with resources: ' . implode(', ', array_keys($this->connections)));
        }
        if ($action === 'read' && (!is_string($server) || !is_string($args['uri'] ?? null) || trim($args['uri']) === '')) {
            throw new PhycodeException('read needs the server and the uri of the resource');
        }
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        try {
            return $args['action'] === 'read'
                ? $this->read($this->connections[(string) $args['server']], trim((string) $args['uri']))
                : $this->listing(isset($args['server']) ? [(string) $args['server'] => $this->connections[(string) $args['server']]] : $this->connections);
        } catch (PhycodeException $error) {
            return ToolResult::fail($error->getMessage());
        }
    }

    private function listing(array $connections): ToolResult
    {
        $lines = [];
        foreach ($connections as $name => $connection) {
            foreach ($connection->listResources() as $resource) {
                $lines[] = "{$name}  {$resource['uri']}  " . Text::oneLine($resource['name'] . ($resource['description'] !== '' ? ' - ' . $resource['description'] : '') . ($resource['mimeType'] !== '' ? " ({$resource['mimeType']})" : ''), 160);
            }
            foreach ($connection->listResourceTemplates() as $template) {
                $lines[] = "{$name}  {$template['uriTemplate']}  template: " . Text::oneLine($template['name'] . ($template['description'] !== '' ? ' - ' . $template['description'] : ''), 160);
            }
        }
        if ($lines === []) {
            return ToolResult::ok('no resources');
        }
        $more = count($lines) > self::MAX_LISTED ? "\n[" . (count($lines) - self::MAX_LISTED) . ' more not shown]' : '';

        return ToolResult::ok(implode("\n", array_slice($lines, 0, self::MAX_LISTED)) . $more);
    }

    private function read(McpConnection $connection, string $uri): ToolResult
    {
        $parts = [];
        foreach ($connection->readResource($uri) as $content) {
            $label = is_string($content['uri'] ?? null) && $content['uri'] !== $uri ? "[{$content['uri']}]\n" : '';
            if (is_string($content['text'] ?? null)) {
                $parts[] = $label . $content['text'];
            } elseif (is_string($content['blob'] ?? null)) {
                $size = intdiv(strlen($content['blob']) * 3, 4) - substr_count(substr($content['blob'], -2), '=');
                $parts[] = $label . '[binary ' . (is_string($content['mimeType'] ?? null) ? $content['mimeType'] : 'data') . ", about {$size} bytes, not shown]";
            }
        }

        return $parts === [] ? ToolResult::fail("the resource {$uri} has no contents") : ToolResult::ok(implode("\n\n", $parts));
    }
}
