<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;
use Phycode\Support\Vault;
use Phycode\Tools\Tool;

final class McpServers
{
    private const SEARCH_AFTER_TOOLS = 20;
    private const SEARCH_AFTER_BYTES = 30000;
    private const INSTRUCTION_BYTES = 2000;

    private bool $connected = false;
    private array $started = [];
    private array $problems = [];
    private array $warnings = [];
    private array $tools = [];
    private array $mcpTools = [];
    private array $counts = [];
    private bool $searching = false;
    private ?McpPrompts $prompts = null;

    public function __construct(
        private readonly array $servers,
        private readonly string $cwd,
        private readonly Vault $vault,
        private readonly string $toolSearch = 'auto',
        private readonly float $timeout = 10.0,
        private readonly string $clientVersion = '0.1.0',
        private readonly array $taken = [],
    ) {
    }

    public static function build(string $name, array $server, string $cwd, Vault $vault, float $timeout, string $clientVersion): McpConnection
    {
        if (isset($server['url'])) {
            $auth = match (true) {
                isset($server['bearer']) && is_string($server['bearer']) => new McpToken($server['bearer']),
                isset($server['token_env']) => new McpBearer((string) $server['token_env']),
                is_array($server['oauth'] ?? null) => self::oauth($name, $server, $vault),
                default => null,
            };

            return new McpHttpClient($name, (string) $server['url'], is_array($server['headers'] ?? null) ? $server['headers'] : [], $auth, $timeout, $clientVersion);
        }

        return new McpClient($name, $server['command'], $cwd, is_array($server['env'] ?? null) ? $server['env'] : [], $clientVersion, $timeout);
    }

    public static function oauth(string $name, array $server, Vault $vault): ?McpOAuth
    {
        return isset($server['url'], $server['oauth']) && is_array($server['oauth']) ? new McpOAuth($name, (string) $server['url'], $vault, $server['oauth']) : null;
    }

    public function isEmpty(): bool
    {
        foreach ($this->servers as $server) {
            if ($server['enabled']) {
                return false;
            }
        }

        return true;
    }

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }
        $this->connected = true;
        $pending = [];
        foreach ($this->servers as $name => $server) {
            if (!$server['enabled']) {
                continue;
            }
            try {
                $connection = self::build((string) $name, $server, $this->cwd, $this->vault, $this->timeout, $this->clientVersion);
                $connection->prepare();
                $pending[(string) $name] = $connection;
            } catch (PhycodeException $error) {
                $this->problems[(string) $name] = $error->getMessage();
            }
        }

        $tools = [];
        $resources = [];
        $prompts = [];
        foreach ($pending as $name => $connection) {
            try {
                $connection->start();
                $listed = $connection->listTools();
                $listedPrompts = $connection->supports('prompts') ? $connection->listPrompts() : [];
                $resourceCount = 0;
                if ($connection->supports('resources')) {
                    $resources[$name] = $connection;
                    $resourceCount = 1;
                }
            } catch (PhycodeException $error) {
                $this->problems[$name] = $error->getMessage();
                $connection->close();
                continue;
            }
            $this->started[$name] = $connection;
            $trusted = ($this->servers[$name]['approval'] ?? 'ask') === 'allow';
            foreach ($listed as $definition) {
                $tools[] = new McpTool($connection, $definition, $trusted);
            }
            if ($listedPrompts !== []) {
                $prompts[$name] = $listedPrompts;
            }
            if ($connection instanceof McpHttpClient) {
                foreach ($connection->skipped() as $skipped) {
                    $this->warnings[] = "MCP server {$name}: the tool {$skipped}; it is left out";
                }
            }
            $this->counts[$name] = ['tools' => count($listed), 'resources' => $resourceCount, 'prompts' => count($listedPrompts)];
        }

        $this->mcpTools = $tools;
        $bytes = array_sum(array_map(static fn (McpTool $tool): int => strlen(Json::encode($tool->parameters())) + strlen($tool->description()), $tools));
        $this->searching = $tools !== [] && ($this->toolSearch === 'on' || ($this->toolSearch === 'auto' && (count($tools) > self::SEARCH_AFTER_TOOLS || $bytes > self::SEARCH_AFTER_BYTES)));
        $this->tools = $this->searching ? (new McpToolIndex($tools))->tools() : $tools;
        if ($resources !== []) {
            $trusted = [];
            foreach (array_keys($resources) as $name) {
                $trusted[$name] = ($this->servers[$name]['approval'] ?? 'ask') === 'allow';
            }
            $this->tools[] = new McpResourceTool($resources, $trusted);
        }
        $this->prompts = new McpPrompts($this->started, $prompts, $this->taken);
    }

    public function tools(): array
    {
        $this->connect();

        return $this->tools;
    }

    public function prompts(): McpPrompts
    {
        $this->connect();

        return $this->prompts ?? new McpPrompts([], []);
    }

    public function searching(): bool
    {
        $this->connect();

        return $this->searching;
    }

    public function problems(): array
    {
        $this->connect();

        return $this->problems;
    }

    public function warnings(): array
    {
        $this->connect();

        return $this->warnings;
    }

    public function instructions(): ?string
    {
        $this->connect();
        $blocks = [];
        foreach ($this->started as $name => $connection) {
            $text = $connection->instructions();
            if ($text !== null) {
                $blocks[] = "MCP server {$name}:\n" . Text::headTail($text, self::INSTRUCTION_BYTES);
            }
        }

        return $blocks === [] ? null : "Instructions from the MCP servers that are connected:\n\n" . implode("\n\n", $blocks);
    }

    public function items(\Closure $redact): array
    {
        $this->connect();
        $items = [];
        foreach ($this->servers as $name => $server) {
            $name = (string) $name;
            if (!$server['enabled']) {
                $items[] = ['category' => $name, 'title' => 'disabled', 'detail' => 'enabled is false in mcp_servers', 'status' => 'off'];
            } elseif (isset($this->problems[$name])) {
                $items[] = ['category' => $name, 'title' => 'not started', 'detail' => $redact($this->problems[$name]), 'status' => 'error'];
            } elseif (isset($this->started[$name])) {
                $connection = $this->started[$name];
                $counts = $this->counts[$name];
                $items[] = [
                    'category' => $name,
                    'title' => 'connected',
                    'detail' => sprintf('%s, protocol %s; %d tools, %d prompts%s', $connection instanceof McpHttpClient ? 'http' : 'stdio', $connection->version() ?? '?', $counts['tools'], $counts['prompts'], $counts['resources'] > 0 ? ', resources' : ''),
                    'status' => 'ok',
                ];
                foreach ($this->mcpTools as $tool) {
                    if (str_starts_with($tool->name(), McpTool::nameFor($name, '')) && $this->searching === false) {
                        $items[] = ['category' => $name, 'title' => $tool->name(), 'detail' => $redact(Text::oneLine($tool->description(), 200)), 'status' => 'ok'];
                    }
                }
            }
        }
        foreach ($this->warnings as $warning) {
            $items[] = ['category' => 'warnings', 'title' => 'left out', 'detail' => $redact($warning), 'status' => 'error'];
        }

        return $items;
    }

    public function close(): void
    {
        foreach ($this->started as $connection) {
            $connection->close();
        }
        $this->started = [];
    }

    public function __destruct()
    {
        $this->close();
    }
}
