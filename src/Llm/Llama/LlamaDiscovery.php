<?php

declare(strict_types=1);

namespace Phycode\Llm\Llama;

use Phycode\Config\Profile;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\Http;
use Phycode\Llm\LlmException;
use Phycode\Llm\Retry;
use Phycode\Support\Json;

final class LlamaDiscovery
{
    private const TIMEOUT = 10;

    private ?LlamaProps $root = null;

    public function __construct(
        private readonly Profile $profile,
        private readonly array $loadingSteps = Retry::LOADING_STEPS,
        private readonly float $loadingLimit = Retry::LOADING_LIMIT,
    ) {
    }

    public function llamaServer(): ?bool
    {
        try {
            $reply = Retry::whileLoading(fn (): array => $this->reply('/props'), $this->loadingSteps, $this->loadingLimit);
        } catch (LlmException) {
            return null;
        }
        if (Retry::isLoading($reply['status'], $reply['body'])) {
            throw Retry::loadingTimeout($this->loadingLimit);
        }
        if ($reply['status'] >= 500) {
            return null;
        }
        $data = json_decode($reply['body'], true);
        $llama = $reply['status'] < 400 && is_array($data) && is_array($data['default_generation_settings'] ?? null);
        if ($llama) {
            $this->root = LlamaProps::fromArray($data);
        }

        return $llama;
    }

    public function props(?string $model = null): LlamaProps
    {
        $data = $this->body('/props', $model);
        if (!is_array($data) || !is_array($data['default_generation_settings'] ?? null)) {
            throw new LlmException("{$this->profile->baseUrl} is not llama.cpp: GET /props has no default_generation_settings");
        }

        $props = LlamaProps::fromArray($data);
        if ($model === null) {
            $this->root = $props;
        }

        return $props;
    }

    public function slots(?string $model = null): array
    {
        $slots = $this->body('/slots', $model);
        if (!is_array($slots) || !array_is_list($slots)) {
            throw new LlmException("{$this->profile->baseUrl} did not return a list of slots");
        }
        $busy = count(array_filter($slots, static fn (mixed $slot): bool => is_array($slot) && ($slot['is_processing'] ?? false) === true));

        return ['total' => count($slots), 'busy' => $busy, 'idle' => count($slots) - $busy];
    }

    public function models(): array
    {
        $models = [];
        foreach ($this->entries('/v1/models') as $entry) {
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $context = $meta['n_ctx'] ?? null;
            $status = self::status($entry);
            $props = $status === null ? $this->rootProps() : null;
            $models[] = new ModelInfo(
                id: $entry['id'],
                profile: $this->profile->name,
                protocol: $this->profile->protocol,
                contextWindow: is_int($context) && $context > 0 ? $context : null,
                tools: $props?->tools,
                thinkingLevels: $props === null ? [] : self::thinkingLevels($props),
                loaded: $status === null ? null : $status === 'loaded',
                source: 'v1/models',
            );
        }

        return $models;
    }

    public function routerModels(): array
    {
        return $this->props()->router ? $this->modelStates() : [];
    }

    public function modelStates(): array
    {
        $models = [];
        foreach ($this->entries('/models') as $entry) {
            $models[] = new ModelInfo(
                id: $entry['id'],
                profile: $this->profile->name,
                protocol: $this->profile->protocol,
                loaded: self::status($entry) === 'loaded',
                source: 'models',
            );
        }

        return $models;
    }

    public function routerStates(): ?array
    {
        $props = $this->reply('/props');
        $listing = $this->reply('/models');
        foreach ([$props, $listing] as $reply) {
            if ($reply['status'] === 401 || $reply['status'] === 403) {
                throw Http::error($reply['status'], $reply['body']);
            }
        }

        $states = [];
        $data = $listing['status'] < 400 ? (Json::object($listing['body'])['data'] ?? null) : null;
        foreach (is_array($data) ? $data : [] as $entry) {
            $status = is_array($entry) ? self::status($entry) : null;
            if ($status !== null && is_string($entry['id'] ?? null)) {
                $states[$entry['id']] = $status;
            }
        }
        $router = $props['status'] === 200 && (Json::object($props['body'])['role'] ?? null) === 'router';

        return $router || $states !== [] ? $states : null;
    }

    public function describe(string $model): ModelInfo
    {
        $props = $this->props();
        if (!$props->router) {
            return $this->described($model, $props, null, 'props');
        }

        $listed = null;
        foreach ($this->modelStates() as $entry) {
            if ($entry->id === $model) {
                $listed = $entry;
            }
        }
        if ($listed === null || $listed->loaded !== true) {
            return new ModelInfo(id: $model, profile: $this->profile->name, protocol: $this->profile->protocol, loaded: false, source: 'models');
        }

        return $this->described($model, $this->props($model), true, 'props');
    }

    public function reply(string $path, ?string $model = null): array
    {
        $query = $model === null ? '' : '?' . http_build_query(['model' => $model], '', '&', PHP_QUERY_RFC3986);

        return Http::get(
            $this->profile->url($path) . $query,
            self::TIMEOUT,
            ['Accept: application/json', ...Http::authorization($this->profile)],
            $this->profile->unixSocket(),
        );
    }

    private function entries(string $path): array
    {
        $body = $this->body($path);
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        if (!is_array($data)) {
            throw new LlmException("the server at {$this->profile->baseUrl} did not return a model list");
        }

        return array_values(array_filter($data, static fn (mixed $entry): bool => is_array($entry) && is_string($entry['id'] ?? null) && $entry['id'] !== ''));
    }

    private function body(string $path, ?string $model = null): mixed
    {
        $reply = $this->reply($path, $model);
        if ($reply['status'] >= 400) {
            throw Http::error($reply['status'], $reply['body']);
        }

        return json_decode($reply['body'], true);
    }

    private static function status(array $entry): ?string
    {
        $status = $entry['status'] ?? null;

        return is_array($status) && is_string($status['value'] ?? null) ? $status['value'] : null;
    }

    private function rootProps(): ?LlamaProps
    {
        if ($this->root === null) {
            try {
                $this->props();
            } catch (LlmException) {
                return null;
            }
        }

        return $this->root;
    }

    private static function thinkingLevels(LlamaProps $props): array
    {
        return str_contains($props->chatTemplate ?? '', 'enable_thinking') ? ['off', 'on'] : [];
    }

    private function described(string $model, LlamaProps $props, ?bool $loaded, string $source): ModelInfo
    {
        return new ModelInfo(
            id: $model,
            profile: $this->profile->name,
            protocol: $this->profile->protocol,
            contextWindow: $props->contextWindow,
            tools: $props->tools,
            thinkingLevels: self::thinkingLevels($props),
            loaded: $loaded,
            source: $source,
        );
    }
}
