<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

abstract class McpConnection
{
    public const MODERN_VERSION = '2026-07-28';
    public const PROTOCOL_VERSION = '2025-11-25';
    protected const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
    protected const MODERN_ERRORS = [-32020, -32021, -32022];
    private const UNSUPPORTED_VERSION = -32022;
    private const MAX_PAGES = 100;

    protected ?string $era = null;
    protected ?string $version = null;
    protected bool $started = false;
    protected bool $opened = false;
    protected bool $closed = false;
    protected ?string $failure = null;
    private array $serverCapabilities = [];
    private ?string $serverInstructions = null;
    private ?array $serverInfo = null;

    public function __construct(protected readonly string $name, protected readonly string $clientVersion = '0.1.0')
    {
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $name) !== 1) {
            throw new PhycodeException("MCP server name '{$name}' may only use letters, digits, '-' and '_'");
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function era(): ?string
    {
        return $this->era;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    public function capabilities(): array
    {
        return $this->serverCapabilities;
    }

    public function supports(string $feature): bool
    {
        return array_key_exists($feature, $this->serverCapabilities);
    }

    public function instructions(): ?string
    {
        return $this->serverInstructions;
    }

    public function serverInfo(): ?array
    {
        return $this->serverInfo;
    }

    public function prepare(): void
    {
        $this->assertUsable();
        if (!$this->opened) {
            try {
                $this->open();
                $this->opened = true;
            } catch (PhycodeException $error) {
                $this->failure ??= $error->getMessage();
                $this->shutdown();

                throw $error;
            }
        }
    }

    public function start(): void
    {
        $this->assertUsable();
        if ($this->started) {
            return;
        }

        try {
            $this->prepare();
            $this->negotiate();
            $this->started = true;
        } catch (PhycodeException $error) {
            $this->failure ??= $error->getMessage();
            $this->shutdown();

            throw $error;
        }
    }

    public function listTools(): array
    {
        $tools = [];
        foreach ($this->listAll('tools/list', 'tools') as $tool) {
            if (is_string($tool['name'] ?? null) && $tool['name'] !== '') {
                $tools[] = [
                    'name' => $tool['name'],
                    'description' => is_string($tool['description'] ?? null) ? $tool['description'] : '',
                    'inputSchema' => is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [],
                ];
            }
        }

        return $tools;
    }

    public function callTool(string $tool, array $arguments): array
    {
        return $this->call('tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
    }

    public function listResources(): array
    {
        $resources = [];
        foreach ($this->listAll('resources/list', 'resources') as $resource) {
            if (is_string($resource['uri'] ?? null) && $resource['uri'] !== '') {
                $resources[] = [
                    'uri' => $resource['uri'],
                    'name' => is_string($resource['name'] ?? null) ? $resource['name'] : $resource['uri'],
                    'description' => is_string($resource['description'] ?? null) ? $resource['description'] : '',
                    'mimeType' => is_string($resource['mimeType'] ?? null) ? $resource['mimeType'] : '',
                ];
            }
        }

        return $resources;
    }

    public function listResourceTemplates(): array
    {
        $templates = [];
        foreach ($this->listAll('resources/templates/list', 'resourceTemplates') as $template) {
            if (is_string($template['uriTemplate'] ?? null) && $template['uriTemplate'] !== '') {
                $templates[] = [
                    'uriTemplate' => $template['uriTemplate'],
                    'name' => is_string($template['name'] ?? null) ? $template['name'] : $template['uriTemplate'],
                    'description' => is_string($template['description'] ?? null) ? $template['description'] : '',
                    'mimeType' => is_string($template['mimeType'] ?? null) ? $template['mimeType'] : '',
                ];
            }
        }

        return $templates;
    }

    public function readResource(string $uri): array
    {
        $result = $this->call('resources/read', ['uri' => $uri]);

        return array_values(array_filter(is_array($result['contents'] ?? null) ? $result['contents'] : [], 'is_array'));
    }

    public function listPrompts(): array
    {
        $prompts = [];
        foreach ($this->listAll('prompts/list', 'prompts') as $prompt) {
            if (!is_string($prompt['name'] ?? null) || $prompt['name'] === '') {
                continue;
            }
            $arguments = [];
            foreach (is_array($prompt['arguments'] ?? null) ? $prompt['arguments'] : [] as $argument) {
                if (is_array($argument) && is_string($argument['name'] ?? null) && $argument['name'] !== '') {
                    $arguments[] = [
                        'name' => $argument['name'],
                        'description' => is_string($argument['description'] ?? null) ? $argument['description'] : '',
                        'required' => ($argument['required'] ?? false) === true,
                    ];
                }
            }
            $prompts[] = [
                'name' => $prompt['name'],
                'description' => is_string($prompt['description'] ?? null) ? $prompt['description'] : '',
                'arguments' => $arguments,
            ];
        }

        return $prompts;
    }

    public function getPrompt(string $name, array $arguments): array
    {
        $result = $this->call('prompts/get', ['name' => $name, 'arguments' => (object) $arguments]);
        $messages = [];
        foreach (is_array($result['messages'] ?? null) ? $result['messages'] : [] as $message) {
            if (is_array($message) && in_array($message['role'] ?? null, ['user', 'assistant'], true) && is_array($message['content'] ?? null)) {
                $messages[] = ['role' => $message['role'], 'content' => $message['content']];
            }
        }

        return ['description' => is_string($result['description'] ?? null) ? $result['description'] : '', 'messages' => $messages];
    }

    public function close(): void
    {
        $this->closed = true;
        $this->shutdown();
    }

    public function stderr(): string
    {
        return '';
    }

    abstract protected function open(): void;

    abstract protected function exchange(string $method, array $params): array;

    abstract protected function notify(string $method, array $params = []): void;

    abstract protected function shutdown(): void;

    protected function assertUsable(): void
    {
        if ($this->failure !== null) {
            throw new PhycodeException($this->failure);
        }
        if ($this->closed) {
            throw new PhycodeException("MCP server {$this->name} is closed");
        }
    }

    protected function call(string $method, array $params): array
    {
        $this->assertUsable();
        if (!$this->started) {
            $this->start();
        }

        return $this->checked($method, $this->exchange($method, $this->withMeta($params)));
    }

    protected function withMeta(array $params, ?string $version = null): array
    {
        if ($this->era === 'legacy') {
            return $params;
        }
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => $version ?? $this->version ?? self::MODERN_VERSION,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'phycode', 'version' => $this->clientVersion],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ] + $meta;

        return $params;
    }

    private function checked(string $method, array $result): array
    {
        $type = $result['resultType'] ?? 'complete';
        if ($type === 'input_required') {
            throw new PhycodeException("MCP server {$this->name}: {$method} needs input from the user, which phycode does not offer to a server");
        }
        if ($type !== 'complete') {
            throw new PhycodeException("MCP server {$this->name}: {$method} answered with the result type '" . self::plain((string) (is_scalar($type) ? $type : 'unknown'), 40) . "', which this client does not know");
        }

        return $result;
    }

    private function listAll(string $method, string $key): array
    {
        $items = [];
        $seen = [];
        $cursor = null;
        do {
            $result = $this->call($method, $cursor === null ? [] : ['cursor' => $cursor]);
            foreach (is_array($result[$key] ?? null) ? $result[$key] : [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $cursor = is_string($result['nextCursor'] ?? null) && $result['nextCursor'] !== '' ? $result['nextCursor'] : null;
            if ($cursor !== null) {
                if (isset($seen[$cursor]) || count($seen) >= self::MAX_PAGES) {
                    throw new PhycodeException("MCP server {$this->name} repeats its {$method} cursor or lists too many pages");
                }
                $seen[$cursor] = true;
            }
        } while ($cursor !== null);

        return $items;
    }

    private function negotiate(): void
    {
        $this->era = 'modern';
        try {
            $result = $this->exchange('server/discover', $this->withMeta([], self::MODERN_VERSION));
            $this->checked('server/discover', $result);
            $this->version = self::MODERN_VERSION;
            $this->learn($result);

            return;
        } catch (McpRpcException $error) {
            $modern = in_array($error->rpcCode, self::MODERN_ERRORS, true);
            if ($modern && $error->rpcCode === self::UNSUPPORTED_VERSION) {
                $supported = is_array($error->data) && is_array($error->data['supported'] ?? null) ? array_values(array_filter($error->data['supported'], 'is_string')) : [];
                if (array_intersect($supported, self::LEGACY_VERSIONS) === []) {
                    throw new PhycodeException(sprintf(
                        'MCP server %s speaks the protocol versions %s; phycode speaks %s',
                        $this->name,
                        $supported === [] ? 'none that it names' : self::plain(implode(', ', $supported), 80),
                        implode(', ', [self::MODERN_VERSION, ...self::LEGACY_VERSIONS]),
                    ));
                }
            } elseif ($modern) {
                throw $error;
            }
        } catch (McpTimeout) {
        }

        $this->era = 'legacy';
        $this->handshake();
    }

    private function handshake(): void
    {
        $result = $this->exchange('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'phycode', 'version' => $this->clientVersion],
        ]);
        $version = is_string($result['protocolVersion'] ?? null) ? $result['protocolVersion'] : null;
        if ($version === null || !in_array($version, self::LEGACY_VERSIONS, true)) {
            throw new PhycodeException(sprintf(
                'MCP server %s answered with protocol version %s; phycode speaks %s',
                $this->name,
                $version === null ? 'none' : self::plain($version, 40),
                implode(', ', [self::MODERN_VERSION, ...self::LEGACY_VERSIONS]),
            ));
        }
        $this->version = $version;
        $this->learn($result);
        $this->afterInitialize($result);
        $this->notify('notifications/initialized');
    }

    protected function renewSession(): void
    {
        $this->handshake();
    }

    protected function afterInitialize(array $result): void
    {
    }

    private function learn(array $result): void
    {
        $this->serverCapabilities = is_array($result['capabilities'] ?? null) ? $result['capabilities'] : [];
        $instructions = $result['instructions'] ?? null;
        $this->serverInstructions = is_string($instructions) && trim($instructions) !== '' ? trim($instructions) : null;
        $info = $result['serverInfo'] ?? ($result['_meta']['io.modelcontextprotocol/serverInfo'] ?? null);
        $this->serverInfo = is_array($info) ? $info : null;
    }

    protected static function plain(string $text, int $max = 300): string
    {
        return Text::oneLine((string) preg_replace('/\p{Cc}+/u', ' ', mb_scrub($text, 'UTF-8')), $max);
    }
}
