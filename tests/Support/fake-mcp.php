<?php

declare(strict_types=1);

require_once __DIR__ . '/fake-mcp-logic.php';

function record(?string $log, string $line): void
{
    if ($log !== null) {
        file_put_contents($log, $line . "\n", FILE_APPEND);
    }
}

$scenario = isset($argv[1])
    ? json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR)
    : [];
$logFile = getenv('PHYCODE_FAKE_MCP_LOG');
$log = is_string($logFile) && $logFile !== '' ? $logFile : null;
$tools = array_merge([[
    'name' => 'echo',
    'description' => 'Echoes the text argument back.',
    'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
]], $scenario['tools'] ?? []);

record($log, json_encode(['pid' => getmypid()], JSON_THROW_ON_ERROR));
$stderrBytes = (int) (getenv('PHYCODE_FAKE_MCP_STDERR_BYTES') ?: 0);
if ($stderrBytes > 0) {
    fwrite(STDERR, str_repeat('x', $stderrBytes) . "\nEND-OF-STDERR\n");
}
if (isset($scenario['stderr'])) {
    fwrite(STDERR, $scenario['stderr'] . "\n");
}
if (!empty($scenario['ignoreTerm']) && function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, SIG_IGN);
}

stream_set_blocking(STDIN, false);
$buffer = '';
$due = [];
while (true) {
    $now = microtime(true);
    foreach ($due as $index => [$at, $line]) {
        if ($at <= $now) {
            fwrite(STDOUT, $line);
            unset($due[$index]);
        }
    }

    $read = [STDIN];
    $write = null;
    $except = null;
    if ($due === []) {
        stream_select($read, $write, $except, null);
    } else {
        $wait = max(0.0, min(array_column($due, 0)) - $now);
        stream_select($read, $write, $except, (int) $wait, (int) (($wait - floor($wait)) * 1_000_000));
    }

    $chunk = fread(STDIN, 65536);
    if ($chunk === false || $chunk === '') {
        if (feof(STDIN)) {
            break;
        }

        continue;
    }
    $buffer .= $chunk;

    while (($end = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $end);
        $buffer = substr($buffer, $end + 1);
        $message = json_decode($line, true);
        if (!is_array($message)) {
            continue;
        }
        record($log, $line);

        $method = $message['method'] ?? null;
        if (!is_string($method)) {
            continue;
        }
        if (isset($scenario['crashOn']) && $scenario['crashOn'] === $method) {
            exit(3);
        }
        if (!array_key_exists('id', $message)) {
            continue;
        }

        if (!empty($scenario['pingFirst']) && $method === 'tools/call') {
            fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => 'ping-1', 'method' => 'ping'], JSON_THROW_ON_ERROR) . "\n");
        }
        if (!empty($scenario['stray'])) {
            fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => 999, 'result' => new stdClass()], JSON_THROW_ON_ERROR) . "\n");
        }

        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $response = json_encode(['jsonrpc' => '2.0', 'id' => $message['id']] + respond($method, $params, $scenario, $tools), JSON_THROW_ON_ERROR) . "\n";
        $delay = (int) ($scenario['delayMs'][$method] ?? 0);
        if ($delay > 0) {
            $due[] = [microtime(true) + $delay / 1000, $response];
        } else {
            fwrite(STDOUT, $response);
        }
    }
}

if (!empty($scenario['ignoreEof'])) {
    sleep(60);
}

exit(0);
