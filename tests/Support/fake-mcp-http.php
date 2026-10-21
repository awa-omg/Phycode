<?php

declare(strict_types=1);

require_once __DIR__ . '/fake-mcp-logic.php';

$folder = (string) getenv('PHYCODE_FAKE_MCP_HTTP');
$scenario = json_decode((string) file_get_contents($folder . '/scenario.json'), true, 512, JSON_THROW_ON_ERROR);
$state = is_file($folder . '/state.json') ? json_decode((string) file_get_contents($folder . '/state.json'), true) : ['requests' => 0, 'sessions' => 0, 'session' => '', 'born' => 0];
$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower($name)] = $value;
}
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
file_put_contents($folder . '/requests.jsonl', json_encode(['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

function reply(int $status, mixed $payload = null, array $extra = []): bool
{
    http_response_code($status);
    foreach ($extra as $name => $value) {
        header("{$name}: {$value}");
    }
    if ($payload !== null) {
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_THROW_ON_ERROR);
    }

    return true;
}

function rpcError(?int $id, int $code, string $message, array $data = []): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message] + ($data === [] ? [] : ['data' => $data])];
}

if ($path === '/redirect') {
    return reply(302, null, ['Location' => '/mcp']);
}

$origin = 'http://' . $_SERVER['HTTP_HOST'];
$oauth = !empty($scenario['oauth']);

function readJson(string $file): array
{
    return is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
}

function writeJson(string $file, array $data): void
{
    file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
}

if ($oauth) {
    $prm = (string) ($scenario['prm'] ?? 'header');
    $resourceMeta = static fn (string $resource): array => ['resource' => $scenario['prmResource'] ?? $resource, 'authorization_servers' => [$origin], 'scopes_supported' => ['files:read', 'files:write']];
    if ($path === '/.well-known/oauth-protected-resource/mcp' && $prm !== 'wellknown-root') {
        return reply(200, $resourceMeta($origin . '/mcp'));
    }
    if ($path === '/.well-known/oauth-protected-resource' && $prm === 'wellknown-root') {
        return reply(200, $resourceMeta($origin));
    }
    $metaPath = ($scenario['asMeta'] ?? 'oauth') === 'openid' ? '/.well-known/openid-configuration' : '/.well-known/oauth-authorization-server';
    if ($path === $metaPath) {
        return reply(200, array_filter([
            'issuer' => $scenario['issuerOverride'] ?? $origin,
            'authorization_endpoint' => $origin . '/authorize',
            'token_endpoint' => $origin . '/token',
            'registration_endpoint' => ($scenario['dcr'] ?? true) ? $origin . '/register' : null,
            'code_challenge_methods_supported' => ($scenario['pkce'] ?? true) ? ['S256'] : null,
            'authorization_response_iss_parameter_supported' => !empty($scenario['issParam']) ? true : null,
            'client_id_metadata_document_supported' => !empty($scenario['cimd']) ? true : null,
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
        ], static fn (mixed $value): bool => $value !== null));
    }
    if ($path === '/register' && $method === 'POST') {
        $clients = readJson($folder . '/clients.json');
        $registration = json_decode($body, true);
        $id = 'client-' . (count($clients) + 1);
        $clients[$id] = $registration;
        writeJson($folder . '/clients.json', $clients);

        return reply(201, ['client_id' => $id]);
    }
    if ($path === '/authorize' && $method === 'GET') {
        $query = $_GET;
        $clients = readJson($folder . '/clients.json');
        $known = isset($clients[$query['client_id'] ?? '']) || in_array($query['client_id'] ?? '', $scenario['preregistered'] ?? [], true) || (!empty($scenario['cimd']) && str_starts_with((string) ($query['client_id'] ?? ''), 'https://'));
        $problems = [];
        if (($query['response_type'] ?? '') !== 'code') {
            $problems[] = 'response_type';
        }
        if (!$known) {
            $problems[] = 'client_id';
        }
        if (!str_starts_with((string) ($query['redirect_uri'] ?? ''), 'http://127.0.0.1:')) {
            $problems[] = 'redirect_uri';
        }
        if (($query['code_challenge_method'] ?? '') !== 'S256' || strlen((string) ($query['code_challenge'] ?? '')) < 43) {
            $problems[] = 'code_challenge';
        }
        if (($query['resource'] ?? '') !== $origin . '/mcp') {
            $problems[] = 'resource';
        }
        if ($problems !== []) {
            return reply(400, ['error' => 'invalid_request', 'error_description' => 'bad: ' . implode(',', $problems)]);
        }
        $iss = !empty($scenario['issWrong']) ? 'https://evil.example' : $origin;
        $tail = '&state=' . rawurlencode((string) $query['state']) . ((!empty($scenario['issParam']) && empty($scenario['issMissing'])) || !empty($scenario['issWrong']) ? '&iss=' . rawurlencode($iss) : '');
        if (!empty($scenario['denyLogin'])) {
            return reply(302, null, ['Location' => $query['redirect_uri'] . '?error=access_denied&error_description=' . rawurlencode('The user said no') . $tail]);
        }
        $code = 'code-' . bin2hex(random_bytes(6));
        $codes = readJson($folder . '/codes.json');
        $codes[$code] = ['challenge' => $query['code_challenge'], 'client_id' => $query['client_id'], 'redirect_uri' => $query['redirect_uri'], 'scope' => $query['scope'] ?? '', 'resource' => $query['resource']];
        writeJson($folder . '/codes.json', $codes);
        file_put_contents($folder . '/authorize-requests.jsonl', json_encode($query) . "\n", FILE_APPEND);

        return reply(302, null, ['Location' => $query['redirect_uri'] . '?code=' . $code . $tail]);
    }
    if ($path === '/token' && $method === 'POST') {
        parse_str($body, $form);
        $log = $form;
        unset($log['code_verifier'], $log['client_secret'], $log['refresh_token'], $log['code']);
        file_put_contents($folder . '/token-requests.jsonl', json_encode(['form' => $log, 'authorization' => $headers['authorization'] ?? null, 'has_secret' => isset($form['client_secret'])]) . "\n", FILE_APPEND);
        $issue = static function (string $client, string $scope) use ($folder, $scenario): array {
            $tokens = readJson($folder . '/tokens.json');
            $n = count($tokens) + 1;
            $access = "at-{$n}";
            $tokens[$access] = ['revoked' => false, 'expires' => time() + (int) ($scenario['expiresIn'] ?? 3600), 'client_id' => $client];
            writeJson($folder . '/tokens.json', $tokens);
            $refresh = readJson($folder . '/refresh.json');
            $new = "rt-{$n}";
            $refresh[$new] = ['client_id' => $client, 'scope' => $scope, 'used' => false];
            writeJson($folder . '/refresh.json', $refresh);

            return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => (int) ($scenario['expiresIn'] ?? 3600), 'refresh_token' => $new, 'scope' => $scope];
        };
        if (($form['grant_type'] ?? '') === 'authorization_code') {
            $codes = readJson($folder . '/codes.json');
            $entry = $codes[$form['code'] ?? ''] ?? null;
            $challenge = rtrim(strtr(base64_encode(hash('sha256', (string) ($form['code_verifier'] ?? ''), true)), '+/', '-_'), '=');
            if ($entry === null || $entry['client_id'] !== ($form['client_id'] ?? '') || $entry['redirect_uri'] !== ($form['redirect_uri'] ?? '') || $entry['challenge'] !== $challenge || ($form['resource'] ?? '') !== $origin . '/mcp') {
                return reply(400, ['error' => 'invalid_grant']);
            }
            unset($codes[$form['code']]);
            writeJson($folder . '/codes.json', $codes);

            return reply(200, $issue($entry['client_id'], $entry['scope']));
        }
        if (($form['grant_type'] ?? '') === 'refresh_token') {
            $refresh = readJson($folder . '/refresh.json');
            $entry = $refresh[$form['refresh_token'] ?? ''] ?? null;
            if (!empty($scenario['refreshFails']) || $entry === null || $entry['used'] || $entry['client_id'] !== ($form['client_id'] ?? '') || ($form['resource'] ?? '') !== $origin . '/mcp') {
                return reply(400, ['error' => 'invalid_grant', 'error_description' => 'the refresh token is not valid']);
            }
            $refresh[$form['refresh_token']]['used'] = true;
            writeJson($folder . '/refresh.json', $refresh);
            $tokens = $issue($entry['client_id'], $entry['scope']);
            if (!empty($scenario['noRotate'])) {
                unset($tokens['refresh_token']);
            }

            return reply(200, $tokens);
        }

        return reply(400, ['error' => 'unsupported_grant_type']);
    }
}
if ($path !== '/mcp') {
    return reply(404);
}
$token = $scenario['token'] ?? null;
$challengeParameters = (($scenario['prm'] ?? 'header') === 'header' && $oauth ? "resource_metadata=\"{$origin}/.well-known/oauth-protected-resource/mcp\", " : '') . 'scope="files:read"';
if ($oauth) {
    $given = preg_match('/^Bearer (\S+)$/', $headers['authorization'] ?? '', $match) === 1 ? $match[1] : '';
    $issued = readJson($folder . '/tokens.json')[$given] ?? null;
    if ($issued === null || $issued['revoked'] || $issued['expires'] < time()) {
        return reply(401, null, ['WWW-Authenticate' => "Bearer {$challengeParameters}"]);
    }
} elseif ($token !== null && ($headers['authorization'] ?? '') !== "Bearer {$token}") {
    return reply(401, null, ['WWW-Authenticate' => "Bearer resource_metadata=\"{$origin}/.well-known/oauth-protected-resource/mcp\", scope=\"files:read\""]);
}
if ($method === 'DELETE') {
    file_put_contents($folder . '/deleted', $headers['mcp-session-id'] ?? '');

    return reply(200);
}
if ($method !== 'POST') {
    return reply(405);
}

$message = json_decode($body, true);
if (!is_array($message) || !isset($message['method'])) {
    return reply(400, rpcError(null, -32700, 'Parse error'));
}
$rpc = (string) $message['method'];
$id = $message['id'] ?? null;
$params = is_array($message['params'] ?? null) ? $message['params'] : [];
$modern = ($scenario['era'] ?? 'modern') === 'modern';
$tools = array_merge([[
    'name' => 'echo',
    'description' => 'Echoes the text argument back.',
    'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
]], $scenario['tools'] ?? []);
$scenario['modern'] = $modern;

$state['requests']++;
if ($modern) {
    $version = $headers['mcp-protocol-version'] ?? null;
    $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
    if ($version === null || $version !== ($meta['io.modelcontextprotocol/protocolVersion'] ?? null)) {
        return reply(400, rpcError($id, -32020, 'Header mismatch: MCP-Protocol-Version'));
    }
    if (($headers['mcp-method'] ?? null) !== $rpc) {
        return reply(400, rpcError($id, -32020, 'Header mismatch: Mcp-Method'));
    }
    $named = match ($rpc) {
        'tools/call', 'prompts/get' => $params['name'] ?? null,
        'resources/read' => $params['uri'] ?? null,
        default => null,
    };
    if (is_string($named)) {
        $sent = $headers['mcp-name'] ?? '';
        if (preg_match('/^=\?base64\?(.*)\?=$/s', $sent, $match) === 1) {
            $sent = (string) base64_decode($match[1], true);
        }
        if ($sent !== $named) {
            return reply(400, rpcError($id, -32020, 'Header mismatch: Mcp-Name'));
        }
    }
} else {
    $probe = (string) ($scenario['probe'] ?? 'rpc-error');
    if ($rpc === 'server/discover' && $probe !== 'rpc-error') {
        return reply((int) $probe);
    }
    if ($rpc !== 'initialize' && $rpc !== 'server/discover' && !empty($scenario['session'])) {
        if (($headers['mcp-session-id'] ?? '') === '') {
            return reply(400, rpcError($id, -32600, 'Missing session id'));
        }
        $expired = isset($scenario['expireSession']) && $state['requests'] - $state['born'] > $scenario['expireSession'];
        if ($headers['mcp-session-id'] !== $state['session'] || $expired) {
            $state['session'] = '';
            file_put_contents($folder . '/state.json', json_encode($state));

            return reply(404);
        }
    }
    if ($rpc !== 'initialize' && $rpc !== 'server/discover' && ($headers['mcp-protocol-version'] ?? '') === '') {
        return reply(400, rpcError($id, -32600, 'Missing MCP-Protocol-Version'));
    }
}

$extra = [];
if ($rpc === 'initialize' && !$modern && !empty($scenario['session'])) {
    $state['sessions']++;
    $state['session'] = 'sess-' . $state['sessions'];
    $state['born'] = $state['requests'];
    $extra['MCP-Session-Id'] = $state['session'];
}
file_put_contents($folder . '/state.json', json_encode($state));

if ($id === null) {
    return reply(202);
}
if ($rpc === 'tools/call' && isset($scenario['statusOnCall'])) {
    return reply((int) $scenario['statusOnCall']);
}

$answer = respond($rpc, $params, $scenario, $tools);
$response = ['jsonrpc' => '2.0', 'id' => $id] + $answer;
if (!empty($scenario['sse'])) {
    header('Content-Type: text/event-stream');
    header('X-Accel-Buffering: no');
    foreach ($extra as $name => $value) {
        header("{$name}: {$value}");
    }
    echo ": keep-alive\n\n";
    echo 'data: ' . json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progressToken' => 1, 'progress' => 1]]) . "\n\n";
    echo 'data: ' . json_encode($response, JSON_THROW_ON_ERROR) . "\n\n";

    return true;
}

return reply(200, $response, $extra);
