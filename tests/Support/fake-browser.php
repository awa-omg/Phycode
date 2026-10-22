<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Phycode\Browser\PageScripts;

final class FakeChrome
{
    private array $targets = [];
    private array $sessionTargets = [];
    private array $popupUrls = [];
    private int $counter = 0;
    private int $targetCount = 0;
    private $client;

    public function __construct(private readonly string $control, private readonly array $scenario, private readonly int $port, private readonly string $path)
    {
    }

    public function serve($server): void
    {
        while (true) {
            $client = stream_socket_accept($server, -1);
            if ($client === false) {
                continue;
            }
            $this->client = $client;
            $this->connection();
            fclose($client);
        }
    }

    private function connection(): void
    {
        $request = '';
        while (!str_contains($request, "\r\n\r\n")) {
            $chunk = fread($this->client, 4096);
            if ($chunk === false || $chunk === '') {
                return;
            }
            $request .= $chunk;
        }
        $lines = explode("\r\n", $request);
        $target = explode(' ', $lines[0])[1] ?? '/';
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        $this->record('http.jsonl', ['target' => $target, 'host' => $headers['host'] ?? '', 'upgrade' => $headers['upgrade'] ?? '']);
        if ($target === '/json/version') {
            $url = $this->scenario['versionUrl'] ?? "ws://127.0.0.1:{$this->port}{$this->path}";
            $this->http('200 OK', json_encode(['Browser' => 'FakeChrome/1.0', 'Protocol-Version' => '1.3', 'webSocketDebuggerUrl' => $url], JSON_THROW_ON_ERROR));

            return;
        }
        if ($target !== $this->path || strtolower($headers['upgrade'] ?? '') !== 'websocket') {
            $this->http('404 Not Found', 'not found');

            return;
        }
        $mode = $this->scenario['handshake'] ?? 'ok';
        if ($mode === 'forbidden') {
            $this->http('403 Forbidden', 'Rejected an incoming WebSocket connection');

            return;
        }
        $accept = base64_encode(sha1(($headers['sec-websocket-key'] ?? '') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $accept = $mode === 'bad-accept' ? base64_encode('wrong') : $accept;
        fwrite($this->client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
        if ($mode === 'silent') {
            sleep(30);

            return;
        }
        while (($message = $this->next()) !== null) {
            $this->dispatch($message);
        }
    }

    private function http(string $status, string $body): void
    {
        fwrite($this->client, "HTTP/1.1 {$status}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n{$body}");
    }

    private function next(): ?array
    {
        while (true) {
            $frame = $this->frame();
            if ($frame === null || $frame['opcode'] === 8) {
                return null;
            }
            if ($frame['opcode'] === 9) {
                fwrite($this->client, $this->encode(10, $frame['payload']));
            }
            if ($frame['opcode'] === 1) {
                return json_decode($frame['payload'], true, 512, JSON_THROW_ON_ERROR);
            }
        }
    }

    private function frame(): ?array
    {
        $head = $this->exactly(2);
        if ($head === null) {
            return null;
        }
        $length = ord($head[1]) & 0x7F;
        if ($length === 126) {
            $length = unpack('n', (string) $this->exactly(2))[1];
        } elseif ($length === 127) {
            $length = unpack('J', (string) $this->exactly(8))[1];
        }
        $mask = (ord($head[1]) & 0x80) !== 0 ? $this->exactly(4) : null;
        $payload = $this->exactly($length) ?? '';
        if ($mask === null) {
            $this->record('protocol.jsonl', ['error' => 'unmasked client frame']);
        } else {
            $payload ^= str_repeat($mask, intdiv(strlen($payload), 4) + 1);
        }

        return ['opcode' => ord($head[0]) & 0x0F, 'payload' => $payload];
    }

    private function exactly(int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->client, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function encode(int $opcode, string $payload, bool $fin = true): string
    {
        $length = strlen($payload);
        $head = chr(($fin ? 0x80 : 0) | $opcode);

        return $head . match (true) {
            $length < 126 => chr($length),
            $length < 65536 => chr(126) . pack('n', $length),
            default => chr(127) . pack('J', $length),
        } . $payload;
    }

    private function emit(string $text): void
    {
        if (empty($this->scenario['chatty']) || strlen($text) < 6) {
            fwrite($this->client, $this->encode(1, $text));

            return;
        }
        $parts = str_split($text, (int) ceil(strlen($text) / 3));
        $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            fwrite($this->client, $this->encode($index === 0 ? 1 : 0, $part, $index === $last));
            if ($index === 0) {
                fwrite($this->client, $this->encode(9, 'keepalive'));
            }
        }
    }

    private function event(string $method, array $params, ?string $session = null): void
    {
        $this->emit(json_encode(['method' => $method, 'params' => (object) $params] + ($session === null ? [] : ['sessionId' => $session]), JSON_THROW_ON_ERROR));
    }

    private function reply(array $message, array $result): void
    {
        $this->emit(json_encode(['id' => $message['id'], 'result' => (object) $result] + (isset($message['sessionId']) ? ['sessionId' => $message['sessionId']] : []), JSON_THROW_ON_ERROR));
    }

    private function fail(array $message, string $text): void
    {
        $this->emit(json_encode(['id' => $message['id'], 'error' => ['code' => -32601, 'message' => $text]], JSON_THROW_ON_ERROR));
    }

    private function record(string $file, array $entry): void
    {
        file_put_contents($this->control . '/' . $file, json_encode($entry, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }

    private function newTarget(?string $opener): array
    {
        $number = ++$this->targetCount;
        $target = ['id' => 'TARGET' . $number, 'session' => 'SESSION' . $number, 'url' => 'about:blank', 'title' => '', 'opener' => $opener];
        $this->targets[$target['id']] = $target;
        $this->sessionTargets[$target['session']] = $target['id'];

        return $target;
    }

    private function attach(array $target, bool $waiting): void
    {
        $info = ['targetId' => $target['id'], 'type' => 'page', 'title' => '', 'url' => $target['url'], 'attached' => true] + ($target['opener'] === null ? [] : ['openerId' => $target['opener']]);
        $this->event('Target.attachedToTarget', ['sessionId' => $target['session'], 'targetInfo' => $info, 'waitingForDebugger' => $waiting]);
    }

    private function page(string $url): array
    {
        return $this->scenario['pages'][$url] ?? ['title' => '', 'text' => '', 'status' => 404];
    }

    private function paused(string $session, string $url, string $type, ?string $redirectedFrom = null): string
    {
        $requestId = 'REQ' . ++$this->counter;
        $this->event('Fetch.requestPaused', [
            'requestId' => $requestId,
            'request' => ['url' => $url, 'method' => 'GET'],
            'frameId' => $this->sessionTargets[$session] ?? 'UNKNOWN',
            'resourceType' => $type,
        ] + ($redirectedFrom === null ? [] : ['redirectedRequestId' => $redirectedFrom]), $session);
        while (($message = $this->next()) !== null) {
            $this->record('commands.jsonl', $message);
            if (($message['params']['requestId'] ?? null) === $requestId && in_array($message['method'] ?? '', ['Fetch.continueRequest', 'Fetch.failRequest'], true)) {
                $continued = $message['method'] === 'Fetch.continueRequest';
                if ($continued) {
                    $this->record('network.jsonl', ['url' => $url, 'type' => $type]);
                }

                return $requestId . ($continued ? ':continue' : ':fail');
            }
            $this->handle($message);
        }
        exit(0);
    }

    private function dispatch(array $message): void
    {
        $this->record('commands.jsonl', $message);
        $this->handle($message);
    }

    private function handle(array $message): void
    {
        $method = (string) ($message['method'] ?? '');
        $params = (array) ($message['params'] ?? []);
        $session = $message['sessionId'] ?? null;
        $crash = $this->scenario['crashOn'] ?? null;
        if ($crash === $method && !is_file($this->control . '/crashed')) {
            touch($this->control . '/crashed');
            exit(1);
        }
        switch ($method) {
            case 'Target.setAutoAttach':
                $this->reply($message, []);
                if ($session === null && empty($this->scenario['noInitialPage'])) {
                    $this->attach($this->newTarget(null), false);
                }

                return;
            case 'Target.createTarget':
                $target = $this->newTarget(null);
                $after = ($this->scenario['attachOrder'] ?? 'before') === 'after';
                if (!$after) {
                    $this->attach($target, true);
                }
                $this->reply($message, ['targetId' => $target['id']]);
                if ($after) {
                    $this->attach($target, true);
                }

                return;
            case 'Target.closeTarget':
                $id = (string) ($params['targetId'] ?? '');
                if (!isset($this->targets[$id])) {
                    $this->fail($message, 'No target with given id found');

                    return;
                }
                $closed = $this->targets[$id];
                unset($this->targets[$id], $this->sessionTargets[$closed['session']]);
                $this->reply($message, ['success' => true]);
                $this->event('Target.detachedFromTarget', ['sessionId' => $closed['session'], 'targetId' => $id]);

                return;
            case 'Runtime.runIfWaitingForDebugger':
                $this->reply($message, []);
                if (isset($this->popupUrls[$session])) {
                    $this->paused($session, $this->popupUrls[$session], 'Document');
                }

                return;
            case 'Page.navigate':
                $this->navigate($message, (string) $session, (string) ($params['url'] ?? ''));

                return;
            case 'Runtime.evaluate':
                $this->reply($message, ['result' => ['type' => 'object', 'value' => $this->evaluate((string) $session, (string) ($params['expression'] ?? ''))]]);

                return;
            case 'Input.dispatchMouseEvent':
                $this->reply($message, []);
                if (($params['type'] ?? '') === 'mouseReleased') {
                    $this->click((string) $session, (float) $params['x'], (float) $params['y']);
                }

                return;
            case 'Input.insertText':
                $this->record('typed.jsonl', ['text' => $params['text'] ?? '']);
                $this->reply($message, []);

                return;
            case 'Page.captureScreenshot':
                $key = ($params['format'] ?? 'png') === 'jpeg' ? 'jpeg' : 'png';
                $this->reply($message, ['data' => $this->scenario[$key] ?? '']);

                return;
            case 'Page.handleJavaScriptDialog':
                $this->record('dialogs.jsonl', ['accept' => $params['accept'] ?? null]);
                $this->reply($message, []);

                return;
            case 'Fetch.continueRequest':
            case 'Fetch.failRequest':
                return;
            case 'Browser.setDownloadBehavior':
            case 'Fetch.enable':
            case 'Page.enable':
            case 'Runtime.enable':
            case 'Log.enable':
            case 'Page.setLifecycleEventsEnabled':
                $this->reply($message, []);

                return;
        }
        $this->fail($message, "'{$method}' wasn't found");
    }

    private function navigate(array $message, string $session, string $url): void
    {
        $targetId = $this->sessionTargets[$session] ?? '';
        $answer = $this->paused($session, $url, 'Document');
        if (str_ends_with($answer, ':fail')) {
            $this->reply($message, ['frameId' => $targetId, 'loaderId' => 'LOADER' . ++$this->counter, 'errorText' => 'net::ERR_BLOCKED_BY_CLIENT']);

            return;
        }
        $final = $url;
        while (isset($this->page($final)['redirect'])) {
            $next = (string) $this->page($final)['redirect'];
            $answer = $this->paused($session, $next, 'Document', explode(':', $answer)[0]);
            if (str_ends_with($answer, ':fail')) {
                $this->reply($message, ['frameId' => $targetId, 'loaderId' => 'LOADER' . ++$this->counter, 'errorText' => 'net::ERR_BLOCKED_BY_CLIENT']);

                return;
            }
            $final = $next;
        }
        $page = $this->page($final);
        if (isset($page['refuse'])) {
            $this->reply($message, ['frameId' => $targetId, 'loaderId' => 'LOADER' . ++$this->counter, 'errorText' => $page['refuse']]);

            return;
        }
        $loader = 'LOADER' . ++$this->counter;
        $this->targets[$targetId]['url'] = $final;
        $this->targets[$targetId]['title'] = $page['title'] ?? '';
        $this->reply($message, ['frameId' => $targetId, 'loaderId' => $loader]);
        if (!empty($page['neverLoads'])) {
            $this->event('Page.lifecycleEvent', ['frameId' => $targetId, 'loaderId' => $loader, 'name' => 'init', 'timestamp' => 1.0], $session);

            return;
        }
        $this->event('Page.lifecycleEvent', ['frameId' => $targetId, 'loaderId' => $loader, 'name' => 'init', 'timestamp' => 1.0], $session);
        $this->subresources($session, $page);
        $this->event('Page.lifecycleEvent', ['frameId' => $targetId, 'loaderId' => $loader, 'name' => 'load', 'timestamp' => 2.0], $session);
        $this->event('Page.lifecycleEvent', ['frameId' => $targetId, 'loaderId' => $loader, 'name' => 'networkIdle', 'timestamp' => 3.0], $session);
    }

    private function subresources(string $session, array $page): void
    {
        foreach ($page['requests'] ?? [] as $request) {
            $answer = $this->paused($session, $request, 'XHR');
            if (str_ends_with($answer, ':fail')) {
                $this->event('Log.entryAdded', ['entry' => ['source' => 'network', 'level' => 'error', 'text' => 'Failed to load resource: net::ERR_BLOCKED_BY_CLIENT', 'url' => $request, 'timestamp' => 1.0]], $session);
            }
        }
        foreach ($page['console'] ?? [] as $entry) {
            $this->event('Runtime.consoleAPICalled', [
                'type' => $entry['type'],
                'args' => [['type' => 'string', 'value' => $entry['text']]],
                'executionContextId' => 1,
                'timestamp' => 1.0,
                'stackTrace' => ['callFrames' => [['functionName' => '', 'scriptId' => '1', 'url' => $entry['url'] ?? 'http://127.0.0.1/app.js', 'lineNumber' => $entry['line'] ?? 0, 'columnNumber' => 0]]],
            ], $session);
        }
        foreach ($page['exceptions'] ?? [] as $text) {
            $this->event('Runtime.exceptionThrown', ['timestamp' => 1.0, 'exceptionDetails' => [
                'exceptionId' => 1,
                'text' => 'Uncaught',
                'lineNumber' => 6,
                'columnNumber' => 1,
                'url' => 'http://127.0.0.1/app.js',
                'exception' => ['type' => 'object', 'subtype' => 'error', 'description' => "Error: {$text}\n    at http://127.0.0.1/app.js:7:2"],
            ]], $session);
        }
        foreach ($page['logs'] ?? [] as $entry) {
            $this->event('Log.entryAdded', ['entry' => $entry + ['source' => 'other', 'timestamp' => 1.0]], $session);
        }
        if (isset($page['dialog'])) {
            $this->event('Page.javascriptDialogOpening', ['url' => 'x', 'frameId' => 'x', 'message' => $page['dialog']['message'], 'type' => $page['dialog']['type'], 'hasBrowserHandler' => true], $session);
        }
    }

    private function evaluate(string $session, string $expression): array
    {
        $target = $this->targets[$this->sessionTargets[$session] ?? ''] ?? ['url' => '', 'title' => ''];
        $page = $this->page($target['url']);
        if ($expression === PageScripts::DESCRIBE) {
            return ['url' => $target['url'], 'title' => $page['title'] ?? '', 'status' => $page['status'] ?? 200, 'text' => $page['text'] ?? ''];
        }
        foreach (['POINT' => PageScripts::POINT, 'FOCUS' => PageScripts::FOCUS] as $kind => $source) {
            if (str_starts_with($expression, $source . '(')) {
                $selector = (string) json_decode(substr($expression, strlen($source) + 1, -1), true, 512, JSON_THROW_ON_ERROR);
                $element = $page['elements'][$selector] ?? null;
                if ($element === null) {
                    return ['error' => 'no element matches ' . $selector];
                }
                if (isset($element['error'])) {
                    return ['error' => $element['error']];
                }

                return $kind === 'POINT' ? ['x' => $element['x'], 'y' => $element['y']] : ['ok' => true];
            }
        }

        return [];
    }

    private function click(string $session, float $x, float $y): void
    {
        $targetId = $this->sessionTargets[$session] ?? '';
        $page = $this->page($this->targets[$targetId]['url'] ?? '');
        foreach ($page['elements'] ?? [] as $element) {
            if ((float) ($element['x'] ?? -1) !== $x || (float) ($element['y'] ?? -1) !== $y || !isset($element['effect'])) {
                continue;
            }
            $effect = $element['effect'];
            if (isset($effect['popup'])) {
                $popup = $this->newTarget($targetId);
                $this->popupUrls[$popup['session']] = $effect['popup'];
                $this->attach($popup, true);
            }
            if (isset($effect['navigate'])) {
                $fake = ['id' => 0, 'sessionId' => $session];
                $this->navigate($fake, $session, $effect['navigate']);
            }
        }
    }
}

$control = (string) getenv('PHYCODE_FAKE_BROWSER');
$scenario = json_decode((string) file_get_contents($control . '/scenario.json'), true, 512, JSON_THROW_ON_ERROR);
$profile = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--user-data-dir=')) {
        $profile = substr($argument, strlen('--user-data-dir='));
    }
}
file_put_contents($control . '/argv.jsonl', json_encode(['argv' => array_slice($argv, 1), 'pid' => getmypid(), 'ppid' => posix_getppid(), 'profile_mode' => $profile === '' ? null : decoct(fileperms($profile) & 0777), 'xdg' => getenv('XDG_CONFIG_HOME')], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if (isset($scenario['exitWith'])) {
    fwrite(STDERR, $scenario['exitWith'] . "\n");
    exit(1);
}
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
$address = (string) stream_socket_get_name($server, false);
$port = (int) substr($address, (int) strrpos($address, ':') + 1);
$path = '/devtools/browser/' . bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(2)) . '-4' . bin2hex(random_bytes(2)) . '-a' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(6));
if (empty($scenario['noPortFile'])) {
    file_put_contents($profile . '/DevToolsActivePort', $port . "\n" . $path);
}
fwrite(STDERR, "DevTools listening on ws://127.0.0.1:{$port}{$path}\n");
(new FakeChrome($control, $scenario, $port, $path))->serve($server);
