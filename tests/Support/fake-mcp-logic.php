<?php

declare(strict_types=1);

function capabilities(array $scenario): array
{
    $capabilities = ['tools' => new stdClass()];
    if (isset($scenario['resources']) || isset($scenario['resourceTemplates'])) {
        $capabilities['resources'] = new stdClass();
    }
    if (isset($scenario['prompts'])) {
        $capabilities['prompts'] = new stdClass();
    }

    return $capabilities;
}

function respond(string $method, array $params, array $scenario, array $tools): array
{
    if (!empty($scenario['modern'])) {
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        if (($meta['io.modelcontextprotocol/protocolVersion'] ?? null) !== '2026-07-28' || !array_key_exists('io.modelcontextprotocol/clientCapabilities', $meta)) {
            return ['error' => ['code' => -32602, 'message' => 'Missing the required _meta fields']];
        }
        if ($method === 'initialize') {
            return ['error' => ['code' => -32601, 'message' => 'Method not found: initialize; this server speaks 2026-07-28']];
        }
    }
    $answer = answer($method, $params, $scenario, $tools);
    if (!empty($scenario['modern']) && isset($answer['result']) && is_array($answer['result']) && !isset($answer['result']['resultType'])) {
        $answer['result']['resultType'] = 'complete';
    }

    return $answer;
}

function answer(string $method, array $params, array $scenario, array $tools): array
{
    switch ($method) {
        case 'server/discover':
            if (isset($scenario['unsupportedVersions'])) {
                return ['error' => ['code' => -32022, 'message' => 'Unsupported protocol version', 'data' => ['supported' => $scenario['unsupportedVersions'], 'requested' => $params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null]]];
            }
            if (empty($scenario['modern'])) {
                return ['error' => ['code' => -32601, 'message' => 'Method not found: server/discover']];
            }

            return ['result' => array_filter([
                'supportedVersions' => ['2026-07-28'],
                'capabilities' => capabilities($scenario),
                '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'fake-echo', 'version' => '1.0.0']],
                'instructions' => $scenario['instructions'] ?? null,
            ], static fn (mixed $value): bool => $value !== null)];
        case 'initialize':
            if (isset($scenario['initializeError'])) {
                return ['error' => $scenario['initializeError']];
            }

            return ['result' => array_filter([
                'protocolVersion' => $scenario['protocolVersion'] ?? '2025-06-18',
                'capabilities' => capabilities($scenario),
                'serverInfo' => ['name' => 'fake-echo', 'version' => '1.0.0'],
                'instructions' => $scenario['instructions'] ?? null,
            ], static fn (mixed $value): bool => $value !== null)];
        case 'resources/list':
            return ['result' => ['resources' => $scenario['resources'] ?? []]];
        case 'resources/templates/list':
            return ['result' => ['resourceTemplates' => $scenario['resourceTemplates'] ?? []]];
        case 'resources/read':
            $uri = (string) ($params['uri'] ?? '');
            if (!isset($scenario['resourceContents'][$uri])) {
                return ['error' => ['code' => -32602, 'message' => 'Resource not found', 'data' => ['uri' => $uri]]];
            }

            return ['result' => ['contents' => $scenario['resourceContents'][$uri]]];
        case 'prompts/list':
            return ['result' => ['prompts' => $scenario['prompts'] ?? []]];
        case 'prompts/get':
            $name = (string) ($params['name'] ?? '');
            if (!isset($scenario['promptMessages'][$name])) {
                return ['error' => ['code' => -32602, 'message' => "Unknown prompt: {$name}"]];
            }
            $text = json_encode(['description' => "prompt {$name}", 'messages' => $scenario['promptMessages'][$name]], JSON_THROW_ON_ERROR);
            foreach (is_array($params['arguments'] ?? null) ? $params['arguments'] : [] as $key => $value) {
                $text = str_replace('{' . $key . '}', trim((string) json_encode((string) $value, JSON_UNESCAPED_UNICODE), '"'), $text);
            }

            return ['result' => json_decode($text, true, 512, JSON_THROW_ON_ERROR)];
        case 'tools/list':
            $size = (int) ($scenario['pageSize'] ?? count($tools));
            $offset = (int) ($params['cursor'] ?? 0);
            $result = ['tools' => array_slice($tools, $offset, $size)];
            if ($offset + $size < count($tools)) {
                $result['nextCursor'] = (string) ($offset + $size);
            }

            return ['result' => $result];
        case 'tools/call':
            $name = (string) ($params['name'] ?? '');
            if (!in_array($name, array_column($tools, 'name'), true)) {
                return ['error' => ['code' => -32602, 'message' => "Unknown tool: {$name}"]];
            }
            if (!empty($scenario['inputRequired'])) {
                return ['result' => ['resultType' => 'input_required', 'inputRequests' => ['who' => ['method' => 'elicitation/create', 'params' => ['message' => 'Who?']]]]];
            }
            if (isset($scenario['callResult'])) {
                return ['result' => $scenario['callResult']];
            }
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $text = is_string($arguments['text'] ?? null)
                ? $arguments['text']
                : json_encode((object) $arguments, JSON_THROW_ON_ERROR);

            return ['result' => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false]];
        case 'ping':
            return ['result' => new stdClass()];
        default:
            return ['error' => ['code' => -32601, 'message' => "Method not found: {$method}"]];
    }
}
