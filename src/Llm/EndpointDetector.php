<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Support\Json;

final class EndpointDetector
{
    private const PORTS = [9931, 8080, 11434, 1234, 8000];
    private const MAX_MODELS = 6;

    public function __construct(private readonly ?\Closure $get = null, private readonly ?\Closure $processes = null)
    {
    }

    public function detect(): array
    {
        $found = [];
        foreach ($this->candidates() as [$host, $port]) {
            $endpoint = $this->probe($host, $port);
            if ($endpoint !== null) {
                $found[] = $endpoint;
            }
        }

        return $this->named($found);
    }

    private function candidates(): array
    {
        $seen = [];
        $add = static function (string $host, int $port) use (&$seen): void {
            if ($port > 0 && $port < 65536) {
                $seen[$host . ':' . $port] = [$host, $port];
            }
        };
        foreach (self::PORTS as $port) {
            $add('127.0.0.1', $port);
        }
        foreach (explode("\n", $this->processList()) as $line) {
            if (preg_match('~(?:^|[\s/])llama[-_]?server(?:\s|$)~', $line) !== 1) {
                continue;
            }
            $host = preg_match('/--host[= ](\S+)/', $line, $h) === 1 && !in_array($h[1], ['0.0.0.0', '::', ''], true) ? $h[1] : '127.0.0.1';
            if (preg_match('/--port[= ](\d+)/', $line, $p) === 1) {
                $add($host, (int) $p[1]);
            }
        }
        $env = (string) getenv('LLAMA_ARG_PORT');
        if (ctype_digit($env)) {
            $add('127.0.0.1', (int) $env);
        }
        $ollama = (string) getenv('OLLAMA_HOST');
        if ($ollama !== '' && preg_match('~^(?:https?://)?([^:/]*):(\d+)~', $ollama, $o) === 1) {
            $add($o[1] === '' || $o[1] === '0.0.0.0' ? '127.0.0.1' : $o[1], (int) $o[2]);
        }

        return array_values($seen);
    }

    private function processList(): string
    {
        if ($this->processes !== null) {
            return ($this->processes)();
        }

        return (string) @shell_exec('ps -eo args= 2>/dev/null');
    }

    private function probe(string $host, int $port): ?array
    {
        $base = "http://{$host}:{$port}";

        $tags = $this->ask($base . '/api/tags');
        if ($tags !== null && $tags['status'] === 200 && is_array(self::field($tags['body'], 'models'))) {
            return self::endpoint('ollama', 'ollama', $base, 'ollama', self::names(self::field($tags['body'], 'models'), 'name'), false);
        }

        $models = $this->ask($base . '/v1/models');
        if ($models === null) {
            return null;
        }
        $server = $this->serverOf($base, $port);
        if (in_array($models['status'], [401, 403], true)) {
            return self::endpoint($server, 'openai', $base, $server, [], true);
        }
        if ($models['status'] !== 200 || !is_array(self::field($models['body'], 'data'))) {
            return null;
        }

        return self::endpoint($server, 'openai', $base, $server, self::names(self::field($models['body'], 'data'), 'id'), false);
    }

    private static function field(string $body, string $key): mixed
    {
        return (Json::object($body) ?? [])[$key] ?? null;
    }

    private static function names(array $items, string $member): array
    {
        $names = [];
        foreach ($items as $item) {
            if (is_array($item) && is_string($item[$member] ?? null)) {
                $names[] = $item[$member];
            }
        }

        return $names;
    }

    private function serverOf(string $base, int $port): string
    {
        $props = $this->ask($base . '/props');
        if ($props !== null && $props['status'] === 200 && is_array(self::field($props['body'], 'default_generation_settings'))) {
            return 'llama.cpp';
        }
        $version = $this->ask($base . '/version');
        if ($version !== null && $version['status'] === 200 && is_string(self::field($version['body'], 'version'))) {
            return 'vllm';
        }

        return $port === 1234 ? 'lm-studio' : 'openai-compatible';
    }

    private static function endpoint(string $suggest, string $protocol, string $base, string $server, array $names, bool $needsKey): array
    {
        return [
            'name' => $suggest,
            'protocol' => $protocol,
            'baseUrl' => $base,
            'server' => $server,
            'count' => count($names),
            'models' => array_slice($names, 0, self::MAX_MODELS),
            'needsKey' => $needsKey,
        ];
    }

    private function named(array $found): array
    {
        $count = array_count_values(array_column($found, 'name'));
        foreach ($found as $i => $endpoint) {
            $name = strtolower(str_replace('.', '-', $endpoint['name']));
            if ($endpoint['name'] === 'openai-compatible' || $count[$endpoint['name']] > 1) {
                $name .= '-' . (int) parse_url($endpoint['baseUrl'], PHP_URL_PORT);
            }
            $found[$i]['name'] = $name;
        }

        return $found;
    }

    private function ask(string $url): ?array
    {
        if ($this->get !== null) {
            return ($this->get)($url);
        }
        try {
            return Http::get($url, 1);
        } catch (LlmException) {
            return null;
        }
    }
}
