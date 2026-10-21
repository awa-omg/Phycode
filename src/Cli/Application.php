<?php

declare(strict_types=1);

namespace Phycode\Cli;

use Phycode\Acp\AcpServer;
use Phycode\Acp\AcpUi;
use Phycode\Agent\Conversation;
use Phycode\Agent\Outcome;
use Phycode\Agent\OutcomeStatus;
use Phycode\Agent\RunSetup;
use Phycode\Btw\BtwWorker;
use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Deploy\DeployCli;
use Phycode\Config\Profile;
use Phycode\Exec\ContainerRunner;
use Phycode\Exec\DomainAllowlist;
use Phycode\Exec\FilteringProxy;
use Phycode\Exec\ProcessRunner;
use Phycode\Gentle\GentleAi;
use Phycode\Gentle\ShellIntegration;
use Phycode\Llm\Http;
use Phycode\Llm\Image;
use Phycode\Llm\Llama\LlamaDiscovery;
use Phycode\Llm\Llama\LlamaDoctor;
use Phycode\Llm\LlmException;
use Phycode\Mcp\McpCli;
use Phycode\Rpc\JsonLines;
use Phycode\Rpc\RpcServer;
use Phycode\Rpc\RpcUi;
use Phycode\Security\PhpAdvisory;
use Phycode\Security\Workspace;
use Phycode\Serve\ServeDaemon;
use Phycode\Ssh\SshCli;
use Phycode\Session\SessionStore;
use Phycode\Session\Transcript;
use Phycode\Support\Cancellation;
use Phycode\Support\Entry;
use Phycode\Support\Json;
use Phycode\Support\JsonSchema;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Vault;
use Phycode\Tui\Terminal;
use Phycode\Tui\TuiApp;
use Phycode\Tui\TuiUi;
use Phycode\Ui\ConsoleUi;
use Phycode\Ui\StreamJsonUi;
use Phycode\Ui\Ui;

final class Application
{
    public const VERSION = '0.1.0';

    public static function run(array $argv): int
    {
        try {
            return (new self())->dispatch(self::parse(array_slice($argv, 1)));
        } catch (PhycodeException $error) {
            fwrite(STDERR, 'phycode: ' . Redaction::text($error->getMessage()) . PHP_EOL);

            return 1;
        } catch (\Throwable $error) {
            fwrite(STDERR, 'phycode: unexpected error: ' . Redaction::text($error->getMessage()) . PHP_EOL);

            return 1;
        }
    }

    private static function parse(array $args): array
    {
        $options = [
            'command' => null,
            'positional' => [],
            'cwd' => null,
            'profile' => null,
            'agent' => null,
            'sandbox' => null,
            'add_dir' => [],
            'tui' => false,
            'yolo' => false,
            'format' => 'text',
            'model' => null,
            'max_turns' => null,
            'schema' => null,
            'continue' => false,
            'resume' => null,
            'images' => [],
            'help' => false,
        ];
        $formatGiven = false;
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if ($arg === '--') {
                $options['positional'] = [...$options['positional'], ...array_slice($args, $i + 1)];
                break;
            }
            if ($arg === '-h' || $arg === '--help') {
                $options['help'] = true;
                continue;
            }
            if ($arg === '-t' || $arg === '--tui') {
                $options['tui'] = true;
                continue;
            }
            if ($arg === '-c' || $arg === '--continue') {
                $options['continue'] = true;
                continue;
            }
            if ($arg === '-r') {
                $options['resume'] = $args[++$i] ?? throw new PhycodeException('-r needs a session id');
                continue;
            }
            if ($arg === '-p' || $arg === '--print') {
                if ($options['command'] !== null && $options['command'] !== 'run') {
                    throw new PhycodeException('-p is an alias of run: write phycode -p "task", or phycode run "task"');
                }
                $options['command'] = 'run';
                continue;
            }
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', substr($arg, 2), 2);
                $name = $parts[0];
                switch ($name) {
                    case 'cwd':
                    case 'profile':
                    case 'agent':
                    case 'sandbox':
                    case 'model':
                    case 'resume':
                    case 'output-schema':
                    case 'max-turns':
                    case 'output-format':
                        $value = $parts[1] ?? $args[++$i] ?? throw new PhycodeException("--{$name} needs a value");
                        if ($name === 'output-format' && $formatGiven && $value !== $options['format']) {
                            throw new PhycodeException('--json and --output-format say different formats; use one of them');
                        }
                        $key = str_replace('-', '_', $name);
                        $options[match ($key) {
                            'output_schema' => 'schema',
                            'output_format' => 'format',
                            default => $key,
                        }] = $value;
                        $formatGiven = $formatGiven || $name === 'output-format';
                        break;
                    case 'add-dir':
                        $folder = $parts[1] ?? $args[++$i] ?? '';
                        if ($folder === '') {
                            throw new PhycodeException('--add-dir needs a value');
                        }
                        $options['add_dir'][] = $folder;
                        break;
                    case 'yolo':
                        $options['yolo'] = true;
                        break;
                    case 'continue':
                        $options['continue'] = true;
                        break;
                    case 'image':
                        $picture = $parts[1] ?? $args[++$i] ?? '';
                        if ($picture === '') {
                            throw new PhycodeException('--image needs a file');
                        }
                        $options['images'][] = $picture;
                        break;
                    case 'json':
                        if ($formatGiven && $options['format'] !== 'json') {
                            throw new PhycodeException('--json and --output-format say different formats; use one of them');
                        }
                        $options['format'] = 'json';
                        $formatGiven = true;
                        break;
                    default:
                        if (in_array($options['command'], ['ssh', 'deploy', 'proxy-run', 'serve'], true)) {
                            $options['positional'][] = $arg;
                            break;
                        }
                        throw new PhycodeException("unknown option --{$name}");
                }
                continue;
            }
            if ($options['command'] === null) {
                $options['command'] = $arg;
            } else {
                $options['positional'][] = $arg;
            }
        }
        if ($options['sandbox'] !== null && !in_array($options['sandbox'], ['host', 'podman', 'docker', 'bwrap'], true)) {
            throw new PhycodeException('--sandbox must be host, podman, docker or bwrap');
        }

        return self::checkRunOptions($options);
    }

    private static function checkRunOptions(array $options): array
    {
        if (!in_array($options['format'], ['text', 'json', 'stream-json'], true)) {
            throw new PhycodeException('--output-format must be text, json or stream-json');
        }
        if ($options['max_turns'] !== null) {
            $turns = (string) $options['max_turns'];
            if (preg_match('/^[1-9][0-9]{0,3}$/', $turns) !== 1) {
                throw new PhycodeException('--max-turns must be a whole number from 1 to 9999');
            }
            $options['max_turns'] = (int) $turns;
        }
        if ($options['model'] !== null && trim((string) $options['model']) === '') {
            throw new PhycodeException('--model needs a model name');
        }
        if ($options['continue'] && $options['resume'] !== null) {
            throw new PhycodeException('--continue and --resume cannot be used together: one picks the last session, the other a session by its id');
        }
        if ($options['resume'] !== null && !SessionStore::validId((string) $options['resume'])) {
            throw new PhycodeException('--resume needs a session id, as /sessions lists them');
        }
        if ($options['command'] !== 'run') {
            $only = ['format' => ['--output-format', 'text'], 'model' => ['--model', null], 'max_turns' => ['--max-turns', null], 'schema' => ['--output-schema', null], 'continue' => ['--continue', false], 'resume' => ['--resume', null], 'images' => ['--image', []]];
            foreach ($only as $key => [$flag, $default]) {
                if ($options[$key] !== $default && !($key === 'format' && $options['format'] === 'json')) {
                    throw new PhycodeException("{$flag} is an option of run: phycode run {$flag} ... 'task'");
                }
            }
        }

        return $options;
    }

    private function dispatch(array $options): int
    {
        if ($options['help'] || $options['command'] === 'help') {
            self::usage(STDOUT);

            return 0;
        }
        if ($options['tui']) {
            return $this->tui($options);
        }
        if ($options['command'] === null) {
            self::usage(STDERR);

            return 1;
        }

        return match ($options['command']) {
            'run' => $this->runTask($options),
            'chat' => $this->chat($options),
            'rpc' => $this->rpc($options),
            'acp' => $this->acp($options),
            'serve' => $this->serve($options),
            'agents' => $this->listAgents($options),
            'doctor' => $this->doctor($options),
            'keys' => $this->keys($options),
            'ssh' => $this->ssh($options),
            'mcp' => $this->mcpCommand($options),
            'deploy' => $this->deploy($options),
            'shell' => ShellIntegration::cli($options['positional']),
            'btw-run' => $this->btwRun(),
            'proxy-run' => $this->proxyRun($options),
            'version' => $this->version(),
            default => throw new PhycodeException("unknown command '{$options['command']}'. Run: phycode help"),
        };
    }

    private const SCHEMA_RETRIES = 2;

    private function runTask(array $options): int
    {
        $prompt = trim(implode(' ', $options['positional']));
        if ($prompt === '' && !posix_isatty(STDIN)) {
            $prompt = trim((string) stream_get_contents(STDIN));
        }
        if ($prompt === '') {
            throw new PhycodeException('give the task in quotes: phycode run "describe the change"');
        }
        $schema = $options['schema'] === null ? null : self::loadSchema((string) $options['schema']);
        $format = (string) $options['format'];

        if ($format === 'stream-json') {
            $ui = new StreamJsonUi(STDOUT);
            $kernel = $this->kernelFor($options, $ui, true);
            $ui->useSecrets($kernel->secrets());
        } else {
            $ui = null;
            $kernel = $this->kernel($options, $format === 'text' && $schema === null, true);
        }
        $previous = $this->sessionToContinue($kernel, $options);
        $profile = $kernel->config->profile($options['profile'] ?? (($previous['profile'] ?? '') !== '' ? $previous['profile'] : null))->name;
        $same = $previous !== null && $previous['profile'] === $profile;
        $model = $options['model'] !== null ? trim((string) $options['model']) : ($same ? $previous['model'] : null);
        $thinking = $same ? $previous['thinking'] : null;
        $primary = $kernel->primaryAgent($options['agent']);
        $transcript = Transcript::open($kernel->sessionDirectory(), $kernel->ui);
        $cancel = new Cancellation();
        self::trapInterrupt(static fn () => $cancel->cancel('interrupted by the user'));
        if ($kernel->headlessRestricted()) {
            $kernel->ui->notice('info', 'no terminal to approve actions: file writes and commands are disabled (use --yolo to allow them)');
        }
        if ($kernel->sandboxes->describe() !== 'host') {
            $kernel->ui->notice('info', 'commands run in the sandbox: ' . $kernel->sandboxes->describe());
        }

        $mode = $kernel->toolMode($profile, $model, $thinking);
        $policy = $kernel->policyFor($primary);
        $context = $kernel->context(
            $primary,
            $policy,
            $transcript,
            $cancel,
            $profile,
            $kernel->delegatorFor(),
            $kernel->sandboxes->runnerFor($primary->sandbox),
            $model,
            $thinking,
        );
        $sessionId = $previous['id'] ?? SessionStore::newId();
        $ui?->event('init', [
            'session_id' => $sessionId,
            'profile' => $profile,
            'model' => $model ?? $kernel->config->profile($profile)->model,
            'tool_mode' => $mode,
            'cwd' => $kernel->workspace->root,
            'resumed' => $previous !== null,
        ]);

        $conversation = new Conversation();
        if ($previous !== null) {
            $conversation->replaceMessages($previous['messages']);
        }
        $conversation->setSystem($kernel->systemPrompt($primary, $mode, $policy));
        $hook = $kernel->runner->promptSubmitted($prompt, $context);
        if ($hook->block !== null) {
            throw new PhycodeException('the prompt was blocked by a hook: ' . $hook->block);
        }
        $message = $hook->context === [] ? $prompt : $prompt . "\n\nUserPromptSubmit hook:\n" . implode("\n", $hook->context);
        $pictures = [];
        foreach ($options['images'] as $picture) {
            $real = realpath(str_starts_with($picture, '~/') ? (string) getenv('HOME') . substr($picture, 1) : $picture);
            $pictures[] = Image::fromFile($real === false ? $picture : $real, basename($picture));
        }
        if ($pictures !== [] && !$kernel->config->profile($profile)->supportsVision()) {
            throw new PhycodeException("the profile {$profile} is not set to read images: put \"vision\": true in it if its model does");
        }
        $conversation->addUser($schema === null ? $message : $message . "\n\n" . self::schemaInstruction($schema), $pictures);
        try {
            $kernel->checkpoints->begin(SessionStore::newId());
        } catch (\Throwable $error) {
            $kernel->ui->notice('warn', 'no checkpoint for this run, so its file changes are refused: ' . Redaction::text($error->getMessage(), $kernel->secrets()));
        }
        $setup = new RunSetup($kernel->clients->forProfile($profile, $model, $thinking), $mode, $kernel->tools, $context, $options['max_turns'] ?? $kernel->config->limits->primaryMaxTurns, $format !== 'json' && $schema === null);
        $outcome = $kernel->runner->run($conversation, $setup);
        $turns = $outcome->turns;
        $calls = $outcome->toolCalls;

        $structured = null;
        $invalid = null;
        for ($attempt = 0; $schema !== null && $outcome->status === OutcomeStatus::Completed; $attempt++) {
            $invalid = self::schemaProblems($outcome->text, $schema, $structured);
            if ($invalid === []) {
                $invalid = null;
                break;
            }
            if ($attempt >= self::SCHEMA_RETRIES) {
                break;
            }
            $conversation->addUser("Your answer does not fit the schema:\n- " . implode("\n- ", $invalid) . "\nReply again with only the corrected JSON value.");
            $outcome = $kernel->runner->run($conversation, $setup);
            $turns += $outcome->turns;
            $calls += $outcome->toolCalls;
            $invalid = null;
        }
        $this->saveSession($kernel, $previous, $sessionId, $profile, $model, $thinking, $conversation);

        $changed = $kernel->changes->since(0);
        $reason = $outcome->reason === null ? null : Redaction::text($outcome->reason, $kernel->secrets());
        $status = $outcome->status->value;
        if ($invalid !== null) {
            $status = 'invalid_output';
            $reason = 'the answer does not fit the schema of --output-schema: ' . implode('; ', $invalid);
        }
        $result = [
            'status' => $status,
            'text' => $outcome->text,
            'turns' => $turns,
            'tool_calls' => $calls,
            'reason' => $reason,
            'changed' => $changed,
            'transcript' => $transcript->path,
            'session_id' => $sessionId,
            'last_usage' => $outcome->lastUsage?->toArray(),
            'usage' => self::usageOf($kernel),
        ];
        if ($schema !== null && $invalid === null && $outcome->status === OutcomeStatus::Completed) {
            $result['structured_output'] = $structured;
        }
        if ($ui !== null) {
            $ui->event('result', $result);
        } elseif ($format === 'json') {
            fwrite(STDOUT, Json::encode($result, true) . "\n");
        } elseif (array_key_exists('structured_output', $result)) {
            fwrite(STDOUT, Json::encode($structured, true) . "\n");
        } elseif ($outcome->status === OutcomeStatus::Completed && $invalid === null) {
            fwrite(STDOUT, "\n");
        } else {
            fwrite(STDOUT, "\n" . $outcome->text . "\n");
        }

        if ($status !== 'completed') {
            fwrite(STDERR, "phycode: run ended ({$status}): " . ($reason ?? 'no reason') . "\n");
        }
        fwrite(STDERR, 'changes: ' . ($changed === [] ? 'none' : implode(', ', $changed)) . "\n");
        fwrite(STDERR, "transcript: {$transcript->path}\n");
        fwrite(STDERR, "session: {$sessionId}\n");

        return $invalid !== null ? 4 : self::exitCode($outcome);
    }

    private static function usageOf(Kernel $kernel): array
    {
        $total = $kernel->usage->totals($kernel->pricing());

        return ['requests' => $total['requests'], 'prompt_tokens' => $total['prompt'], 'completion_tokens' => $total['completion'], 'cached_tokens' => $total['cached'], 'cost_usd' => $total['cost'] === null ? null : round($total['cost'], 6), 'priced' => $total['priced']];
    }

    private function sessionToContinue(Kernel $kernel, array $options): ?array
    {
        if (!$options['continue'] && $options['resume'] === null) {
            return null;
        }
        $store = new SessionStore($kernel->workspace->root);
        $id = $options['resume'] ?? ($store->all()[0]['id'] ?? throw new PhycodeException('there is no session to continue in this project; start one with phycode run "task"'));
        $session = $store->load((string) $id);
        if (!$kernel->config->hasProfile($session['profile']) && $options['profile'] === null) {
            $kernel->ui->notice('warn', "the profile '{$session['profile']}' of the session is not in the config; the default profile runs it");
            $session['profile'] = '';
        }

        return $session;
    }

    private function saveSession(Kernel $kernel, ?array $previous, string $id, string $profile, ?string $model, ?string $thinking, Conversation $conversation): void
    {
        $secrets = $kernel->secrets();
        $messages = $conversation->messages();
        try {
            (new SessionStore($kernel->workspace->root))->save([
                'id' => $id,
                'title' => $previous['title'] ?? Redaction::text(ChatSession::titleOf($messages), $secrets),
                'createdAt' => $previous['createdAt'] ?? SessionStore::now(),
                'updatedAt' => SessionStore::now(),
                'profile' => $profile,
                'model' => $model ?? $kernel->config->profile($profile)->model,
                'thinking' => $thinking ?? $kernel->config->profile($profile)->thinking,
                'messages' => ChatSession::redacted($messages, $secrets),
            ]);
        } catch (PhycodeException $error) {
            $kernel->ui->notice('warn', 'the session is not saved: ' . Redaction::text($error->getMessage(), $secrets));
        }
    }

    private static function loadSchema(string $argument): array
    {
        $text = str_starts_with(ltrim($argument), '{') ? $argument : (is_file($argument) && filesize($argument) <= 262144 ? (string) file_get_contents($argument) : null);
        if ($text === null) {
            throw new PhycodeException("--output-schema: '{$argument}' is neither a JSON object nor a readable file of at most 256 KB");
        }
        $schema = Json::object($text);
        if ($schema === null) {
            throw new PhycodeException('--output-schema is not a JSON object');
        }
        try {
            JsonSchema::check($schema);
        } catch (PhycodeException $error) {
            throw new PhycodeException('--output-schema: ' . $error->getMessage());
        }

        return $schema;
    }

    private static function schemaInstruction(array $schema): string
    {
        return "When you are done, your final answer must be only one JSON value that fits this JSON Schema: no code fence and no text before or after it.\n" . Json::encode($schema);
    }

    private static function schemaProblems(string $answer, array $schema, mixed &$value): array
    {
        if (!JsonSchema::extract($answer, $value)) {
            return ['the answer has no JSON value in it'];
        }

        return JsonSchema::errors($value, $schema);
    }

    private function chat(array $options): int
    {
        $kernel = $this->kernel($options, true, true);
        $profile = $kernel->config->profile($options['profile'])->name;
        $primary = $kernel->primaryAgent($options['agent']);
        $transcript = Transcript::open($kernel->sessionDirectory(), $kernel->ui);
        $session = new ChatSession($kernel, $profile, $primary, $transcript);
        self::trapInterrupt(static fn () => $session->interrupt());
        if ($kernel->headlessRestricted()) {
            $kernel->ui->notice('info', 'no terminal to approve actions: file writes and commands are disabled (use --yolo to allow them)');
        }
        fwrite(STDERR, $session->banner() . "\n");

        while (true) {
            fwrite(STDERR, "\n{$session->primary()->name}> ");
            $line = fgets(STDIN);
            if ($line === false) {
                fwrite(STDERR, "\n");
                break;
            }
            if (!$session->handle($line)) {
                break;
            }
        }

        fwrite(STDERR, "transcript: {$session->transcriptPath()}\n");

        return 0;
    }

    private function rpc(array $options): int
    {
        if ($options['positional'] !== []) {
            throw new PhycodeException('rpc takes no task: it reads its commands from stdin');
        }
        ini_set('display_errors', 'stderr');
        $channel = new JsonLines(STDIN, STDOUT);
        $ui = new RpcUi($channel);
        $kernel = null;
        try {
            $kernel = $this->kernelFor($options, $ui, true);
            $profile = $kernel->config->profile($options['profile'])->name;
            $primary = $kernel->primaryAgent($options['agent']);
            $transcript = Transcript::open($kernel->sessionDirectory(), $kernel->ui);
            $session = new ChatSession($kernel, $profile, $primary, $transcript);
        } catch (\Throwable $error) {
            $message = Redaction::text($error->getMessage(), $kernel?->secrets() ?? []);
            fwrite(STDERR, 'phycode: ' . $message . PHP_EOL);
            $channel->send(['type' => 'error', 'message' => $message]);

            return 1;
        }
        $ui->attach(static fn (): string => $session->primary()->name, static fn () => $session->interrupt());
        self::trapInterrupt(static fn () => $ui->interrupt());

        return (new RpcServer($channel, $ui, $kernel, $session, self::VERSION))->run();
    }

    private function acp(array $options): int
    {
        ini_set('display_errors', 'stderr');
        $factory = function (string $cwd, array $mcp, AcpUi $ui) use ($options): array {
            $kernel = new Kernel($cwd, (bool) $options['yolo'], $ui, $options['sandbox'] ?? null, null, [], true, $mcp);
            $profile = $kernel->config->profile($options['profile'])->name;
            $primary = $kernel->primaryAgent($options['agent']);
            $transcript = Transcript::open($kernel->sessionDirectory(), $kernel->ui);

            return [$kernel, new ChatSession($kernel, $profile, $primary, $transcript)];
        };

        return (new AcpServer(new JsonLines(STDIN, STDOUT), $factory, self::VERSION))->run();
    }

    private function serve(array $options): int
    {
        ini_set('display_errors', 'stderr');
        $values = ['socket' => null, 'listen' => null, 'token-file' => null, 'max-connections' => '8'];
        $words = $options['positional'];
        for ($i = 0; $i < count($words); $i++) {
            $word = (string) $words[$i];
            $parts = explode('=', substr($word, 2), 2);
            if (!str_starts_with($word, '--') || !array_key_exists($parts[0], $values)) {
                throw new PhycodeException("serve does not take '{$word}': see phycode help");
            }
            $values[$parts[0]] = $parts[1] ?? $words[++$i] ?? throw new PhycodeException("--{$parts[0]} needs a value");
        }
        if (preg_match('/^[1-9][0-9]{0,2}$/D', (string) $values['max-connections']) !== 1) {
            throw new PhycodeException('--max-connections must be a whole number from 1 to 256');
        }
        $address = ServeDaemon::address($values['socket'], $values['listen'], ServeDaemon::defaultSocket());
        $home = (string) (getenv('HOME') ?: '/root');
        $configDirectory = rtrim((string) (getenv('XDG_CONFIG_HOME') ?: $home . '/.config'), '/') . '/phycode';
        $tokenFile = $values['token-file'];
        if ($tokenFile === null && str_starts_with($address, 'tcp://')) {
            $tokenFile = $configDirectory . '/serve-token';
            fwrite(STDERR, "phycode: a TCP port needs a token; it is in {$tokenFile}\n");
        }
        if ($tokenFile !== null && !is_dir(dirname($tokenFile))) {
            mkdir(dirname($tokenFile), 0700, true);
        }
        $factory = function (string $folder, ?string $profile, ?string $agent, RpcUi $ui) use ($options): array {
            $kernel = null;
            try {
                $kernel = $this->kernelFor([...$options, 'cwd' => $folder], $ui, true);
                $name = $kernel->config->profile($profile ?? $options['profile'])->name;
                $primary = $kernel->primaryAgent($agent ?? $options['agent']);
                $transcript = Transcript::open($kernel->sessionDirectory(), $kernel->ui);

                return [$kernel, new ChatSession($kernel, $name, $primary, $transcript)];
            } catch (\Throwable $error) {
                throw new PhycodeException(Redaction::text($error->getMessage(), $kernel?->secrets() ?? []));
            }
        };

        return (new ServeDaemon($address, $tokenFile === null ? null : ServeDaemon::token($tokenFile), $factory, self::VERSION, self::projectFolder($options), (int) $values['max-connections']))->run();
    }

    private function tui(array $options): int
    {
        if ($options['positional'] !== []) {
            throw new PhycodeException('the full-screen UI takes no task; use phycode run "task" for one task');
        }
        new Workspace(self::projectFolder($options), $options['add_dir']);
        $binary = Entry::binFolder() . '/phycode-tui';
        [$php, $script] = Entry::command();
        $launch = self::tuiLaunch($options, $binary, $php, $script, function_exists('pcntl_exec'));
        if ($launch['outcome'] === 'not-built') {
            fwrite(STDERR, "phycode-tui is not built; using the built-in terminal UI. Build it with: cd tui && go build -o ../bin/phycode-tui ./cmd/phycode-tui\n");
        } elseif ($launch['outcome'] === 'no-pcntl') {
            fwrite(STDERR, "phycode: warning: pcntl_exec is not available; using the built-in terminal UI\n");
        } else {
            @pcntl_exec($binary, $launch['argv']);
            fwrite(STDERR, 'phycode: warning: cannot start phycode-tui (' . pcntl_strerror(pcntl_get_last_error()) . "); using the built-in terminal UI\n");
        }

        $terminal = Terminal::open();
        try {
            $ui = new TuiUi($terminal);
            $kernel = $this->kernelFor($options, $ui, true);
            $profile = $kernel->config->profile($options['profile'])->name;
            $primary = $kernel->primaryAgent($options['agent']);
            $transcript = Transcript::open($kernel->sessionDirectory(), $kernel->ui);
            $session = new ChatSession($kernel, $profile, $primary, $transcript);

            return (new TuiApp($terminal, $ui, $session, $kernel))->run();
        } finally {
            $terminal->close();
        }
    }

    public static function tuiLaunch(array $options, string $binary, string $php, string $script, bool $pcntl): array
    {
        $argv = ['--', $php, $script, 'rpc'];
        foreach (['cwd', 'profile', 'agent', 'sandbox'] as $name) {
            if ($options[$name] !== null) {
                array_push($argv, "--{$name}", (string) $options[$name]);
            }
        }
        foreach ($options['add_dir'] as $folder) {
            array_push($argv, '--add-dir', $folder);
        }
        if ($options['yolo']) {
            $argv[] = '--yolo';
        }

        $outcome = match (true) {
            !is_file($binary) || !is_executable($binary) => 'not-built',
            !$pcntl => 'no-pcntl',
            default => 'exec',
        };

        return ['outcome' => $outcome, 'argv' => $argv];
    }

    private function listAgents(array $options): int
    {
        $kernel = $this->kernel($options, false);
        fwrite(STDOUT, $kernel->agentListing() . "\n");

        return 0;
    }

    private function doctor(array $options): int
    {
        $kernel = $this->kernel($options, false);
        $secrets = $kernel->secrets();
        $ok = true;
        $write = static function (string $text) use ($secrets): void {
            fwrite(STDOUT, Redaction::text($text, $secrets));
        };
        $line = static function (string $label, bool $pass, string $detail = '') use (&$ok, $write): void {
            $ok = $ok && $pass;
            $write(sprintf("[%s] %-22s %s\n", $pass ? 'ok' : 'FAIL', $label, $detail));
        };
        $info = static function (string $label, string $detail) use ($write): void {
            $write(sprintf("[--] %-22s %s\n", $label, $detail));
        };

        $line('PHP version', version_compare(PHP_VERSION, '8.4.1', '>='), PHP_VERSION);
        $advisory = PhpAdvisory::problem(PHP_VERSION);
        if ($advisory === null) {
            $line('PHP advisory', true, 'includes the fix for ' . PhpAdvisory::CVES);
        } else {
            $info('PHP advisory', 'warning: ' . $advisory);
        }
        foreach (['curl', 'json', 'mbstring', 'posix'] as $extension) {
            $line("extension {$extension}", extension_loaded($extension));
        }
        $write('[--] extension pcntl          ' . (extension_loaded('pcntl') ? 'available (Ctrl+C cancels turns)' : 'missing (Ctrl+C ends the process)') . "\n");
        foreach (['timeout', 'bash', 'git', 'python3', 'node', 'rg', 'podman', 'docker', 'bwrap', 'socat'] as $binary) {
            $found = ProcessRunner::which($binary);
            $write(sprintf("[%s] %-22s %s\n", $found !== null ? 'ok' : '--', $binary, $found ?? 'not found'));
        }

        $sandbox = $kernel->config->sandbox;
        if ($sandbox->default === 'host') {
            $line('sandbox', true, 'host (commands run on this machine)');
        } else {
            $installed = ContainerRunner::available($sandbox->default);
            $imageReady = $installed && ContainerRunner::imageExists($sandbox->default, (string) $sandbox->image, $kernel->processes);
            $detail = match (true) {
                !$installed => "{$sandbox->default} is not installed",
                $imageReady => "{$sandbox->default}, image {$sandbox->image}",
                default => "{$sandbox->default} is installed but image {$sandbox->image} is missing",
            };
            $line('sandbox', $installed && $imageReady, $detail);
        }

        $profile = $kernel->config->profile($options['profile']);
        $line('profile', true, sprintf('%s -> %s model=%s tool_mode=%s', $profile->name, Redaction::endpoint($profile->baseUrl), $profile->model, $profile->toolMode));
        $skipped = LlamaDoctor::skipReason($profile);
        if ($skipped !== null) {
            $info('server health', $skipped);
        } else {
            $discovery = new LlamaDiscovery($profile);
            try {
                $health = $discovery->reply('/health');
                $line('server health', $health['status'] === 200, 'HTTP ' . $health['status']);
                $listing = $discovery->reply('/v1/models');
                if ($listing['status'] >= 400) {
                    $line('model listed', false, Http::error($listing['status'], $listing['body'])->getMessage());
                } else {
                    $models = Json::object($listing['body']) ?? [];
                    $ids = array_map(static fn (array $model): string => (string) ($model['id'] ?? '?'), is_array($models['data'] ?? null) ? $models['data'] : []);
                    $line('model listed', in_array($profile->model, $ids, true) || $ids === [], implode(', ', $ids) ?: 'none listed');
                }
                $states = null;
                $unknown = $discovery->llamaServer() === null ? 'GET /props failed' : null;
                if ($unknown === null) {
                    try {
                        $states = $discovery->routerStates();
                    } catch (LlmException $error) {
                        $unknown = $error->getMessage();
                    }
                }
                $info('router mode', match (true) {
                    $unknown !== null => "unknown ({$unknown})",
                    $states !== null => 'yes (models report load status)',
                    default => 'no (single-model server)',
                });
                if ($unknown !== null) {
                    $info('tool calling', 'skipped: the router mode is unknown, so doctor sends no test request');
                } elseif ($states !== null && ($states[$profile->model] ?? null) !== 'loaded') {
                    $state = $states[$profile->model] ?? 'not listed';
                    $info('tool calling', "{$state} (doctor does not load models)");
                } else {
                    $probe = $kernel->clients->probe($profile->name);
                    $configured = $profile->toolMode === 'auto' ? '' : " (set in config: {$profile->toolMode})";
                    $line('tool calling', $probe['mode'] !== 'none', $probe['mode'] . $configured . ($probe['error'] !== null ? ': ' . $probe['error'] : ''));
                }
            } catch (PhycodeException $error) {
                $line('server reachable', false, $error->getMessage());
            }
        }

        $repository = file_exists($kernel->workspace->root . '/.git');
        $write('[--] git repository          ' . ($repository ? 'yes (git_read, git_write and git_remote are offered)' : 'no (git tools are not offered)') . "\n");
        $line('agents', $kernel->agents->all() !== [], implode(', ', array_keys($kernel->agents->all())));
        foreach ($kernel->agents->warnings() as $warning) {
            $write("[--] warning                 {$warning}\n");
        }
        foreach ($kernel->config->warnings as $warning) {
            $write("[--] config                  {$warning}\n");
        }
        try {
            $gentleLines = (new GentleAi())->doctorLines($kernel->config->gentleAiBin(), $kernel->workspace->root, $secrets);
        } catch (ConfigException $error) {
            $line('gentle-ai', false, $error->getMessage());
            $gentleLines = [];
        }
        foreach ($gentleLines as $gentleLine) {
            $write("[--] {$gentleLine}\n");
        }
        try {
            $vault = Vault::inUserConfig();
            $stored = $vault->profiles();
            foreach ($stored as $name) {
                $vault->get($name);
            }
            $wanted = array_map('strval', array_keys(array_filter($kernel->config->profiles, static fn (Profile $profile): bool => $profile->keyInVault())));
            $missing = array_values(array_diff($wanted, $stored));
            if ($missing !== []) {
                $line('vault', false, 'no key is stored for ' . implode(', ', $missing) . '; set one with phycode keys set <profile>');
            } elseif ($stored === []) {
                $info('vault', 'no keys stored');
            } else {
                $line('vault', true, 'keys stored for ' . implode(', ', $stored) . ' (values are never shown)');
            }
        } catch (PhycodeException $error) {
            $line('vault', false, $error->getMessage());
        }
        foreach ($kernel->config->profiles as $llamaProfile) {
            if (LlamaDoctor::remote($llamaProfile)) {
                $info('llama health', LlamaDoctor::REMOTE_SKIP);

                continue;
            }
            if (!LlamaDoctor::applies($llamaProfile)) {
                continue;
            }
            try {
                foreach (LlamaDoctor::report($llamaProfile) as $check) {
                    if ($check['pass'] === null) {
                        $info($check['label'], $check['detail']);
                    } else {
                        $line($check['label'], $check['pass'], $check['detail']);
                    }
                }
            } catch (PhycodeException $error) {
                $line('llama ' . $llamaProfile->name, false, $error->getMessage());
            }
        }

        return $ok ? 0 : 1;
    }

    private function deploy(array $options): int
    {
        $home = (string) (getenv('HOME') ?: '/root');
        $configFile = rtrim((string) (getenv('XDG_CONFIG_HOME') ?: $home . '/.config'), '/') . '/phycode/config.json';
        $root = self::projectFolder($options);

        return DeployCli::run($options['positional'], $configFile, $root, STDOUT, STDERR, readSecret: static fn (string $prompt): string => self::readSecret($prompt));
    }

    private function ssh(array $options): int
    {
        $home = (string) (getenv('HOME') ?: '/root');
        $configFile = rtrim((string) (getenv('XDG_CONFIG_HOME') ?: $home . '/.config'), '/') . '/phycode/config.json';

        return SshCli::run($options['positional'], $configFile, STDOUT, STDERR);
    }

    private function proxyRun(array $options): int
    {
        $values = ['socket' => null, 'log' => null, 'allow' => ''];
        $private = false;
        $words = $options['positional'];
        for ($i = 0; $i < count($words); $i++) {
            if ($words[$i] === '--allow-private') {
                $private = true;
            } elseif (isset($values[ltrim((string) $words[$i], '-')]) || in_array($words[$i], ['--socket', '--log', '--allow'], true)) {
                $values[ltrim((string) $words[$i], '-')] = $words[++$i] ?? '';
            }
        }
        if (!is_string($values['socket']) || $values['socket'] === '') {
            throw new PhycodeException('proxy-run needs --socket');
        }
        (new FilteringProxy(new DomainAllowlist(array_values(array_filter(explode(',', (string) $values['allow'])))), $private, is_string($values['log']) ? $values['log'] : null))->serve($values['socket']);

        return 0;
    }

    private function mcpCommand(array $options): int
    {
        $home = (string) (getenv('HOME') ?: '/root');
        $configDir = rtrim((string) (getenv('XDG_CONFIG_HOME') ?: $home . '/.config'), '/') . '/phycode';
        $cwd = self::projectFolder($options);

        return McpCli::run($options['positional'], Config::load($cwd, $configDir, getenv())->mcpServers(), $cwd, new Vault($configDir), STDOUT, STDERR);
    }

    private function keys(array $options): int
    {
        $arguments = $options['positional'];
        $action = $arguments[0] ?? '';
        $profile = $arguments[1] ?? '';
        $valid = match ($action) {
            'list' => count($arguments) === 1,
            'set', 'rm' => count($arguments) === 2 && Vault::validProfile($profile),
            default => false,
        };
        if (!$valid) {
            fwrite(STDERR, "usage: phycode keys set <profile> | phycode keys list | phycode keys rm <profile>\n");

            return 2;
        }

        $vault = Vault::inUserConfig();
        if ($action === 'list') {
            $profiles = $vault->profiles();
            if ($profiles === []) {
                fwrite(STDERR, "no keys are stored\n");
            }
            foreach ($profiles as $name) {
                fwrite(STDOUT, "{$name}\n");
            }

            return 0;
        }
        if ($action === 'rm') {
            if (!$vault->remove($profile)) {
                throw new PhycodeException("no key is stored for profile {$profile}");
            }
            fwrite(STDERR, "key removed for profile {$profile}\n");

            return 0;
        }
        $vault->store($profile, self::readKey($profile));
        fwrite(STDERR, "key stored for profile {$profile}\n");

        return 0;
    }

    private static function readKey(string $profile): string
    {
        return self::readSecret("API key for profile {$profile} (typing is hidden): ");
    }

    private static function readSecret(string $prompt): string
    {
        if (!posix_isatty(STDIN)) {
            return trim((string) stream_get_contents(STDIN));
        }
        $saved = self::stty('-g');
        if ($saved === null || self::stty('-echo') === null) {
            throw new PhycodeException('the terminal cannot hide the key as it is typed; give it on stdin instead, for example: phycode keys set <profile> < key.txt');
        }
        fwrite(STDERR, $prompt);
        try {
            $line = (string) fgets(STDIN);
        } finally {
            self::stty($saved);
            fwrite(STDERR, "\n");
        }

        return trim($line);
    }

    private static function stty(string ...$arguments): ?string
    {
        $process = proc_open(['stty', ...$arguments], [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return proc_close($process) === 0 ? trim($output) : null;
    }

    private function btwRun(): int
    {
        try {
            $request = Json::object((string) stream_get_contents(STDIN));
            if ($request === null) {
                throw new PhycodeException('btw-run reads one JSON object on stdin');
            }
            $answer = BtwWorker::run($request);
        } catch (\Throwable $error) {
            fwrite(STDOUT, Json::encode(['error' => Redaction::text($error->getMessage())]) . "\n");

            return 1;
        }
        fwrite(STDOUT, Json::encode($answer) . "\n");

        return 0;
    }

    private function version(): int
    {
        fwrite(STDOUT, 'phycode ' . self::VERSION . ' (PHP ' . PHP_VERSION . ")\n");

        return 0;
    }

    private function kernel(array $options, bool $streamText, bool $mcp = false): Kernel
    {
        $ui = new ConsoleUi(STDOUT, STDERR, STDIN, $streamText, posix_isatty(STDERR) && getenv('NO_COLOR') === false);

        return $this->kernelFor($options, $ui, $mcp);
    }

    private function kernelFor(array $options, Ui $ui, bool $mcp = false): Kernel
    {
        $kernel = new Kernel(self::projectFolder($options), (bool) $options['yolo'], $ui, $options['sandbox'] ?? null, null, $options['add_dir'], $mcp);
        if ($kernel->workspace->readOnly !== []) {
            $ui->notice('info', 'read-only folders (--add-dir): ' . implode(', ', $kernel->workspace->readOnly));
            $ui->notice('info', 'the shell is disabled: bash and run_code are not offered, because they cannot keep the --add-dir folders read-only');
        }

        return $kernel;
    }

    private static function projectFolder(array $options): string
    {
        $cwd = realpath((string) ($options['cwd'] ?? getcwd()));
        if ($cwd === false) {
            throw new PhycodeException('the --cwd folder does not exist');
        }

        return $cwd;
    }

    private static function trapInterrupt(\Closure $onInterrupt): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static function () use ($onInterrupt): void {
            $onInterrupt();
        });
    }

    private static function exitCode(Outcome $outcome): int
    {
        return match ($outcome->status) {
            OutcomeStatus::Completed => 0,
            OutcomeStatus::Budget => 3,
            default => 2,
        };
    }

    private static function usage($stream): void
    {
        fwrite($stream, <<<'TEXT'
            phycode - a local coding agent for llama.cpp servers

            Usage:
              phycode run [options] "task"      run one task and print the result
              phycode -p [options] "task"       same as run, in print mode (headless)
              phycode chat [options]            line-based interactive session
              phycode rpc [options]             JSON-lines backend for the terminal UI (stdin and stdout)
              phycode acp [options]             agent for editors that speak the Agent Client Protocol (Zed, JetBrains, Neovim)
              phycode serve [options]           daemon: several clients drive sessions over a unix socket (or a loopback port with a token)
              phycode -t [options]              full-screen terminal UI (same session as chat)
              phycode agents [options]          list agents and their tools
              phycode doctor [options]          check the server, profile, sandbox and tools
              phycode keys set <profile>        store an API key, typed hidden or piped on stdin
              phycode keys list                 list the profiles that have a stored key
              phycode keys rm <profile>         remove a stored key
              phycode shell [ACTION]            status (the default), install or uninstall the PATH block in the login shell
              phycode ssh [ACTION]              hosts the model may run commands on: list, add, remove, trust, test
              phycode mcp [ACTION]              MCP servers: list, login NAME, logout NAME, test NAME
              phycode deploy [ACTION]           where the model may deploy: list, add, remove, secret, plan, history; or NAME ACTION
              phycode version

            Options:
              --cwd DIR        project folder (default: current folder)
              --profile NAME   profile from config.json (default: the configured default)
              --agent NAME     primary agent to run (default: build)
              --sandbox NAME   run bash and run_code on host, podman, docker or bwrap (bubblewrap)
                               (default: sandbox.default; overrides agent files)
              --yolo           do not ask before writes and commands
              --output-format F  run only: text (default), json (one result object) or stream-json (one event per line)
              --json           run only: same as --output-format json
              --model NAME     run only: the model for this run (default: the one of the profile)
              --max-turns N    run only: stop after N model turns (default: limits.primary_max_turns)
              --output-schema S  run only: a JSON Schema file (or JSON) that the final answer must fit; exit code 4 when it does not
              --image FILE     run only: send an image (PNG, JPEG, GIF, WebP) with the task (repeatable); the profile needs vision
              --continue, -c   run only: go on with the newest session of the project
              --resume ID, -r ID  run only: go on with a saved session (/sessions lists the ids)
              --add-dir DIR    read-only folder outside the project: read, list and grep may use it (repeatable)

            serve options:
              --socket PATH         unix socket to listen on (mode 0600; default: $XDG_RUNTIME_DIR/phycode/serve.sock)
              --listen HOST:PORT    listen on a loopback port instead (127.0.0.1, localhost or [::1]); needs a token
              --token-file FILE     the token a client must send in its hello line (made, mode 0600, when missing)
              --max-connections N   sessions at the same time (default 8)
              A client sends {"type":"hello","token":"...","cwd":"/abs/folder"} and then speaks the protocol of phycode rpc.
              Whoever connects uses your tools: only you should reach the socket or the token.

            In chat, @NAME TASK runs a subagent directly.

            TEXT);
    }
}
