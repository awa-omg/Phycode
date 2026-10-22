<?php

declare(strict_types=1);

namespace Phycode\Lsp;

use Phycode\Exec\Locale;
use Phycode\Exec\ProcessRunner;
use Phycode\Support\Cancellation;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Text;

final class LspClient
{
    private const MAX_MESSAGE_BYTES = 16777216;
    private const MAX_HEADER_BYTES = 8192;
    private const CHUNK_BYTES = 65536;
    private const EXIT_GRACE_SECONDS = 1.0;
    private const TERMINATE_SECONDS = 1.0;
    private const DRAIN_SECONDS = 0.3;
    private const STDERR_BYTES = 8192;
    private const RETRY_SECONDS = 0.2;
    private const METHOD_NOT_FOUND = -32601;
    private const CONTENT_MODIFIED = -32801;
    private const SERVER_CANCELLED = -32802;
    private const SYNC_NONE = 0;
    private const PULL_METHOD = 'textDocument/diagnostic';

    private $process = null;
    private $stdin = null;
    private $stdout = null;
    private $stderr = null;

    private string $outgoing = '';
    private string $incoming = '';
    private string $stderrTail = '';
    private int $lastId = 0;
    private ?int $awaiting = null;
    private ?array $answer = null;
    private ?array $exitStatus = null;
    private bool $initialized = false;
    private array $serverCapabilities = [];
    private array $documents = [];
    private array $published = [];
    private array $pullRegistrations = [];
    private bool $pullRefused = false;
    private bool $warm = false;
    private array $progress = [];
    private ?Cancellation $cancel = null;

    public function __construct(
        private readonly string $name,
        private readonly array $argv,
        private readonly string $root,
        private readonly array $initializationOptions = [],
        private readonly LspTimeouts $timeouts = new LspTimeouts(),
        private readonly string $clientVersion = '0.1.0',
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function bind(?Cancellation $cancel): self
    {
        $this->cancel = $cancel;

        return $this;
    }

    public function alive(): bool
    {
        return $this->initialized && $this->running();
    }

    public function start(): void
    {
        $this->open();
        try {
            $result = $this->request('initialize', $this->initializeParams(), $this->timeouts->initializeSeconds);
            $this->serverCapabilities = is_array($result) && is_array($result['capabilities'] ?? null) ? $result['capabilities'] : [];
            $encoding = $this->serverCapabilities['positionEncoding'] ?? 'utf-16';
            if ($encoding !== 'utf-16') {
                throw new PhycodeException("language server {$this->name} chose the position encoding {$encoding}, but phycode offers only utf-16; update the server or its settings so that it accepts utf-16");
            }
            $this->notify('initialized', new \stdClass());
            $this->initialized = true;
        } catch (PhycodeException $error) {
            $this->stop();

            throw $error;
        }
    }

    public function request(string $method, array|object|null $params = null, ?float $seconds = null): mixed
    {
        $seconds ??= $this->timeouts->requestSeconds;
        $id = ++$this->lastId;
        $this->awaiting = $id;
        $this->answer = null;
        $this->queue(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method] + ($params === null ? [] : ['params' => $params]));
        try {
            $answered = $this->waitFor(fn (): bool => $this->answer !== null, $seconds, $method);
        } finally {
            $this->awaiting = null;
        }
        if (!$answered) {
            $this->notify('$/cancelRequest', ['id' => $id]);

            throw new PhycodeException("language server {$this->name} did not answer {$method} within {$seconds} s; it may still be indexing the project, so try again in a moment");
        }
        $response = $this->answer;
        $this->answer = null;
        if (isset($response['error'])) {
            $error = is_array($response['error']) ? $response['error'] : [];
            $message = Text::oneLine(is_string($error['message'] ?? null) ? $error['message'] : 'no message', 300);
            $code = is_int($error['code'] ?? null) ? $error['code'] : 0;

            throw new LspRpcException("language server {$this->name} failed {$method}: {$message} (code {$code})", $code);
        }

        return $response['result'] ?? null;
    }

    public function notify(string $method, array|object|null $params = null): void
    {
        $this->queue(['jsonrpc' => '2.0', 'method' => $method] + ($params === null ? [] : ['params' => $params]));
        $deadline = microtime(true) + $this->timeouts->requestSeconds;
        while ($this->outgoing !== '' && $this->stdin !== null && ($left = $deadline - microtime(true)) > 0.0) {
            $this->pump(min($left, 0.1));
        }
    }

    public function syncDocument(string $path, string $text): void
    {
        $hash = md5($text);
        $known = $this->documents[$path] ?? null;
        if ($known !== null && $known['hash'] === $hash) {
            return;
        }
        $version = ($known['version'] ?? 0) + 1;
        $this->documents[$path] = ['version' => $version, 'hash' => $hash];
        unset($this->published[$path]);
        $item = ['uri' => LspFiles::uri($path), 'languageId' => LspFiles::languageId($path), 'version' => $version, 'text' => $text];
        if ($known === null) {
            $this->notify('textDocument/didOpen', ['textDocument' => $item]);
        } elseif ($this->changeKind() === self::SYNC_NONE) {
            $this->notify('textDocument/didClose', ['textDocument' => ['uri' => $item['uri']]]);
            $this->notify('textDocument/didOpen', ['textDocument' => $item]);
        } else {
            $this->notify('textDocument/didChange', ['textDocument' => ['uri' => $item['uri'], 'version' => $version], 'contentChanges' => [['text' => $text]]]);
        }
    }

    public function refreshDocuments(): void
    {
        foreach (array_keys($this->documents) as $path) {
            $text = is_file($path) ? @file_get_contents($path) : false;
            if ($text === false) {
                $this->notify('textDocument/didClose', ['textDocument' => ['uri' => LspFiles::uri($path)]]);
                unset($this->documents[$path], $this->published[$path]);
            } else {
                $this->syncDocument($path, $text);
            }
        }
    }

    public function warmUp(string $path): void
    {
        if ($this->warm || $this->timeouts->warmupSeconds <= 0.0) {
            return;
        }
        $this->warm = true;
        $deadline = microtime(true) + $this->timeouts->warmupSeconds;
        try {
            $this->diagnostics($path, $this->timeouts->warmupSeconds);
        } catch (LspRpcException) {
        }
        $this->waitFor(fn (): bool => $this->progress === [], max(0.0, $deadline - microtime(true)), 'the project to load');
    }

    private function listen(): void
    {
        $this->pump(0.0);
        while (($message = $this->nextMessage()) !== null) {
            $this->handle($message);
        }
    }

    public function diagnostics(string $path, ?float $seconds = null): array
    {
        $this->listen();
        $deadline = microtime(true) + ($seconds ?? $this->timeouts->diagnosticsSeconds);
        $this->awaitPush($path, $deadline);
        if ($this->pullAvailable()) {
            $items = $this->pullDiagnostics($path, $deadline);
            if ($items !== null) {
                return ['items' => $items, 'via' => 'pull'];
            }
            $this->awaitPush($path, $deadline);
        }
        $entry = $this->published[$path] ?? null;

        return $entry === null ? ['items' => [], 'via' => null] : ['items' => $entry['items'], 'via' => 'push'];
    }

    public function close(): void
    {
        if ($this->process === null) {
            return;
        }
        $this->cancel = null;
        if ($this->initialized && $this->running()) {
            try {
                $this->request('shutdown', null, $this->timeouts->shutdownSeconds);
                $this->notify('exit');
            } catch (PhycodeException) {
            }
        }
        $this->stop();
    }

    private function initializeParams(): array
    {
        $rootUri = LspFiles::uri($this->root);
        $empty = new \stdClass();
        $params = [
            'processId' => getmypid(),
            'clientInfo' => ['name' => 'phycode', 'version' => $this->clientVersion],
            'rootUri' => $rootUri,
            'workspaceFolders' => [['uri' => $rootUri, 'name' => basename($this->root)]],
            'capabilities' => [
                'general' => ['positionEncodings' => ['utf-16']],
                'window' => ['workDoneProgress' => true],
                'workspace' => ['configuration' => true, 'workspaceFolders' => true, 'symbol' => $empty],
                'textDocument' => [
                    'synchronization' => ['dynamicRegistration' => false],
                    'definition' => $empty,
                    'references' => $empty,
                    'hover' => ['contentFormat' => ['plaintext', 'markdown']],
                    'documentSymbol' => ['hierarchicalDocumentSymbolSupport' => true],
                    'publishDiagnostics' => ['relatedInformation' => false],
                    'diagnostic' => ['dynamicRegistration' => true, 'relatedDocumentSupport' => false],
                ],
            ],
        ];
        if ($this->initializationOptions !== []) {
            $params['initializationOptions'] = $this->initializationOptions;
        }

        return $params;
    }

    private function open(): void
    {
        $program = $this->argv[0];
        $absolute = str_starts_with($program, '/') ? $program : $this->root . '/' . $program;
        $found = str_contains($program, '/') ? is_file($absolute) && is_executable($absolute) : ProcessRunner::which($program) !== null;
        if (!$found) {
            throw new PhycodeException("language server {$this->name} is not installed: '{$program}' was not found. Install it, or fix lsp.{$this->name}.command in the global config");
        }
        $pipes = [];
        $process = @proc_open($this->argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, self::environment());
        if (!is_resource($process)) {
            throw new PhycodeException("cannot start language server {$this->name} ({$program}); check that lsp.{$this->name}.command can run in {$this->root}");
        }
        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        stream_set_read_buffer($this->stdout, 0);
        stream_set_read_buffer($this->stderr, 0);
    }

    private static function environment(): array
    {
        $environment = [
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => (string) (getenv('HOME') ?: '/root'),
            ...Locale::variables(),
            'TERM' => 'dumb',
            'NO_COLOR' => '1',
        ];
        $temporary = getenv('TMPDIR');
        if (is_string($temporary) && $temporary !== '') {
            $environment['TMPDIR'] = $temporary;
        }

        return $environment;
    }

    private function changeKind(): int
    {
        $sync = $this->serverCapabilities['textDocumentSync'] ?? null;
        $kind = is_array($sync) ? ($sync['change'] ?? self::SYNC_NONE) : $sync;

        return is_int($kind) ? $kind : self::SYNC_NONE;
    }

    private function pullAvailable(): bool
    {
        return !$this->pullRefused && (($this->serverCapabilities['diagnosticProvider'] ?? false) !== false || $this->pullRegistrations !== []);
    }

    private function awaitPush(string $path, float $deadline): void
    {
        $quiet = $this->timeouts->diagnosticsQuietSeconds;
        $this->waitFor(function () use ($path, $quiet): bool {
            $entry = $this->published[$path] ?? null;

            return $this->pullAvailable() || ($entry !== null && microtime(true) - $entry['at'] >= $quiet);
        }, max(0.0, $deadline - microtime(true)), 'diagnostics');
    }

    private function pullDiagnostics(string $path, float $deadline): ?array
    {
        while (true) {
            try {
                $report = $this->request(self::PULL_METHOD, ['textDocument' => ['uri' => LspFiles::uri($path)]], max(1.0, $deadline - microtime(true)));

                return is_array($report) && is_array($report['items'] ?? null) ? $report['items'] : [];
            } catch (LspRpcException $error) {
                if ($error->getCode() === self::METHOD_NOT_FOUND) {
                    $this->pullRefused = true;

                    return null;
                }
                if (!in_array($error->getCode(), [self::SERVER_CANCELLED, self::CONTENT_MODIFIED], true) || microtime(true) >= $deadline) {
                    throw $error;
                }
                $this->waitFor(static fn (): bool => false, self::RETRY_SECONDS, self::PULL_METHOD);
            }
        }
    }

    private function waitFor(\Closure $ready, float $seconds, string $during): bool
    {
        $deadline = microtime(true) + $seconds;
        $exitedAt = null;
        while (true) {
            while (($message = $this->nextMessage()) !== null) {
                $this->handle($message);
            }
            if ($ready()) {
                return true;
            }
            if ($this->cancel?->isCancelled()) {
                throw new PhycodeException("the call to language server {$this->name} was cancelled while waiting for {$during}");
            }
            if (!$this->running()) {
                $exitedAt ??= microtime(true);
            }
            if ($this->stdout === null || ($exitedAt !== null && microtime(true) - $exitedAt > self::EXIT_GRACE_SECONDS)) {
                throw $this->crashed($during);
            }
            $left = $deadline - microtime(true);
            if ($left <= 0.0) {
                return false;
            }
            $this->pump(min($left, 0.1));
        }
    }

    private function nextMessage(): ?array
    {
        $headerEnd = strpos($this->incoming, "\r\n\r\n");
        if ($headerEnd === false) {
            if (strlen($this->incoming) > self::MAX_HEADER_BYTES) {
                throw $this->broken('sent data that is not framed with Content-Length headers; it is probably not speaking the Language Server Protocol on stdout');
            }

            return null;
        }
        if (preg_match('/^Content-Length:[ \t]*(\d+)[ \t]*\r?$/mi', substr($this->incoming, 0, $headerEnd), $match) !== 1) {
            throw $this->broken('sent a message without a Content-Length header; it is probably printing something other than the Language Server Protocol on stdout');
        }
        $length = (int) $match[1];
        if ($length > self::MAX_MESSAGE_BYTES) {
            throw $this->broken('sent a message longer than ' . intdiv(self::MAX_MESSAGE_BYTES, 1048576) . ' MB');
        }
        $end = $headerEnd + 4 + $length;
        if (strlen($this->incoming) < $end) {
            return null;
        }
        $body = substr($this->incoming, $headerEnd + 4, $length);
        $this->incoming = substr($this->incoming, $end);
        $message = Json::object($body);
        if ($message === null) {
            throw $this->broken('sent a message that is not a JSON object');
        }

        return $message;
    }

    private function handle(array $message): void
    {
        $method = $message['method'] ?? null;
        if (!is_string($method)) {
            if ($this->awaiting !== null && ($message['id'] ?? null) === $this->awaiting) {
                $this->answer = $message;
            }

            return;
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        if (array_key_exists('id', $message)) {
            $this->queue(['jsonrpc' => '2.0', 'id' => $message['id']] + $this->replyTo($method, $params));

            return;
        }
        if ($method === 'textDocument/publishDiagnostics') {
            $this->keepPublished($params);
        } elseif ($method === '$/progress' && is_scalar($params['token'] ?? null)) {
            $this->trackProgress((string) $params['token'], $params['value']['kind'] ?? null);
        }
    }

    private function replyTo(string $method, array $params): array
    {
        return match ($method) {
            'workspace/configuration' => ['result' => array_fill(0, is_array($params['items'] ?? null) ? count($params['items']) : 0, null)],
            'workspace/workspaceFolders' => ['result' => [['uri' => LspFiles::uri($this->root), 'name' => basename($this->root)]]],
            'client/registerCapability' => $this->register($params),
            'client/unregisterCapability' => $this->unregister($params),
            'workspace/applyEdit' => ['result' => ['applied' => false, 'failureReason' => 'phycode asks language servers questions only and never applies their edits']],
            'window/workDoneProgress/create' => $this->createProgress($params),
            'window/showMessageRequest', 'workspace/diagnostic/refresh', 'workspace/semanticTokens/refresh', 'workspace/codeLens/refresh', 'workspace/inlayHint/refresh', 'workspace/inlineValue/refresh' => ['result' => null],
            default => ['error' => ['code' => self::METHOD_NOT_FOUND, 'message' => 'Method not found']],
        };
    }

    private function createProgress(array $params): array
    {
        if (is_scalar($params['token'] ?? null)) {
            $this->progress[(string) $params['token']] = true;
        }

        return ['result' => null];
    }

    private function trackProgress(string $token, mixed $kind): void
    {
        if ($kind === 'end') {
            unset($this->progress[$token]);
        } elseif ($kind === 'begin') {
            $this->progress[$token] = true;
        }
    }

    private function register(array $params): array
    {
        foreach (is_array($params['registrations'] ?? null) ? $params['registrations'] : [] as $registration) {
            if (is_array($registration) && ($registration['method'] ?? null) === self::PULL_METHOD && is_string($registration['id'] ?? null)) {
                $this->pullRegistrations[$registration['id']] = true;
            }
        }

        return ['result' => null];
    }

    private function unregister(array $params): array
    {
        foreach (is_array($params['unregisterations'] ?? null) ? $params['unregisterations'] : [] as $unregistration) {
            if (is_array($unregistration) && is_string($unregistration['id'] ?? null)) {
                unset($this->pullRegistrations[$unregistration['id']]);
            }
        }

        return ['result' => null];
    }

    private function keepPublished(array $params): void
    {
        $path = is_string($params['uri'] ?? null) ? LspFiles::path($params['uri']) : null;
        if ($path === null || !isset($this->documents[$path]) || !is_array($params['diagnostics'] ?? null)) {
            return;
        }
        $version = $params['version'] ?? null;
        if (is_int($version) && $version < $this->documents[$path]['version']) {
            return;
        }
        $this->published[$path] = ['items' => $params['diagnostics'], 'at' => microtime(true)];
    }

    private function queue(array $message): void
    {
        $body = Json::encode($message);
        $this->outgoing .= 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
    }

    private function pump(float $seconds): void
    {
        $read = [];
        if ($this->stdout !== null) {
            $read[] = $this->stdout;
        }
        if ($this->stderr !== null) {
            $read[] = $this->stderr;
        }
        $write = $this->stdin !== null && $this->outgoing !== '' ? [$this->stdin] : [];
        if ($read === [] && $write === []) {
            usleep((int) ($seconds * 1_000_000));

            return;
        }

        $except = null;
        if (@stream_select($read, $write, $except, 0, (int) ($seconds * 1_000_000)) === false) {
            return;
        }
        foreach ($read as $stream) {
            $chunk = fread($stream, self::CHUNK_BYTES);
            $chunk = is_string($chunk) ? $chunk : '';
            $ended = feof($stream);
            if ($stream === $this->stdout) {
                $this->incoming .= $chunk;
                if ($ended) {
                    fclose($stream);
                    $this->stdout = null;
                }
            } else {
                $this->stderrTail = substr($this->stderrTail . $chunk, -self::STDERR_BYTES);
                if ($ended) {
                    fclose($stream);
                    $this->stderr = null;
                }
            }
        }

        if ($write !== [] && $this->stdin !== null) {
            $written = @fwrite($this->stdin, $this->outgoing);
            if ($written === false) {
                fclose($this->stdin);
                $this->stdin = null;
                $this->outgoing = '';
            } else {
                $this->outgoing = substr($this->outgoing, $written);
            }
        }
    }

    private function running(): bool
    {
        if ($this->process === null) {
            return false;
        }
        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $this->exitStatus ??= $status;
        }

        return $status['running'];
    }

    private function crashed(string $during): PhycodeException
    {
        $this->waitForExit(self::EXIT_GRACE_SECONDS);
        $this->drainStderr();
        $state = 'closed its output';
        if ($this->exitStatus !== null) {
            $state = $this->exitStatus['signaled'] ? "was killed by signal {$this->exitStatus['termsig']}" : "exited with code {$this->exitStatus['exitcode']}";
        }
        $excerpt = $this->excerpt();
        $this->stop();

        return new PhycodeException("language server {$this->name} {$state} while waiting for {$during}" . ($excerpt === '' ? '' : ": {$excerpt}") . '. It is started again on the next lsp call');
    }

    private function broken(string $what): PhycodeException
    {
        $this->stop();

        return new PhycodeException("language server {$this->name} {$what}. It is started again on the next lsp call");
    }

    private function excerpt(): string
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $this->stderrTail)),
            static fn (string $line): bool => $line !== '',
        ));

        return Text::oneLine(Redaction::text(implode(' | ', array_slice($lines, -3))), 300);
    }

    private function drainStderr(): void
    {
        $deadline = microtime(true) + self::DRAIN_SECONDS;
        while ($this->stderr !== null && microtime(true) < $deadline) {
            $this->pump(0.05);
        }
    }

    private function waitForExit(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while ($this->running() && ($left = $deadline - microtime(true)) > 0.0) {
            $this->pump(min($left, 0.05));
        }
    }

    private function stop(): void
    {
        $this->initialized = false;
        if ($this->process === null) {
            return;
        }
        if ($this->stdin !== null) {
            fclose($this->stdin);
            $this->stdin = null;
        }
        $this->outgoing = '';
        $this->waitForExit(self::EXIT_GRACE_SECONDS);
        if ($this->running()) {
            proc_terminate($this->process);
            $this->waitForExit(self::TERMINATE_SECONDS);
        }
        if ($this->running()) {
            proc_terminate($this->process, 9);
        }
        $this->drainStderr();
        foreach ([$this->stdout, $this->stderr] as $pipe) {
            if ($pipe !== null) {
                fclose($pipe);
            }
        }
        $this->stdout = null;
        $this->stderr = null;
        proc_close($this->process);
        $this->process = null;
    }
}
