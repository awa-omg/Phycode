<?php

declare(strict_types=1);

$folder = (string) getenv('PHYCODE_LOOPBACK');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$scenario = explode('/', trim($path, '/'))[0];
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
$steps = is_array($scenarios[$scenario] ?? null) ? $scenarios[$scenario] : [];
if ($steps === []) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":{"message":"unknown scenario","type":"not_found_error","code":404}}';

    return true;
}

$counter = $folder . '/count-' . $scenario;
$index = (int) @file_get_contents($counter);
file_put_contents($counter, (string) ($index + 1));
$step = $steps[min($index, count($steps) - 1)];

http_response_code((int) ($step['status'] ?? 200));
if (isset($step['fixture'])) {
    $fixture = basename((string) $step['fixture']);
    $content = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/llama/' . $fixture);
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
