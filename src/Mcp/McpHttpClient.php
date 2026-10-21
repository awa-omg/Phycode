<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Llm\SseParser;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Text;

final class McpHttpClient extends McpConnection
{
    private const MAX_RESPONSE_BYTES = 16777216;
    private const CONNECT_SECONDS = 10;
    private const RESERVED_HEADERS = ['content-type', 'accept', 'host', 'content-length', 'authorization', 'cookie', 'transfer-encoding', 'connection'];
    private const CREDENTIAL_HEADER = '/api-?key|token|secret|password|authorization/i';

    private int $lastId = 0;
    private ?string $sessionId = null;
    private array $annotations = [];
    private array $skipped = [];

    public function __construct(
        string $name,
        private readonly string $url,
        private readonly array $headers = [],
        private readonly ?McpAuth $auth = null,
        private readonly float $timeout = 30.0,
        string $clientVersion = '0.1.0',
    ) {
        parent::__construct($name, $clientVersion);
        self::checkUrl($url, "MCP server {$name}");
        foreach ($headers as $header => $value) {
            self::checkHeader((string) $header, $value, "MCP server {$name}");
        }
        if ($timeout <= 0.0) {
            throw new PhycodeException("MCP server {$name}: the timeout must be positive");
        }
    }

    public static function checkUrl(string $url, string $label): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        if (!is_array($parts) || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            throw new PhycodeException("{$label}: the url must be an http or https address");
        }
        if ($scheme === 'http' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new PhycodeException("{$label}: the url must be https, because a token must not cross the network in plain text (http is allowed for localhost only)");
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new PhycodeException("{$label}: the url has no user, password or fragment; put a token in token_env or oauth");
        }
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new PhycodeException("{$label}: the url has a space or a control character in it");
        }
    }

    public static function checkHeader(string $name, mixed $value, string $label): void
    {
        if (preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/D", $name) !== 1) {
            throw new PhycodeException("{$label}: '{$name}' is not a header name");
        }
        if (in_array(strtolower($name), self::RESERVED_HEADERS, true) || str_starts_with(strtolower($name), 'mcp-') || strtolower($name) === 'mcp-protocol-version') {
            throw new PhycodeException("{$label}: the header {$name} is set by phycode; use token_env or oauth for credentials");
        }
        if (preg_match(self::CREDENTIAL_HEADER, $name) === 1) {
            throw new PhycodeException("{$label}: the header {$name} would hold a credential in config.json, and keys are not written there; set token_env to the name of an environment variable that holds the token");
        }
        if (!is_string($value) || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new PhycodeException("{$label}: the value of the header {$name} must be one line of text");
        }
    }

    public function skipped(): array
    {
        return $this->skipped;
    }

    public function listTools(): array
    {
        $tools = parent::listTools();
        if ($this->era !== 'modern') {
            return $tools;
        }
        $this->skipped = [];
        $this->annotations = [];
        $kept = [];
        foreach ($tools as $tool) {
            $annotations = McpHeaders::annotations($tool['inputSchema'], $why);
            if ($annotations === null) {
                $this->skipped[] = "{$tool['name']}: {$why}";
                continue;
            }
            $this->annotations[$tool['name']] = $annotations;
            $kept[] = $tool;
        }

        return $kept;
    }

    protected function open(): void
    {
    }

    protected function exchange(string $method, array $params): array
    {
        $id = ++$this->lastId;
        $body = Json::encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);
        $response = $this->send($body, $method, $params, $id);
        if ($method === 'initialize' && is_string($response['headers']['mcp-session-id'] ?? null)) {
            $session = $response['headers']['mcp-session-id'];
            $this->sessionId = preg_match('/^[\x21-\x7E]{1,512}$/D', $session) === 1 ? $session : null;
        }

        return $this->resultOf($response, $method);
    }

    protected function notify(string $method, array $params = []): void
    {
        $body = Json::encode(['jsonrpc' => '2.0', 'method' => $method] + ($params === [] ? [] : ['params' => $params]));
        $response = $this->send($body, $method, $params, null);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new PhycodeException("MCP server {$this->name}: {$method} was refused with HTTP {$response['status']}");
        }
    }

    protected function shutdown(): void
    {
        $session = $this->sessionId;
        $this->sessionId = null;
        if ($session === null || $this->era !== 'legacy') {
            return;
        }
        $curl = curl_init($this->url);
        if ($curl !== false) {
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_HTTPHEADER => $this->headerLines(['MCP-Session-Id' => $session, 'MCP-Protocol-Version' => (string) $this->version, ...$this->headers, ...($this->auth?->headers() ?? [])]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            curl_exec($curl);
            curl_close($curl);
        }
    }

    private function send(string $body, string $method, array $params, ?int $id): array
    {
        $renewedCredentials = false;
        $renewedSession = false;
        while (true) {
            $response = $this->post($body, $this->requestHeaders($method, $params), $id);
            $status = $response['status'];
            if ($status === 401 || $status === 403) {
                if ($this->auth === null) {
                    throw new PhycodeException("MCP server {$this->name} refused the request (HTTP {$status}); it wants credentials: set token_env or oauth for it in mcp_servers");
                }
                if (!$renewedCredentials && $this->auth->rejected($status, $response['headers']['www-authenticate'] ?? '')) {
                    $renewedCredentials = true;
                    continue;
                }

                throw new PhycodeException($this->auth->advice($this->name));
            }
            if ($status === 404 && $this->sessionId !== null && $method !== 'initialize' && !$renewedSession) {
                $renewedSession = true;
                $this->sessionId = null;
                $this->renewSession();
                continue;
            }

            return $response;
        }
    }

    private function requestHeaders(string $method, array $params): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'User-Agent' => 'phycode/' . $this->clientVersion];
        $headers = [...$headers, ...$this->headers, ...($this->auth?->headers() ?? [])];
        if ($this->era === 'modern') {
            $headers['MCP-Protocol-Version'] = $this->version ?? self::MODERN_VERSION;
            $headers['Mcp-Method'] = $method;
            $named = match ($method) {
                'tools/call', 'prompts/get' => $params['name'] ?? null,
                'resources/read' => $params['uri'] ?? null,
                default => null,
            };
            if (is_string($named)) {
                $headers['Mcp-Name'] = McpHeaders::encode($named);
            }
            if ($method === 'tools/call' && is_string($params['name'] ?? null) && isset($this->annotations[$params['name']])) {
                $headers = [...$headers, ...McpHeaders::forCall($this->annotations[$params['name']], (array) ($params['arguments'] ?? []))];
            }
        } elseif ($this->era === 'legacy' && $method !== 'initialize') {
            $headers['MCP-Protocol-Version'] = (string) $this->version;
            if ($this->sessionId !== null) {
                $headers['MCP-Session-Id'] = $this->sessionId;
            }
        }

        return $headers;
    }

    private function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return $lines;
    }

    private function post(string $body, array $headers, ?int $id): array
    {
        $curl = curl_init($this->url);
        if ($curl === false) {
            throw new PhycodeException("MCP server {$this->name}: cannot start an HTTP request");
        }
        $received = [];
        $status = 0;
        $text = '';
        $parser = new SseParser();
        $found = null;
        $stream = false;
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $this->headerLines($headers),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_SECONDS,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received, &$status, &$stream): int {
                $length = strlen($line);
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $match) === 1) {
                    $status = (int) $match[1];
                    $received = [];
                    $stream = false;
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))] = trim($value);
                    $stream = str_starts_with(strtolower($received['content-type'] ?? ''), 'text/event-stream');
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$text, &$stream, &$found, $parser, $id): int {
                $text .= $chunk;
                if (strlen($text) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                if ($stream && $id !== null) {
                    foreach ($parser->feed($chunk) as $data) {
                        $message = Json::object($data);
                        if ($message !== null && ($message['id'] ?? null) === $id && (array_key_exists('result', $message) || array_key_exists('error', $message))) {
                            $found = $message;

                            return 0;
                        }
                    }
                }

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($found === null && $ok === false) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new McpTimeout("MCP server {$this->name} did not answer within {$this->timeout} s");
            }
            if (strlen($text) > self::MAX_RESPONSE_BYTES) {
                throw new PhycodeException("MCP server {$this->name} sent a response longer than " . intdiv(self::MAX_RESPONSE_BYTES, 1048576) . ' MB');
            }

            throw new PhycodeException("MCP server {$this->name}: the request to its url failed: " . self::plain(Redaction::text($error), 200));
        }
        if ($status >= 300 && $status < 400) {
            throw new PhycodeException("MCP server {$this->name} answered with a redirect (HTTP {$status}); set the url to the final address, because phycode does not follow redirects with a token");
        }

        return ['status' => $status, 'headers' => $received, 'message' => $found ?? ($stream ? null : Json::object(trim($text))), 'body' => $text];
    }

    private function resultOf(array $response, string $method): array
    {
        $message = $response['message'];
        $status = $response['status'];
        if ($message !== null && isset($message['error'])) {
            $error = is_array($message['error']) ? $message['error'] : [];
            $text = is_string($error['message'] ?? null) ? self::plain($error['message']) : 'no message';
            $code = is_int($error['code'] ?? null) ? $error['code'] : null;

            throw new McpRpcException("MCP server {$this->name}: {$method} failed: {$text}" . ($code === null ? '' : " (code {$code})"), $code, $error['data'] ?? null, $status);
        }
        if ($status >= 200 && $status < 300) {
            if ($message !== null && array_key_exists('result', $message)) {
                return is_array($message['result']) ? $message['result'] : [];
            }

            throw new PhycodeException("MCP server {$this->name}: the answer to {$method} has no JSON-RPC response in it");
        }

        $excerpt = self::plain(Redaction::text(trim($response['body'])), 200);
        $detail = "MCP server {$this->name}: {$method} failed: HTTP {$status}" . ($excerpt === '' ? '' : ": {$excerpt}");
        if (in_array($status, [400, 404, 405], true)) {
            throw new McpRpcException($detail . ($method === 'initialize' && $status !== 400 ? ' (is the url the MCP endpoint? the deprecated HTTP+SSE transport is not supported)' : ''), null, null, $status);
        }

        throw new PhycodeException($detail);
    }
}
