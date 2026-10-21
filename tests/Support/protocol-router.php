<?php

declare(strict_types=1);

$folder = (string) getenv('PHYCODE_PROTOCOL_LOOPBACK');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$parts = explode('/', ltrim($path, '/'), 2);
$scenario = $parts[0];
$endpoint = '/' . ($parts[1] ?? '');
$body = (string) file_get_contents('php://input');

file_put_contents($folder . '/requests.jsonl', json_encode([
    'time' => microtime(true),
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    'scenario' => $scenario,
    'path' => $path,
    'query' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
    'headers' => [
        'x-api-key' => $_SERVER['HTTP_X_API_KEY'] ?? null,
        'anthropic-version' => $_SERVER['HTTP_ANTHROPIC_VERSION'] ?? null,
        'anthropic-beta' => $_SERVER['HTTP_ANTHROPIC_BETA'] ?? null,
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        'content-type' => $_SERVER['CONTENT_TYPE'] ?? null,
        'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
        'x-grok-conv-id' => $_SERVER['HTTP_X_GROK_CONV_ID'] ?? null,
        'x-session-id' => $_SERVER['HTTP_X_SESSION_ID'] ?? null,
    ],
    'body' => $body,
], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

$scenarios = json_decode((string) file_get_contents($folder . '/scenarios.json'), true, 512, JSON_THROW_ON_ERROR);
$steps = is_array($scenarios[$scenario][$endpoint] ?? null) ? $scenarios[$scenario][$endpoint] : [];
if ($steps === []) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"type":"error","error":{"type":"not_found_error","message":"no step is set for this endpoint"}}';

    return true;
}

$counter = $folder . '/count-' . $scenario . '-' . str_replace('/', '_', $endpoint);
$index = (int) @file_get_contents($counter);
file_put_contents($counter, (string) ($index + 1));
$step = $steps[min($index, count($steps) - 1)];

http_response_code((int) ($step['status'] ?? 200));
if (isset($step['fixture'])) {
    $fixture = (string) $step['fixture'];
    $file = dirname(__DIR__) . '/Fixtures/' . $fixture;
    if (str_contains($fixture, '..') || !is_file($file)) {
        http_response_code(500);
        echo 'no such fixture: ' . $fixture;

        return true;
    }
    $content = (string) file_get_contents($file);
    header('Content-Type: ' . ($step['type'] ?? (str_ends_with($fixture, '.sse') ? 'text/event-stream' : 'application/json')));
    if (!empty($step['truncate'])) {
        header('Content-Length: ' . (strlen($content) + 300));
    }
    echo $content;

    return true;
}

header('Content-Type: ' . ($step['type'] ?? 'application/json'));
echo (string) ($step['body'] ?? '');

return true;
