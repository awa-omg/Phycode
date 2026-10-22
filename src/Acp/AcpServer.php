<?php

declare(strict_types=1);

namespace Phycode\Acp;

use Phycode\Cli\ChatSession;
use Phycode\Cli\Kernel;
use Phycode\Config\Config;
use Phycode\Llm\Image;
use Phycode\Mcp\McpHttpClient;
use Phycode\Rpc\JsonLines;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;

final class AcpServer
{
    public const PROTOCOL = 1;
    private const MODES = [
        ['id' => 'ask', 'name' => 'Ask', 'description' => 'Asks before it edits files and before it runs commands'],
        ['id' => 'accept_edits', 'name' => 'Accept edits', 'description' => 'Edits files without asking; commands still ask'],
        ['id' => 'plan', 'name' => 'Plan', 'description' => 'Reads and proposes a plan, and changes nothing'],
        ['id' => 'yolo', 'name' => 'Do not ask', 'description' => 'Runs everything without asking'],
    ];
    private const MAX_COMMANDS = 100;

    private array $sessions = [];
    private array $deferred = [];
    private int $requestIds = 0;
    private ?AcpSession $turning = null;
    private bool $polling = false;

    public function __construct(private readonly JsonLines $channel, private readonly \Closure $factory, private readonly string $version)
    {
    }

    public function run(): int
    {
        Cancellation::setPoll(fn () => $this->poll());
        try {
            while (true) {
                $message = array_shift($this->deferred) ?? $this->channel->next(null);
                if ($message === null) {
                    return 0;
                }
                $this->dispatch($message);
            }
        } finally {
            Cancellation::setPoll(null);
            foreach ($this->sessions as $session) {
                $session->kernel->mcp?->close();
                $session->kernel->lsp->close();
            }
        }
    }

    public function update(string $sessionId, array $update): void
    {
        $this->channel->send(['jsonrpc' => '2.0', 'method' => 'session/update', 'params' => ['sessionId' => $sessionId, 'update' => $update]]);
    }

    public function poll(): void
    {
        if ($this->polling || $this->turning === null) {
            return;
        }
        $this->polling = true;
        try {
            while (($message = $this->channel->next(0.0)) !== null) {
                $this->absorb($message);
            }
            if ($this->channel->ended()) {
                $this->cancelTurn();
            }
        } finally {
            $this->polling = false;
        }
    }

    public function requestPermission(string $sessionId, array $toolCall, array $options): ?string
    {
        $id = 'perm-' . ++$this->requestIds;
        $this->channel->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'session/request_permission', 'params' => ['sessionId' => $sessionId, 'toolCall' => $toolCall, 'options' => $options]]);
        while (!($this->turning?->ui->wasCancelled() ?? true)) {
            $message = $this->channel->next(0.5);
            if ($message === null) {
                if ($this->channel->ended()) {
                    $this->cancelTurn();
                }
                continue;
            }
            if (!isset($message['method']) && ($message['id'] ?? null) === $id) {
                $outcome = is_array($message['result']['outcome'] ?? null) ? $message['result']['outcome'] : [];
                $chosen = ($outcome['outcome'] ?? null) === 'selected' && is_string($outcome['optionId'] ?? null) ? $outcome['optionId'] : null;

                return $chosen !== null && in_array($chosen, array_column($options, 'optionId'), true) ? $chosen : null;
            }
            $this->absorb($message);
        }

        return null;
    }

    private function absorb(array $message): void
    {
        if (($message['method'] ?? null) === 'session/cancel') {
            $this->cancelTurn();

            return;
        }
        if (isset($message['method'])) {
            $this->deferred[] = $message;
        }
    }

    private function cancelTurn(): void
    {
        $this->turning?->ui->markCancelled();
        $this->turning?->chat->interrupt();
    }

    private function dispatch(array $message): void
    {
        $method = $message['method'] ?? null;
        if (!is_string($method)) {
            return;
        }
        $hasId = array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'authenticate' => new \stdClass(),
                'session/new' => $this->newSession($id, $params),
                'session/load' => $this->loadSession($id, $params),
                'session/prompt' => $this->prompt($params),
                'session/set_mode' => $this->setMode($params),
                'session/cancel' => $this->cancelIdle(),
                default => $hasId ? throw new AcpError("method not found: {$method}", AcpError::METHOD_NOT_FOUND) : null,
            };
            if ($hasId && $result !== false) {
                $this->channel->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
            }
        } catch (AcpError $error) {
            if ($hasId) {
                $this->channel->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $error->rpcCode, 'message' => $error->getMessage()]]);
            }
        } catch (\Throwable $error) {
            if ($hasId) {
                $this->channel->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => AcpError::INTERNAL, 'message' => $this->redact($error->getMessage())]]);
            }
        }
    }

    private function cancelIdle(): ?object
    {
        return null;
    }

    private function initialize(array $params): array
    {
        return [
            'protocolVersion' => self::PROTOCOL,
            'agentCapabilities' => [
                'loadSession' => true,
                'promptCapabilities' => ['image' => true, 'audio' => false, 'embeddedContext' => true],
                'mcpCapabilities' => ['http' => true, 'sse' => false],
            ],
            'agentInfo' => ['name' => 'phycode', 'title' => 'phycode', 'version' => $this->version],
            'authMethods' => [],
        ];
    }

    private function newSession(mixed $id, array $params): bool
    {
        $session = $this->open($params);
        $this->channel->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['sessionId' => $session->id(), 'modes' => $this->modes($session)]]);
        $this->announce($session);

        return false;
    }

    private function loadSession(mixed $id, array $params): bool
    {
        $wanted = $params['sessionId'] ?? null;
        if (!is_string($wanted) || $wanted === '') {
            throw new AcpError('sessionId is needed', AcpError::INVALID_PARAMS);
        }
        $session = $this->open($params);
        try {
            $session->chat->switchSession($wanted);
        } catch (PhycodeException $error) {
            unset($this->sessions[$session->id()]);
            throw new AcpError($this->redact($error->getMessage()), AcpError::INVALID_PARAMS);
        }
        $this->sessions = array_filter($this->sessions, static fn (AcpSession $known): bool => $known !== $session);
        $this->sessions[$session->id()] = $session;
        $session->ui->bind($session->id(), fn () => $session->kernel->todos);
        foreach ($session->chat->messages() as $message) {
            $text = Image::textWithPlaceholders($message);
            if ($text === '') {
                continue;
            }
            if (($message['role'] ?? null) === 'user') {
                $session->ui->message($text, 'user_message_chunk');
            } elseif (($message['role'] ?? null) === 'assistant') {
                $session->ui->message($text);
            }
        }
        $this->channel->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => new \stdClass()]);
        $this->announce($session);

        return false;
    }

    private function open(array $params): AcpSession
    {
        $cwd = $params['cwd'] ?? null;
        if (!is_string($cwd) || !str_starts_with($cwd, '/') || !is_dir($cwd)) {
            throw new AcpError('cwd must be the absolute path of a folder that exists', AcpError::INVALID_PARAMS);
        }
        $ui = new AcpUi($this);
        [$kernel, $chat] = ($this->factory)($cwd, self::mcpServers(is_array($params['mcpServers'] ?? null) ? $params['mcpServers'] : [], $ui), $ui);
        $session = new AcpSession($kernel, $chat, $ui);
        $this->sessions[$session->id()] = $session;
        $ui->bind($session->id(), fn () => $kernel->todos);

        return $session;
    }

    private function announce(AcpSession $session): void
    {
        $commands = [];
        foreach ($session->chat->commands() as $command) {
            [$word, $hint] = explode(' ', $command['name'], 2) + [1 => null];
            $name = ltrim($word, '/');
            if ($name === '' || isset($commands[$name]) || count($commands) >= self::MAX_COMMANDS) {
                continue;
            }
            $commands[$name] = ['name' => $name, 'description' => $command['description']] + ($hint === null ? [] : ['input' => ['hint' => $hint]]);
        }
        $session->ui->send(['sessionUpdate' => 'available_commands_update', 'availableCommands' => array_values($commands)]);
    }

    private function prompt(array $params): array|bool
    {
        $session = $this->find($params);
        [$text, $images] = $this->content($params['prompt'] ?? null, $session);
        if (trim($text) === '' && $images === []) {
            throw new AcpError('the prompt has no text', AcpError::INVALID_PARAMS);
        }
        $this->turning = $session;
        $session->ui->beginTurn();
        try {
            if ($images !== []) {
                $session->chat->sendWithImages($text, $images);
            } else {
                $session->chat->handle(trim($text));
            }
        } finally {
            $this->turning = null;
        }

        if ($session->ui->wasCancelled() || $session->chat->lastStatus() === 'cancelled') {
            return ['stopReason' => 'cancelled'];
        }

        return match ($session->chat->lastStatus()) {
            'error' => throw new AcpError($this->redact($session->chat->lastReason() ?? 'the turn failed'), AcpError::INTERNAL),
            'budget' => ['stopReason' => 'max_turn_requests'],
            default => ['stopReason' => 'end_turn'],
        };
    }

    private function content(mixed $blocks, AcpSession $session): array
    {
        if (!is_array($blocks) || !array_is_list($blocks)) {
            throw new AcpError('prompt must be a list of content blocks', AcpError::INVALID_PARAMS);
        }
        $parts = [];
        $images = [];
        foreach ($blocks as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if ($type === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            } elseif ($type === 'resource' && is_array($block['resource'] ?? null)) {
                $resource = $block['resource'];
                $name = self::path(is_string($resource['uri'] ?? null) ? $resource['uri'] : 'resource');
                $parts[] = is_string($resource['text'] ?? null) ? "Content of {$name}:\n```\n{$resource['text']}\n```" : "[{$name}: not text, not shown]";
            } elseif ($type === 'resource_link' && is_string($block['uri'] ?? null)) {
                $parts[] = 'Referenced: ' . self::path($block['uri']);
            } elseif ($type === 'image' && is_string($block['data'] ?? null)) {
                $name = is_string($block['uri'] ?? null) ? basename(self::path($block['uri'])) : 'image';
                if (!$session->chat->visionSupported()) {
                    $parts[] = "[image {$name} attached, but this model cannot see images: set \"vision\": true in the profile if it can]";
                    continue;
                }
                try {
                    $images[] = Image::fromBytes((string) base64_decode($block['data'], true), $name);
                } catch (PhycodeException $error) {
                    $parts[] = '[an image could not be used: ' . $error->getMessage() . ']';
                }
            }
        }

        return [implode("\n\n", $parts), $images];
    }

    private static function path(string $uri): string
    {
        return str_starts_with($uri, 'file://') ? rawurldecode(substr($uri, 7)) : $uri;
    }

    private function setMode(array $params): \stdClass
    {
        $session = $this->find($params);
        $mode = $params['modeId'] ?? null;
        if (!is_string($mode) || !in_array($mode, array_column(self::MODES, 'id'), true)) {
            throw new AcpError('modeId must be one of: ' . implode(', ', array_column(self::MODES, 'id')), AcpError::INVALID_PARAMS);
        }
        if ($mode === 'plan') {
            $session->chat->handle('/plan');
        } else {
            if ($session->chat->primary()->name === 'plan') {
                $default = $session->kernel->config->defaultAgent;
                $session->chat->handle('/agent ' . ($default === null || $default === 'plan' ? 'build' : $default));
            }
            $session->kernel->setPermissionMode($mode);
        }
        $session->ui->send(['sessionUpdate' => 'current_mode_update', 'currentModeId' => $mode]);

        return new \stdClass();
    }

    private function modes(AcpSession $session): array
    {
        $current = $session->chat->primary()->name === 'plan' ? 'plan' : $session->kernel->permissionMode;

        return ['currentModeId' => in_array($current, array_column(self::MODES, 'id'), true) ? $current : 'ask', 'availableModes' => self::MODES];
    }

    private function find(array $params): AcpSession
    {
        $id = $params['sessionId'] ?? null;

        return is_string($id) && isset($this->sessions[$id]) ? $this->sessions[$id] : throw new AcpError('unknown session; start one with session/new', AcpError::INVALID_PARAMS);
    }

    private function redact(string $message): string
    {
        return \Phycode\Support\Redaction::text($message);
    }

    private static function mcpServers(array $list, AcpUi $ui): array
    {
        $servers = [];
        foreach ($list as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null) || preg_match('/^[A-Za-z0-9_-]+$/D', $entry['name']) !== 1) {
                $ui->notice('warn', 'an MCP server of the editor has no usable name, so it is not started');
                continue;
            }
            $name = $entry['name'];
            try {
                if (($entry['type'] ?? null) === 'http') {
                    $url = (string) ($entry['url'] ?? '');
                    McpHttpClient::checkUrl($url, "MCP server {$name}");
                    $headers = [];
                    foreach (is_array($entry['headers'] ?? null) ? $entry['headers'] : [] as $header) {
                        if (is_array($header) && is_string($header['name'] ?? null) && is_string($header['value'] ?? null)) {
                            $headers[$header['name']] = $header['value'];
                        }
                    }
                    $bearer = null;
                    foreach ($headers as $header => $value) {
                        if (strtolower($header) === 'authorization') {
                            if (preg_match('/^Bearer (\S+)$/i', $value, $match) !== 1) {
                                throw new PhycodeException("MCP server {$name}: only a Bearer token is understood in the Authorization header");
                            }
                            $bearer = $match[1];
                            unset($headers[$header]);
                            continue;
                        }
                        McpHttpClient::checkHeader($header, $value, "MCP server {$name}");
                    }
                    $servers[$name] = ['command' => [], 'url' => $url, 'headers' => $headers, 'token_env' => null, 'oauth' => null, 'bearer' => $bearer, 'enabled' => true, 'approval' => 'ask'];
                } elseif (($entry['type'] ?? 'stdio') === 'stdio' && is_string($entry['command'] ?? null) && $entry['command'] !== '') {
                    $env = [];
                    foreach (is_array($entry['env'] ?? null) ? $entry['env'] : [] as $variable) {
                        if (is_array($variable) && is_string($variable['name'] ?? null) && is_string($variable['value'] ?? null)) {
                            $env[$variable['name']] = $variable['value'];
                        }
                    }
                    $servers[$name] = ['command' => [$entry['command'], ...array_values(array_filter(is_array($entry['args'] ?? null) ? $entry['args'] : [], 'is_string'))], 'env' => $env, 'enabled' => true, 'approval' => 'ask'];
                } else {
                    $ui->notice('warn', "the MCP server {$name} of the editor uses a transport that phycode does not speak (stdio and http are the ones)");
                }
            } catch (PhycodeException $error) {
                $ui->notice('warn', $error->getMessage());
            }
        }

        return $servers;
    }
}
