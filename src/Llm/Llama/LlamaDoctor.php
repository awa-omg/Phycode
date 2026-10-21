<?php

declare(strict_types=1);

namespace Phycode\Llm\Llama;

use Phycode\Config\Profile;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\OpenAiClient;
use Phycode\Support\Json;
use Phycode\Support\Redaction;

final class LlamaDoctor
{
    private const TOOL_REQUEST = 'Call the tool probe_echo with word "phycode". Do not answer in plain text.';
    private const TOOL = [
        'type' => 'function',
        'function' => [
            'name' => 'probe_echo',
            'description' => 'Echo one word back. Used only to test tool calling.',
            'parameters' => ['type' => 'object', 'properties' => ['word' => ['type' => 'string']], 'required' => ['word']],
        ],
    ];

    public const REMOTE_SKIP = 'skipped: remote server (use phycode run to test it)';

    private array $lines = [];
    private readonly LlamaDiscovery $discovery;
    private readonly string $where;
    private readonly string $listen;

    private function __construct(private readonly Profile $profile)
    {
        $this->discovery = new LlamaDiscovery($profile);
        $socket = $profile->unixSocket();
        if ($socket !== null) {
            $this->where = "socket {$socket}";
            $this->listen = "--host {$socket}";

            return;
        }
        $host = trim((string) parse_url($profile->baseUrl, PHP_URL_HOST), '[]');
        $port = (int) (parse_url($profile->baseUrl, PHP_URL_PORT) ?: (str_starts_with($profile->baseUrl, 'https://') ? 443 : 80));
        $this->where = "port {$port}";
        $this->listen = '--host ' . ($host === 'localhost' ? '127.0.0.1' : $host) . " --port {$port}";
    }

    public static function applies(Profile $profile): bool
    {
        if ($profile->protocol !== 'openai') {
            return false;
        }
        if ($profile->unixSocket() !== null) {
            return true;
        }
        $host = strtolower(trim((string) parse_url($profile->baseUrl, PHP_URL_HOST), '[]'));
        if (self::privateAddress($host)) {
            return true;
        }

        return $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || (!str_contains($host, '.') && !str_contains($host, ':'));
    }

    public static function skipReason(Profile $profile): ?string
    {
        if ($profile->protocol !== 'openai') {
            return "skipped: protocol {$profile->protocol}; doctor checks llama.cpp servers only";
        }
        if (!self::loopback($profile)) {
            return self::REMOTE_SKIP;
        }

        return null;
    }

    public static function remote(Profile $profile): bool
    {
        return !self::loopback($profile);
    }

    public static function report(Profile $profile): array
    {
        $reason = self::skipReason($profile);
        if ($reason !== null) {
            return [['pass' => null, 'label' => 'llama health', 'detail' => $reason]];
        }
        $doctor = new self($profile);
        $doctor->check();

        return $doctor->lines;
    }

    private function check(): void
    {
        $name = $this->profile->name;
        try {
            $health = $this->discovery->reply('/health');
        } catch (LlmException $error) {
            $this->add(false, 'llama health', "{$name}: cannot reach " . Redaction::endpoint($this->profile->baseUrl) . ' (' . Redaction::text($error->getMessage()) . ')');
            $this->fix(str_contains($error->getMessage(), 'SSL certificate')
                ? 'the server certificate is not trusted: set curl.cainfo in php.ini to a CA file that contains it'
                : "start llama-server with -m <model.gguf> {$this->listen} --jinja");

            return;
        }
        if ($health['status'] === 503) {
            $this->add(false, 'llama health', "{$name}: the model is still loading (HTTP 503)");
            $this->fix('wait until the model has loaded, then run doctor again');

            return;
        }
        if ($health['status'] !== 200) {
            $this->add(null, 'llama server', "not llama.cpp: GET /health answered HTTP {$health['status']}");

            return;
        }
        $this->add(true, 'llama health', "{$name}: ok at " . Redaction::endpoint($this->profile->baseUrl));

        try {
            $props = $this->discovery->props();
        } catch (LlmException $error) {
            if (str_starts_with($error->getMessage(), 'HTTP 401:') || str_starts_with($error->getMessage(), 'HTTP 403:')) {
                $this->add(false, 'llama server', "the server rejects the API key ({$error->getMessage()})");
                $this->fix("set api_key_env or api_key_file in profile {$name}; the key is sent as Authorization: Bearer");

                return;
            }
            $this->add(null, 'llama server', "not llama.cpp: {$error->getMessage()}");

            return;
        }
        $this->add(true, 'llama server', 'llama.cpp' . ($props->build !== '' ? " {$props->build}" : ''));

        if ($props->router) {
            $this->router();

            return;
        }
        $this->single($props);
    }

    private function single(LlamaProps $props): void
    {
        try {
            $ids = array_map(static fn (ModelInfo $model): string => $model->id, $this->discovery->models());
        } catch (LlmException $error) {
            $this->add(false, 'llama model', $error->getMessage());

            return;
        }
        if ($ids === []) {
            $this->add(false, 'llama model', 'the server lists no model');

            return;
        }
        $this->add(true, 'llama model', implode(', ', $ids) . " (the profile asks for {$this->profile->model})");
        $this->add(null, 'llama router mode', 'no (single-model server)');
        $this->context($props->contextWindow);
        $this->slots(null);
        $this->template($props);
        $this->tools();
    }

    private function router(): void
    {
        $models = $this->discovery->modelStates();
        $loaded = count(array_filter($models, static fn (ModelInfo $model): bool => $model->loaded === true));
        $this->add(null, 'llama router mode', 'yes (' . count($models) . " models, {$loaded} loaded)");

        $wanted = $this->profile->model;
        $listed = null;
        foreach ($models as $model) {
            if ($model->id === $wanted) {
                $listed = $model;
            }
        }
        if ($listed === null) {
            $names = implode(', ', array_map(static fn (ModelInfo $model): string => $model->id, $models)) ?: 'none';
            $this->add(false, 'llama model', "{$wanted} is not listed by the router (listed: {$names})");
            $this->fix("set model in profile {$this->profile->name} to one of the listed names, or add {$wanted} to the --models-dir or --models-preset of llama-server");

            return;
        }
        if ($listed->loaded !== true) {
            $this->add(null, 'llama model', "{$wanted} is not loaded; its first request loads it, so the checks below wait");

            return;
        }

        $this->add(true, 'llama model', "{$wanted} is loaded");
        $props = $this->discovery->props($wanted);
        $this->context($props->contextWindow);
        $this->slots($wanted);
        $this->template($props);
        $this->tools();
    }

    private function context(?int $window): void
    {
        $limit = $this->profile->contextLimit;
        if ($window === null) {
            $this->add(null, 'llama context', 'unknown');

            return;
        }
        if ($window < $limit) {
            $this->add(null, 'llama context', "{$window} tokens, below context_limit {$limit}");
            $this->fix("restart llama-server with -c {$limit} on {$this->where}");

            return;
        }
        $this->add(true, 'llama context', "{$window} tokens");
    }

    private function slots(?string $model): void
    {
        try {
            $slots = $this->discovery->slots($model);
        } catch (LlmException $error) {
            if (str_starts_with($error->getMessage(), 'HTTP 501:')) {
                $this->add(null, 'llama slots', 'the slots endpoint is off');
                $this->fix("restart llama-server with --slots on {$this->where}");

                return;
            }
            $this->add(false, 'llama slots', $error->getMessage());

            return;
        }
        $this->add(true, 'llama slots', "{$slots['total']} total: {$slots['idle']} idle, {$slots['busy']} busy");
    }

    private function template(LlamaProps $props): void
    {
        if ($props->chatTemplate === null) {
            $this->add(false, 'llama template', 'the server has no chat template');
            $this->fix($this->jinjaFix());

            return;
        }
        $this->add(true, 'llama template', 'present (' . strlen($props->chatTemplate) . ' bytes)');
    }

    private function tools(): void
    {
        try {
            $response = (new OpenAiClient($this->profile))->chat(
                [['role' => 'system', 'content' => 'You can call tools.'], ['role' => 'user', 'content' => self::TOOL_REQUEST]],
                [self::TOOL],
                new ChatOptions(overrides: [$this->profile->tokenParam => 256, 'temperature' => 0.0]),
            );
        } catch (LlmException $error) {
            if (str_contains($error->getMessage(), '--jinja')) {
                $this->add(false, 'llama tools', "native tool calls need --jinja ({$error->getMessage()})");
                $this->fix($this->jinjaFix());

                return;
            }
            $this->add(false, 'llama tools', "the test request failed ({$error->getMessage()})");

            return;
        }
        foreach ($response->toolCalls as $call) {
            if ($call->name === 'probe_echo' && Json::object($call->arguments) !== null) {
                $this->add(true, 'llama tools', 'native tool calls work');

                return;
            }
        }
        $this->add(null, 'llama tools', 'the model did not call the test tool; phycode falls back to text tool calls');
    }

    private function jinjaFix(): string
    {
        return "restart llama-server with --jinja on {$this->where} (this build enables --jinja by default; check with llama-server --help)";
    }

    private function add(?bool $pass, string $label, string $detail): void
    {
        $this->lines[] = ['pass' => $pass, 'label' => $label, 'detail' => $detail];
    }

    private function fix(string $command): void
    {
        $this->add(null, 'fix', $command);
    }

    private static function loopback(Profile $profile): bool
    {
        if ($profile->unixSocket() !== null) {
            return true;
        }
        $host = strtolower(trim((string) parse_url($profile->baseUrl, PHP_URL_HOST), '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }
        $address = ip2long($host);

        return $address !== false && ($address & 0xFF000000) === 0x7F000000;
    }

    private static function privateAddress(string $host): bool
    {
        if (str_contains($host, ':')) {
            return $host === '::1' || (bool) preg_match('/^(fe[89ab]|f[cd])/', $host);
        }
        $address = ip2long($host);
        if ($address === false) {
            return false;
        }
        foreach (['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16'] as $network) {
            [$base, $bits] = explode('/', $network);
            $mask = -1 << (32 - (int) $bits);
            if (($address & $mask) === (ip2long($base) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
