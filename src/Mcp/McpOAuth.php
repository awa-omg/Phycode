<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Text;
use Phycode\Support\Vault;

final class McpOAuth implements McpAuth
{
    private const LOGIN_SECONDS = 180.0;
    private const EARLY_SECONDS = 30;

    public function __construct(
        private readonly string $server,
        private readonly string $resource,
        private readonly Vault $vault,
        private readonly array $settings = ['client_id' => null, 'client_secret_env' => null, 'scopes' => [], 'callback_port' => 0],
    ) {
        McpHttpClient::checkUrl($resource, "MCP server {$server}");
    }

    public function headers(): array
    {
        $login = $this->load();
        if ($login === null) {
            return [];
        }
        if (isset($login['expires_at']) && $login['expires_at'] - self::EARLY_SECONDS <= time()) {
            if (!$this->refresh()) {
                return [];
            }
            $login = $this->load() ?? $login;
        }

        return ['Authorization' => 'Bearer ' . $login['access_token']];
    }

    public function rejected(int $status, string $challenge): bool
    {
        return $status === 401 && $this->refresh();
    }

    public function advice(string $server): string
    {
        return $this->load() === null
            ? "the MCP server {$server} needs you to sign in: run phycode mcp login {$server}"
            : "the MCP server {$server} refused the login that phycode has; run phycode mcp login {$server} again";
    }

    public function loggedIn(): bool
    {
        return $this->load() !== null;
    }

    public function logout(): bool
    {
        return $this->vault->removeMcpLogin($this->server);
    }

    public function login(\Closure $say, \Closure $open, float $waitSeconds = self::LOGIN_SECONDS): array
    {
        $challenge = $this->challenge();
        $prm = $this->protectedResource($challenge['resource_metadata'] ?? null);
        $issuer = $prm['authorization_servers'][0];
        $meta = $this->authorizationServer($issuer);

        [$socket, $redirect] = $this->listen();
        try {
            $client = $this->client($meta, $issuer, $redirect);
            $scope = $this->scope($challenge['scope'] ?? null, $prm);
            $verifier = self::random(48);
            $state = self::random(24);
            $query = [
                'response_type' => 'code',
                'client_id' => $client['client_id'],
                'redirect_uri' => $redirect,
                'state' => $state,
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
                'resource' => $this->resource,
            ] + ($scope === '' ? [] : ['scope' => $scope]);
            $url = $meta['authorization_endpoint'] . (str_contains($meta['authorization_endpoint'], '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

            $say("Sign in to the MCP server {$this->server} at this address:\n{$url}");
            $open($url);
            $answer = $this->awaitCallback($socket, $waitSeconds, $state);
        } finally {
            fclose($socket);
        }

        $this->verifyAnswer($answer, $state, $issuer, $meta);
        $tokens = $this->tokenRequest($meta, $client, [
            'grant_type' => 'authorization_code',
            'code' => $answer['code'],
            'redirect_uri' => $redirect,
            'code_verifier' => $verifier,
        ]);
        $login = [
            'resource' => $this->resource,
            'issuer' => $issuer,
            'token_endpoint' => $meta['token_endpoint'],
            'token_endpoint_auth_methods_supported' => $meta['token_endpoint_auth_methods_supported'] ?? [],
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirect,
        ] + ($client['client_secret'] === null ? [] : ['client_secret' => $client['client_secret']]) + $tokens;
        $this->vault->storeMcpLogin($this->server, Json::encode($login));

        return ['issuer' => $issuer, 'scope' => (string) ($tokens['scope'] ?? ''), 'expires_at' => $tokens['expires_at'] ?? null];
    }

    public static function openBrowser(string $url): void
    {
        if (preg_match('~^https?://~i', $url) !== 1) {
            return;
        }
        foreach (['xdg-open', 'open'] as $program) {
            $path = \Phycode\Exec\ProcessRunner::which($program);
            if ($path !== null) {
                $process = @proc_open([$path, $url], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                if (is_resource($process)) {
                    return;
                }
            }
        }
    }

    private function challenge(): array
    {
        $response = OAuthHttp::request('POST', $this->resource, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => McpConnection::MODERN_VERSION,
            'Mcp-Method' => 'server/discover',
        ], Json::encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => McpConnection::MODERN_VERSION,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ]]]));
        if ($response['status'] !== 401) {
            throw new PhycodeException("the MCP server {$this->server} did not ask for a login (HTTP {$response['status']}): it needs none, or its url is not the MCP endpoint");
        }

        return self::parseChallenge($response['headers']['www-authenticate'] ?? '');
    }

    public static function parseChallenge(string $header): array
    {
        $parameters = [];
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_-]*)=(?:"((?:[^"\\\\]|\\\\.)*)"|([^\s,]+))/', $header, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $parameters[strtolower($match[1])] = stripslashes($match[2] !== '' ? $match[2] : ($match[3] ?? ''));
            }
        }

        return $parameters;
    }

    private function protectedResource(?string $given): array
    {
        $parts = parse_url($this->resource);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $candidates = $given !== null && $given !== '' ? [$given] : array_values(array_filter([
            $path === '' ? null : "{$origin}/.well-known/oauth-protected-resource{$path}",
            "{$origin}/.well-known/oauth-protected-resource",
        ]));

        $problem = 'no protected resource metadata was found';
        foreach ($candidates as $candidate) {
            try {
                OAuthHttp::check($candidate, 'the protected resource metadata address');
                $response = OAuthHttp::request('GET', $candidate, ['Accept' => 'application/json']);
            } catch (PhycodeException $error) {
                $problem = $error->getMessage();
                continue;
            }
            $document = $response['status'] === 200 ? Json::object($response['body']) : null;
            if ($document === null) {
                $problem = "{$candidate} answered HTTP {$response['status']} without metadata";
                continue;
            }
            $servers = array_values(array_filter(is_array($document['authorization_servers'] ?? null) ? $document['authorization_servers'] : [], static fn (mixed $server): bool => is_string($server) && $server !== ''));
            if ($servers === []) {
                throw new PhycodeException("the protected resource metadata of {$this->server} names no authorization server");
            }
            $described = is_string($document['resource'] ?? null) ? rtrim($document['resource'], '/') : '';
            $mine = rtrim($this->resource, '/');
            if ($described !== '' && $described !== $mine && !str_starts_with($mine, $described . '/')) {
                throw new PhycodeException("the protected resource metadata is about {$described}, not about {$mine}; a login is refused when they differ");
            }

            return [
                'authorization_servers' => $servers,
                'scopes_supported' => array_values(array_filter(is_array($document['scopes_supported'] ?? null) ? $document['scopes_supported'] : [], 'is_string')),
            ];
        }

        throw new PhycodeException("cannot find where {$this->server} signs in: {$problem}");
    }

    private function authorizationServer(string $issuer): array
    {
        OAuthHttp::check($issuer, 'the issuer');
        $parts = parse_url($issuer);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $candidates = $path === ''
            ? ["{$origin}/.well-known/oauth-authorization-server", "{$origin}/.well-known/openid-configuration"]
            : ["{$origin}/.well-known/oauth-authorization-server{$path}", "{$origin}/.well-known/openid-configuration{$path}", "{$origin}{$path}/.well-known/openid-configuration"];

        $problem = 'no metadata was found';
        foreach ($candidates as $candidate) {
            try {
                $response = OAuthHttp::request('GET', $candidate, ['Accept' => 'application/json']);
            } catch (PhycodeException $error) {
                $problem = $error->getMessage();
                continue;
            }
            $meta = $response['status'] === 200 ? Json::object($response['body']) : null;
            if ($meta === null) {
                continue;
            }
            if (($meta['issuer'] ?? null) !== $issuer) {
                throw new PhycodeException("the metadata at {$candidate} says its issuer is " . Text::oneLine((string) (is_string($meta['issuer'] ?? null) ? $meta['issuer'] : 'nothing'), 100) . ", not {$issuer}; it is not used");
            }
            foreach (['authorization_endpoint', 'token_endpoint'] as $field) {
                if (!is_string($meta[$field] ?? null)) {
                    throw new PhycodeException("the authorization server {$issuer} has no {$field}");
                }
                OAuthHttp::check($meta[$field], "its {$field}");
            }
            if (!in_array('S256', is_array($meta['code_challenge_methods_supported'] ?? null) ? $meta['code_challenge_methods_supported'] : [], true)) {
                throw new PhycodeException("the authorization server {$issuer} does not say that it supports PKCE with S256, so the login is refused");
            }

            return $meta;
        }

        throw new PhycodeException("cannot read the metadata of the authorization server {$issuer}: {$problem}");
    }

    private function client(array $meta, string $issuer, string $redirect): array
    {
        $configured = $this->settings['client_id'];
        if ($configured !== null && $configured !== '') {
            $variable = $this->settings['client_secret_env'];
            $secret = $variable !== null ? getenv($variable) : false;

            return ['client_id' => $configured, 'client_secret' => is_string($secret) && $secret !== '' ? $secret : null];
        }
        $stored = $this->load(true);
        if ($stored !== null && ($stored['issuer'] ?? null) === $issuer && ($stored['redirect_uri'] ?? null) === $redirect && is_string($stored['client_id'] ?? null)) {
            return ['client_id' => $stored['client_id'], 'client_secret' => is_string($stored['client_secret'] ?? null) ? $stored['client_secret'] : null];
        }
        if (!is_string($meta['registration_endpoint'] ?? null)) {
            throw new PhycodeException("the authorization server {$issuer} does not register clients by itself; register phycode there and set oauth.client_id for {$this->server}");
        }
        OAuthHttp::check($meta['registration_endpoint'], 'its registration_endpoint');
        $response = OAuthHttp::request('POST', $meta['registration_endpoint'], ['Content-Type' => 'application/json', 'Accept' => 'application/json'], Json::encode([
            'client_name' => 'phycode',
            'redirect_uris' => [$redirect],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'application_type' => 'native',
        ]));
        $registered = $response['status'] >= 200 && $response['status'] < 300 ? Json::object($response['body']) : null;
        if ($registered === null || !is_string($registered['client_id'] ?? null) || $registered['client_id'] === '') {
            throw new PhycodeException("the authorization server {$issuer} refused to register phycode (HTTP {$response['status']}): " . self::describe($response['body']));
        }

        return ['client_id' => $registered['client_id'], 'client_secret' => is_string($registered['client_secret'] ?? null) && $registered['client_secret'] !== '' ? $registered['client_secret'] : null];
    }

    private function scope(?string $challenged, array $prm): string
    {
        if ($challenged !== null && trim($challenged) !== '') {
            $scopes = preg_split('/\s+/', trim($challenged)) ?: [];
            return implode(' ', array_values(array_unique([...$scopes, ...$this->settings['scopes']])));
        }

        return implode(' ', $this->settings['scopes'] !== [] ? $this->settings['scopes'] : $prm['scopes_supported']);
    }

    private function listen(): array
    {
        $port = $this->settings['callback_port'];
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
        if ($socket === false) {
            throw new PhycodeException("cannot listen on 127.0.0.1:{$port} for the answer of the browser: {$error}");
        }
        $name = (string) stream_socket_get_name($socket, false);

        return [$socket, 'http://127.0.0.1:' . substr($name, (int) strrpos($name, ':') + 1) . '/callback'];
    }

    private function awaitCallback($socket, float $seconds, string $state): array
    {
        $deadline = microtime(true) + $seconds;
        while (($left = $deadline - microtime(true)) > 0.0) {
            $connection = @stream_socket_accept($socket, min($left, 1.0));
            if ($connection === false) {
                continue;
            }
            stream_set_timeout($connection, 5);
            $request = '';
            while (!str_contains($request, "\r\n\r\n") && strlen($request) < 8192) {
                $chunk = fread($connection, 2048);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $request .= $chunk;
            }
            $line = (string) strtok($request, "\r\n");
            if (preg_match('~^GET (/callback\?\S*) HTTP/~', $line, $match) !== 1) {
                @fwrite($connection, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                fclose($connection);
                continue;
            }
            parse_str((string) parse_url($match[1], PHP_URL_QUERY), $query);
            $answer = array_map('strval', array_filter($query, 'is_scalar'));
            if (!self::isThisMachine($request) || !hash_equals($state, $answer['state'] ?? '')) {
                @fwrite($connection, "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                fclose($connection);
                continue;
            }
            $page = '<!doctype html><meta charset="utf-8"><title>phycode</title><p>You can close this tab and go back to phycode.</p>';
            @fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nReferrer-Policy: no-referrer\r\nContent-Length: " . strlen($page) . "\r\nConnection: close\r\n\r\n{$page}");
            fclose($connection);

            return $answer;
        }

        throw new PhycodeException('the browser did not come back within ' . round($seconds, 1) . ' seconds; run the login again');
    }

    private static function isThisMachine(string $request): bool
    {
        return preg_match('/^Host:[ \t]*(?:127\.0\.0\.1|localhost)(?::\d+)?[ \t\r]*$/mi', $request) === 1;
    }

    private function verifyAnswer(array $answer, string $state, string $issuer, array $meta): void
    {
        $iss = $answer['iss'] ?? null;
        if ($iss === null && ($meta['authorization_response_iss_parameter_supported'] ?? false) === true) {
            throw new PhycodeException('the answer of the authorization server has no iss, which it said it sends; the login is refused');
        }
        if ($iss !== null && $iss !== $issuer) {
            throw new PhycodeException('the answer of the browser names another issuer than the one phycode asked; the login is refused');
        }
        if (!hash_equals($state, $answer['state'] ?? '')) {
            throw new PhycodeException('the answer of the browser has another state than the login that phycode started; it is refused');
        }
        if (isset($answer['error'])) {
            $why = Text::oneLine(Redaction::text(($answer['error_description'] ?? '') !== '' ? $answer['error_description'] : $answer['error']), 200);
            throw new PhycodeException("the authorization server did not let phycode in: {$why}");
        }
        if (($answer['code'] ?? '') === '') {
            throw new PhycodeException('the answer of the browser has no code');
        }
    }

    private function tokenRequest(array $meta, array $client, array $fields): array
    {
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        $fields += ['client_id' => $client['client_id'], 'resource' => $this->resource];
        if ($client['client_secret'] !== null) {
            $methods = is_array($meta['token_endpoint_auth_methods_supported'] ?? null) ? $meta['token_endpoint_auth_methods_supported'] : [];
            if (in_array('client_secret_basic', $methods, true) && !in_array('client_secret_post', $methods, true)) {
                $headers['Authorization'] = 'Basic ' . base64_encode(rawurlencode($client['client_id']) . ':' . rawurlencode($client['client_secret']));
            } else {
                $fields['client_secret'] = $client['client_secret'];
            }
        }
        $response = OAuthHttp::request('POST', (string) $meta['token_endpoint'], $headers, http_build_query($fields, '', '&', PHP_QUERY_RFC3986));
        $document = Json::object($response['body']);
        if ($response['status'] !== 200 || $document === null || !is_string($document['access_token'] ?? null) || $document['access_token'] === '') {
            throw new McpRpcException('the token endpoint refused the request (HTTP ' . $response['status'] . '): ' . self::describe($response['body']), null, null, $response['status']);
        }
        if (strtolower((string) ($document['token_type'] ?? 'bearer')) !== 'bearer') {
            throw new PhycodeException('the token endpoint gave a token of the type ' . Text::oneLine((string) $document['token_type'], 40) . ', and only Bearer is supported');
        }
        $tokens = ['access_token' => $document['access_token']];
        if (is_string($document['refresh_token'] ?? null) && $document['refresh_token'] !== '') {
            $tokens['refresh_token'] = $document['refresh_token'];
        }
        if (is_int($document['expires_in'] ?? null) && $document['expires_in'] > 0) {
            $tokens['expires_at'] = time() + $document['expires_in'];
        }
        if (is_string($document['scope'] ?? null)) {
            $tokens['scope'] = $document['scope'];
        }

        return $tokens;
    }

    private function refresh(): bool
    {
        $login = $this->load();
        if ($login === null || !is_string($login['refresh_token'] ?? null)) {
            return false;
        }
        try {
            $tokens = $this->tokenRequest(
                ['token_endpoint' => $login['token_endpoint'], 'token_endpoint_auth_methods_supported' => $login['token_endpoint_auth_methods_supported'] ?? []],
                ['client_id' => $login['client_id'], 'client_secret' => is_string($login['client_secret'] ?? null) ? $login['client_secret'] : null],
                ['grant_type' => 'refresh_token', 'refresh_token' => $login['refresh_token']],
            );
        } catch (McpRpcException $error) {
            unset($login['access_token'], $login['refresh_token'], $login['expires_at']);
            $this->vault->removeMcpLogin($this->server);
            if (isset($login['client_id'])) {
                $this->vault->storeMcpLogin($this->server, Json::encode(['resource' => $this->resource, 'issuer' => $login['issuer'] ?? '', 'client_id' => $login['client_id'], 'redirect_uri' => $login['redirect_uri'] ?? ''] + (isset($login['client_secret']) ? ['client_secret' => $login['client_secret']] : [])));
            }

            return false;
        } catch (PhycodeException) {
            return false;
        }
        unset($login['expires_at']);
        $this->vault->storeMcpLogin($this->server, Json::encode($tokens + $login));

        return true;
    }

    private function load(bool $client = false): ?array
    {
        $json = $this->vault->mcpLogin($this->server);
        $login = $json === null ? null : Json::object($json);
        if ($login === null || ($login['resource'] ?? null) !== $this->resource) {
            return null;
        }
        if (!$client && !is_string($login['access_token'] ?? null)) {
            return null;
        }

        return $login;
    }

    private static function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private static function describe(string $body): string
    {
        $document = Json::object($body);
        $text = $document !== null && is_string($document['error'] ?? null)
            ? $document['error'] . (is_string($document['error_description'] ?? null) ? ': ' . $document['error_description'] : '')
            : $body;

        return Text::oneLine(Redaction::text(trim($text)), 200);
    }
}
