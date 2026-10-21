<?php

declare(strict_types=1);

ini_set('default_charset', '');

$folder = (string) getenv('PHYCODE_WEB_FIXTURES');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
    }
}
file_put_contents($folder . '/requests.jsonl', json_encode([
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    'path' => $path,
    'query' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
    'headers' => $headers,
    'body' => (string) file_get_contents('php://input'),
], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

$routes = json_decode((string) file_get_contents($folder . '/routes.json'), true, 512, JSON_THROW_ON_ERROR);
$steps = is_array($routes[$path] ?? null) ? $routes[$path] : [];
if ($steps === []) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'no route';

    return true;
}

$counter = $folder . '/count-' . md5($path);
$index = (int) @file_get_contents($counter);
file_put_contents($counter, (string) ($index + 1));
$step = $steps[min($index, count($steps) - 1)];

if (isset($step['sleep'])) {
    usleep((int) ($step['sleep'] * 1_000_000));
}
http_response_code((int) ($step['status'] ?? 200));
foreach ($step['headers'] ?? [] as $name => $value) {
    header($name . ': ' . $value);
}

if (isset($step['fixture'])) {
    $file = (string) $step['fixture'];
    if (str_contains($file, '..') || str_starts_with($file, '/')) {
        http_response_code(500);

        return true;
    }
    $type = $step['type'] ?? (str_ends_with($file, '.json') ? 'application/json' : 'text/html; charset=utf-8');
    header('Content-Type: ' . $type);
    echo (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/web/' . $file);

    return true;
}

header('Content-Type: ' . ($step['type'] ?? 'text/plain; charset=utf-8'));
if (isset($step['body_base64'])) {
    echo base64_decode((string) $step['body_base64']);
} elseif (isset($step['bytes'])) {
    echo str_repeat('<p>padding paragraph</p>', intdiv((int) $step['bytes'], 24) + 1);
} else {
    echo (string) ($step['body'] ?? '');
}

return true;
