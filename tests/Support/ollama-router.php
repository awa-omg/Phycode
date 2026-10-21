<?php

declare(strict_types=1);

$folder = (string) getenv('PHYCODE_OLLAMA_LOOPBACK');
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
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'body' => $body,
], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

$scenarios = json_decode((string) file_get_contents($folder . '/scenarios.json'), true, 512, JSON_THROW_ON_ERROR);
$steps = is_array($scenarios[$scenario][$endpoint] ?? null) ? $scenarios[$scenario][$endpoint] : [];
if ($steps === []) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"no step is set for this endpoint"}';

    return true;
}

$counter = $folder . '/count-' . $scenario . '-' . str_replace('/', '_', $endpoint);
$index = (int) @file_get_contents($counter);
file_put_contents($counter, (string) ($index + 1));
$step = $steps[min($index, count($steps) - 1)];

http_response_code((int) ($step['status'] ?? 200));
if (isset($step['redirect'])) {
    header('Location: http://' . (string) ($_SERVER['HTTP_HOST'] ?? '') . '/' . $step['redirect'] . $endpoint);
}
if (isset($step['fixture'])) {
    $fixture = (string) $step['fixture'];
    $path = dirname(__DIR__) . '/Fixtures/ollama/' . $fixture;
    if (str_contains($fixture, '..') || !is_file($path)) {
        http_response_code(500);
        echo 'no such fixture: ' . $fixture;

        return true;
    }
    $content = (string) file_get_contents($path);
    header('Content-Type: ' . ($step['type'] ?? (str_ends_with($fixture, '.ndjson') ? 'application/x-ndjson' : 'application/json')));
    if (!empty($step['truncate'])) {
        header('Content-Length: ' . (strlen($content) + 300));
    }
    echo $content;

    return true;
}

header('Content-Type: ' . ($step['type'] ?? 'application/json'));
echo (string) ($step['body'] ?? '');

return true;
