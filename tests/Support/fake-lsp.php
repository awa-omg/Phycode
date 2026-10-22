<?php

declare(strict_types=1);

$scenario = isset($argv[1]) ? json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR) : [];
$logFile = $scenario['log'] ?? null;

function record(?string $logFile, array $entry): void
{
    if ($logFile !== null) {
        file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
}

function frame(array $message): string
{
    $body = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

    return 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
}

function emit(string $bytes, int $chunkBytes): void
{
    if ($chunkBytes <= 0) {
        fwrite(STDOUT, $bytes);
        fflush(STDOUT);

        return;
    }
    foreach (str_split($bytes, $chunkBytes) as $piece) {
        fwrite(STDOUT, $piece);
        fflush(STDOUT);
        usleep(2000);
    }
}

function answer(array $scenario, string $method, mixed $id): array
{
    static $failures = [];
    $failure = $scenario['errors'][$method] ?? null;
    if ($failure !== null && ($failures[$method] ?? 0) < ($failure['times'] ?? PHP_INT_MAX)) {
        $failures[$method] = ($failures[$method] ?? 0) + 1;

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $failure['code'], 'message' => $failure['message']]];
    }
    if (array_key_exists($method, $scenario['results'] ?? [])) {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $scenario['results'][$method]];
    }

    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method]];
}

record($logFile, ['pid' => getmypid()]);
if (!empty($scenario['ignoreTerm'])) {
    pcntl_signal(SIGTERM, SIG_IGN);
}

$capabilities = array_replace([
    'textDocumentSync' => 1,
    'definitionProvider' => true,
    'referencesProvider' => true,
    'hoverProvider' => true,
    'documentSymbolProvider' => true,
    'workspaceSymbolProvider' => true,
], $scenario['capabilities'] ?? []);
$chunkBytes = (int) ($scenario['chunkBytes'] ?? 0);

stream_set_blocking(STDIN, false);
$buffer = '';
$due = [];
$shutdownSeen = false;
$versions = [];

while (true) {
    $now = microtime(true);
    foreach ($due as $index => [$at, $message]) {
        if ($at <= $now) {
            if (isset($message['method'])) {
                record($logFile, ['method' => 'sent:' . $message['method']]);
            }
            emit(frame($message), $chunkBytes);
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

    while (($headerEnd = strpos($buffer, "\r\n\r\n")) !== false) {
        preg_match('/Content-Length: (\d+)/i', substr($buffer, 0, $headerEnd), $match);
        $length = (int) $match[1];
        if (strlen($buffer) < $headerEnd + 4 + $length) {
            break;
        }
        $message = json_decode(substr($buffer, $headerEnd + 4, $length), true);
        $buffer = substr($buffer, $headerEnd + 4 + $length);
        record($logFile, $message);

        $method = $message['method'] ?? null;
        if (!is_string($method)) {
            continue;
        }
        $id = $message['id'] ?? null;

        if ($method === 'initialize') {
            if (!empty($scenario['garbage'])) {
                emit("Oops this is not LSP\r\n\r\n", 0);
                continue;
            }
            if (isset($scenario['initializeError'])) {
                emit(frame(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => $scenario['initializeError']]]), $chunkBytes);
                continue;
            }
            $reply = frame(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['capabilities' => $capabilities, 'serverInfo' => ['name' => 'fake-lsp']]]);
            if (isset($scenario['initializeDelay'])) {
                usleep((int) ($scenario['initializeDelay'] * 1_000_000));
            }
            emit($reply, $chunkBytes);
            continue;
        }
        if ($method === 'initialized') {
            foreach ($scenario['serverRequests'] ?? [] as $request) {
                $due[] = [$now, ['jsonrpc' => '2.0', 'id' => $request['id'], 'method' => $request['method'], 'params' => $request['params'] ?? []]];
            }
            foreach ($scenario['notifications'] ?? [] as $notification) {
                $due[] = [$now + ($notification['after'] ?? 0.0), ['jsonrpc' => '2.0', 'method' => $notification['method'], 'params' => $notification['params'] ?? []]];
            }
            if (!empty($scenario['registerPull'])) {
                $due[] = [$now, ['jsonrpc' => '2.0', 'id' => 'reg-1', 'method' => 'client/registerCapability', 'params' => ['registrations' => [['id' => 'diagnostics-1', 'method' => 'textDocument/diagnostic']]]]];
            }
            continue;
        }
        if ($method === 'shutdown') {
            $shutdownSeen = true;
            emit(frame(['jsonrpc' => '2.0', 'id' => $id, 'result' => null]), $chunkBytes);
            continue;
        }
        if ($method === 'exit') {
            if (empty($scenario['ignoreExit'])) {
                exit($shutdownSeen ? 0 : 1);
            }
            continue;
        }
        if ($method === 'textDocument/didOpen' || $method === 'textDocument/didChange') {
            $uri = $message['params']['textDocument']['uri'];
            $versions[$uri] = $message['params']['textDocument']['version'];
            foreach ($scenario['publish'] ?? [] as $batch) {
                $due[] = [$now + ($batch['after'] ?? 0.0), [
                    'jsonrpc' => '2.0',
                    'method' => 'textDocument/publishDiagnostics',
                    'params' => ['uri' => $uri, 'version' => $batch['version'] ?? $versions[$uri], 'diagnostics' => $batch['diagnostics']],
                ]];
            }
            continue;
        }
        if ($id === null) {
            continue;
        }

        if (($scenario['crashOn'] ?? null) === $method) {
            $marker = $scenario['crashOnce'] ?? null;
            if ($marker === null || !is_file($marker)) {
                if ($marker !== null) {
                    file_put_contents($marker, '1');
                }
                fwrite(STDERR, ($scenario['crashMessage'] ?? 'crashing on purpose') . "\n");
                exit(3);
            }
        }
        if (in_array($method, $scenario['silent'] ?? [], true)) {
            continue;
        }
        $delay = $scenario['delays'][$method] ?? null;
        $reply = answer($scenario, $method, $id);
        if ($delay !== null) {
            $due[] = [$now + $delay, $reply];
        } else {
            emit(frame($reply), $chunkBytes);
        }
    }
}
