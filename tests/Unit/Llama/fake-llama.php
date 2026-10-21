<?php

declare(strict_types=1);

$address = $argv[1] ?? '';
$folder = $argv[2] ?? '';
if (str_starts_with($address, 'unix://')) {
    @unlink(substr($address, strlen('unix://')));
}
$server = stream_socket_server($address, $errno, $message);
if ($server === false) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
file_put_contents($folder . '/ready', '');

$routes = json_decode((string) file_get_contents($folder . '/routes.json'), true, 512, JSON_THROW_ON_ERROR);
$apiKey = is_file($folder . '/api-key') ? trim((string) file_get_contents($folder . '/api-key')) : '';
$served = [];

while (($connection = stream_socket_accept($server, -1)) !== false) {
    stream_set_timeout($connection, 5);
    $head = (string) stream_get_line($connection, 65536, "\r\n\r\n");
    [$requestLine, $headerText] = array_pad(explode("\r\n", $head, 2), 2, '');
    [$method, $target] = array_pad(explode(' ', $requestLine, 3), 3, '');
    $headers = [];
    foreach (explode("\r\n", $headerText) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }
    $length = (int) ($headers['content-length'] ?? 0);
    $body = '';
    while (strlen($body) < $length && ($chunk = fread($connection, $length - strlen($body))) !== false && $chunk !== '') {
        $body .= $chunk;
    }

    $path = (string) parse_url($target, PHP_URL_PATH);
    $key = $method . ' ' . $path;
    $steps = is_array($routes[$key] ?? null) ? $routes[$key] : [];
    if ($apiKey !== '' && $path !== '/health' && ($headers['authorization'] ?? '') !== "Bearer {$apiKey}") {
        $step = ['status' => 401, 'body' => '{"error":{"code":401,"message":"Invalid API Key","type":"authentication_error"}}'];
    } elseif ($steps === []) {
        $step = ['status' => 404, 'body' => '{"error":{"message":"File Not Found","type":"not_found_error","code":404}}'];
    } else {
        $step = $steps[min($served[$key] ?? 0, count($steps) - 1)];
        $served[$key] = ($served[$key] ?? 0) + 1;
    }

    file_put_contents($folder . '/requests.jsonl', json_encode([
        'method' => $method,
        'target' => $target,
        'host' => $headers['host'] ?? null,
        'authorization' => $headers['authorization'] ?? null,
        'body' => $body,
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

    $status = (int) ($step['status'] ?? 200);
    $payload = isset($step['fixture'])
        ? (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/llama/' . basename((string) $step['fixture']))
        : (string) ($step['body'] ?? '');
    $type = (string) ($step['type'] ?? (str_ends_with((string) ($step['fixture'] ?? ''), '.sse') ? 'text/event-stream' : 'application/json'));
    fwrite($connection, "HTTP/1.1 {$status} " . ($status < 400 ? 'OK' : 'Error') . "\r\nContent-Type: {$type}\r\nContent-Length: " . strlen($payload) . "\r\nConnection: close\r\n\r\n" . $payload);
    fclose($connection);
}
