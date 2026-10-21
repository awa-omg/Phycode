<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Tools\BaseTool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolResult;

final class McpToolIndex
{
    public function __construct(private readonly array $tools)
    {
    }

    public function all(): array
    {
        return $this->tools;
    }

    public function find(string $name): ?McpTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name || $tool->definition()['name'] === $name) {
                return $tool;
            }
        }

        return null;
    }

    public function search(string $query, int $limit): array
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: []));
        $scored = [];
        foreach ($this->tools as $tool) {
            $name = mb_strtolower($tool->name());
            $description = mb_strtolower($tool->description());
            $score = $words === [] ? 1 : 0;
            foreach ($words as $word) {
                $score += (str_contains($name, $word) ? 3 : 0) + (str_contains($description, $word) ? 1 : 0);
            }
            if ($score > 0) {
                $scored[] = [$score, $tool];
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1]->name()] <=> [$a[0], $b[1]->name()]);

        return array_map(static fn (array $pair): McpTool => $pair[1], array_slice($scored, 0, $limit));
    }

    public function allTrusted(): bool
    {
        foreach ($this->tools as $tool) {
            if (!$tool->autoApproved()) {
                return false;
            }
        }

        return $this->tools !== [];
    }

    public function tools(): array
    {
        return [new class ($this) extends BaseTool {
            public function __construct(private readonly McpToolIndex $index)
            {
            }

            public function name(): string
            {
                return 'mcp_search';
            }

            public function description(): string
            {
                return 'Finds tools of the connected MCP servers (' . count($this->index->all()) . ' in all) by words of their names and descriptions, and shows their '
                    . 'input schemas. Use it before mcp_call to learn which tools exist and what arguments they take.';
            }

            public function parameters(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Words to look for, such as "github issue"; empty lists the tools'],
                        'limit' => ['type' => 'integer', 'description' => 'How many tools to show (default 8, at most 25)'],
                    ],
                    'required' => ['query'],
                ];
            }

            public function category(): ToolCategory
            {
                return ToolCategory::Read;
            }

            public function execute(array $args, ToolContext $context): ToolResult
            {
                $limit = max(1, min(25, is_int($args['limit'] ?? null) ? $args['limit'] : 8));
                $found = $this->index->search((string) ($args['query'] ?? ''), $limit);
                if ($found === []) {
                    return ToolResult::ok('no tool matches; there are ' . count($this->index->all()) . ' tools in all, and an empty query lists them');
                }
                $blocks = [];
                foreach ($found as $tool) {
                    $schema = Json::encode($tool->parameters());
                    $blocks[] = $tool->name() . ' - ' . trim(preg_replace('/\s+/', ' ', $tool->description()) ?? '') . "\n  arguments: " . (strlen($schema) > 2000 ? substr($schema, 0, 2000) . '...' : $schema);
                }

                return ToolResult::ok(implode("\n\n", $blocks) . "\n\n" . count($found) . ' of ' . count($this->index->all()) . ' tools shown; call one with mcp_call.');
            }
        }, new class ($this) extends BaseTool {
            public function __construct(private readonly McpToolIndex $index)
            {
            }

            public function name(): string
            {
                return 'mcp_call';
            }

            public function description(): string
            {
                return 'Calls a tool of an MCP server by the name that mcp_search shows (mcp__server__tool), with its arguments as an object.';
            }

            public function parameters(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'tool' => ['type' => 'string', 'description' => 'The name of the tool, as mcp_search shows it'],
                        'arguments' => ['type' => 'object', 'description' => 'The arguments of the tool, by their names', 'additionalProperties' => true],
                    ],
                    'required' => ['tool'],
                ];
            }

            public function category(): ToolCategory
            {
                return ToolCategory::Exec;
            }

            public function autoApproved(): bool
            {
                return $this->index->allTrusted();
            }

            public function summarize(array $args): string
            {
                $tool = is_string($args['tool'] ?? null) ? $this->index->find($args['tool']) : null;
                $arguments = is_array($args['arguments'] ?? null) ? $args['arguments'] : [];

                return $tool !== null ? $tool->summarize($arguments) : 'mcp_call ' . (is_string($args['tool'] ?? null) ? $args['tool'] : '?');
            }

            public function validate(array $args, ToolContext $context): void
            {
                $tool = is_string($args['tool'] ?? null) ? $this->index->find($args['tool']) : null;
                if ($tool === null) {
                    throw new PhycodeException('there is no MCP tool with that name; find it with mcp_search');
                }
                if (isset($args['arguments']) && !is_array($args['arguments'])) {
                    throw new PhycodeException('arguments must be an object');
                }
                $tool->validate(is_array($args['arguments'] ?? null) ? $args['arguments'] : [], $context);
            }

            public function execute(array $args, ToolContext $context): ToolResult
            {
                $tool = $this->index->find((string) $args['tool']);

                return $tool === null
                    ? ToolResult::fail('there is no MCP tool with that name; find it with mcp_search')
                    : $tool->execute(is_array($args['arguments'] ?? null) ? $args['arguments'] : [], $context);
            }
        }];
    }
}
