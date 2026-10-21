<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Config\Config;
use Phycode\Config\Profile;
use Phycode\Support\Redaction;

final class ClientFactory implements ClientProvider
{
    private array $clients = [];

    private array $modes = [];

    private array $reasons = [];

    public function __construct(
        private readonly Config $config,
        private readonly CapabilityCache $cache,
    ) {
    }

    public function forProfile(string $name, ?string $model = null, ?string $thinking = null): ChatClient
    {
        $primary = $this->client($name, $model, $thinking);
        $chain = $this->config->fallbackChain($name);
        if ($chain === []) {
            return $primary;
        }

        $clients = [$primary];
        foreach ($chain as $fallback) {
            $clients[] = $this->client($fallback);
        }

        return new FallbackClient([$name, ...$chain], $clients);
    }

    private function client(string $name, ?string $model = null, ?string $thinking = null): ChatClient
    {
        $profile = $this->resolve($name, $model, $thinking);

        return $this->clients[self::key($profile)] ??= match ($profile->protocol) {
            'openai' => $this->toolRoute($profile),
            'ollama' => new \Phycode\Llm\Ollama\OllamaClient($profile),
            'anthropic' => new \Phycode\Llm\Anthropic\AnthropicClient($profile, cache: $this->cache),
            'openai-responses' => new \Phycode\Llm\Responses\ResponsesClient($profile),
            default => throw new LlmException("protocol {$profile->protocol} is not available yet"),
        };
    }

    public function toolMode(string $name, ?string $model = null, ?string $thinking = null): string
    {
        $profile = $this->resolve($name, $model, $thinking);
        $key = self::key($profile);
        if (isset($this->modes[$key])) {
            return $this->modes[$key];
        }

        if ($profile->toolMode !== 'auto') {
            return $this->modes[$key] = $profile->toolMode;
        }

        $cached = $this->cache->get(CapabilityCache::key($profile));
        if ($cached !== null) {
            return $this->modes[$key] = $cached['mode'];
        }

        $probe = $this->probe($name, $model, $thinking);
        if ($probe['transient']) {
            if (str_starts_with((string) $probe['error'], 'HTTP 429: ') && $this->config->fallbackChain($name) !== []) {
                return Probe::TEXT;
            }

            throw new LlmException(Redaction::text((string) $probe['error']));
        }

        return $probe['mode'];
    }

    public function probe(string $name, ?string $model = null, ?string $thinking = null): array
    {
        $profile = $this->resolve($name, $model, $thinking);
        $result = Probe::run($this->client($name, $model, $thinking));
        if (!$result['transient']) {
            $this->modes[self::key($profile)] = $result['mode'];
            if ($result['mode'] === Probe::NONE && $result['error'] !== null) {
                $this->reasons[self::key($profile)] = $result['error'];
            }
            if ($result['mode'] !== Probe::NONE) {
                $this->cache->put(CapabilityCache::key($profile), $result['mode'], $result['error']);
            }
        }

        return $result;
    }

    public function whyNoTools(string $name, ?string $model = null, ?string $thinking = null): ?string
    {
        return $this->reasons[self::key($this->resolve($name, $model, $thinking))] ?? null;
    }

    public function reportedLevels(string $name, ?string $model = null): ?array
    {
        $profile = $this->resolve($name, $model, null);
        if (!EffortMapping::readsListing($profile)) {
            return null;
        }

        return $this->cache->levels(CapabilityCache::key($profile), $profile->model);
    }

    private function resolve(string $name, ?string $model, ?string $thinking): Profile
    {
        $profile = $this->config->profile($name);
        if ($model !== null) {
            $profile = $profile->withModel($model);
        }
        if ($thinking !== null) {
            $profile = $profile->withThinking($thinking);
        }

        return $profile;
    }

    private function toolRoute(Profile $profile): ToolRouteClient
    {
        $key = CapabilityCache::key($profile);

        return new ToolRouteClient(
            new OpenAiClient($profile, cache: $this->cache),
            new \Phycode\Llm\Responses\ResponsesClient($profile->withProtocol('openai-responses')),
            $this->cache->route($key) === 'responses',
            fn () => $this->cache->merge($key, ['route' => 'responses']),
        );
    }

    private static function key(Profile $profile): string
    {
        return implode('|', [$profile->name, $profile->protocol, $profile->model, $profile->thinking]);
    }
}
