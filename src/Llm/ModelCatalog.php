<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Config\Config;
use Phycode\Config\Profile;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Support\PhycodeException;
use Phycode\Support\StateStore;

final class ModelCatalog
{
    private const AVAILABLE = ['openai', 'ollama', 'anthropic', 'openai-responses'];

    private array $listings = [];

    private array $fetching = [];

    private bool $loaded = false;
    private bool $loading = false;

    public function __construct(
        private readonly ClientProvider $clients,
        private readonly Config $config,
        private readonly ?StateStore $state = null,
    ) {
    }

    public function entries(bool $refresh = false): array
    {
        $this->ensure($refresh);

        return $this->rows()[0];
    }

    public function providers(bool $refresh = false): array
    {
        $this->ensure($refresh);

        return $this->rows()[1];
    }

    private function ensure(bool $refresh): void
    {
        if ($this->loading || ($this->loaded && !$refresh)) {
            return;
        }

        $this->loading = true;
        try {
            foreach ($this->config->profiles as $key => $profile) {
                $name = (string) $key;
                $this->fetching[$name] = true;
                try {
                    $this->listings[$name] = $this->fetch($name, $profile);
                } finally {
                    unset($this->fetching[$name]);
                }
            }
            $this->loaded = true;
        } finally {
            $this->loading = false;
        }
    }

    private function fetch(string $name, Profile $profile): array
    {
        $updatedAt = $this->listings[$name]['updatedAt'] ?? null;
        if (!in_array($profile->protocol, self::AVAILABLE, true)) {
            return ['models' => [], 'error' => "protocol {$profile->protocol} is not available yet", 'updatedAt' => $updatedAt];
        }
        try {
            $models = $this->clients->forProfile($name)->listModels();
        } catch (PhycodeException $error) {
            return ['models' => [], 'error' => $error->getMessage(), 'updatedAt' => $updatedAt];
        }

        return [
            'models' => $models,
            'error' => $models === [] ? 'the server lists no models' : null,
            'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function rows(): array
    {
        $entries = [];
        $providers = [];
        foreach ($this->config->profiles as $key => $profile) {
            $name = (string) $key;
            $listing = $this->listings[$name] ?? ['models' => [], 'error' => null, 'updatedAt' => null];
            $rows = $listing['models'] === []
                ? [self::failed($name, $profile, $listing['error'])]
                : array_map(static fn (ModelInfo $info): array => self::listed($name, $profile, $info), $listing['models']);
            $ids = array_column($rows, 'model');
            $count = count($listing['models']);
            $custom = $this->state?->customModels($name) ?? [];
            foreach ($custom as $id) {
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                    $rows[] = self::custom($name, $profile, $id);
                    $count++;
                }
            }
            array_push($entries, ...$rows);
            $providers[] = [
                'profile' => $name,
                'protocol' => $profile->protocol,
                'endpoint' => self::endpoint($profile),
                'count' => $count,
                'error' => $listing['error'],
                'updatedAt' => $listing['updatedAt'],
                'refreshing' => isset($this->fetching[$name]),
            ];
        }

        return [$entries, $providers];
    }

    private static function listed(string $name, Profile $profile, ModelInfo $info): array
    {
        return [
            'profile' => $name,
            'model' => $info->id,
            'protocol' => $info->protocol,
            'contextWindow' => $info->contextWindow,
            'tools' => $info->tools,
            'thinking' => array_values($info->thinkingLevels),
            'thinkingDefault' => $profile->thinking,
            'loaded' => $info->loaded,
            'error' => null,
            'custom' => false,
            'kind' => $info->kind,
            'effortLevels' => $info->effortLevels,
        ];
    }

    private static function custom(string $name, Profile $profile, string $id): array
    {
        return [
            'profile' => $name,
            'model' => $id,
            'protocol' => $profile->protocol,
            'contextWindow' => null,
            'tools' => null,
            'thinking' => [],
            'thinkingDefault' => $profile->thinking,
            'loaded' => null,
            'error' => null,
            'custom' => true,
            'kind' => null,
            'effortLevels' => [],
        ];
    }

    private static function failed(string $name, Profile $profile, ?string $error): array
    {
        return [
            'profile' => $name,
            'model' => $profile->model,
            'protocol' => $profile->protocol,
            'contextWindow' => null,
            'tools' => null,
            'thinking' => [],
            'thinkingDefault' => $profile->thinking,
            'loaded' => null,
            'error' => $error,
            'custom' => false,
            'kind' => null,
            'effortLevels' => [],
        ];
    }

    private static function endpoint(Profile $profile): string
    {
        $socket = $profile->unixSocket();
        if ($socket !== null) {
            return 'unix:' . $socket;
        }
        $scheme = parse_url($profile->baseUrl, PHP_URL_SCHEME);
        $host = parse_url($profile->baseUrl, PHP_URL_HOST);
        if (!is_string($scheme) || !is_string($host)) {
            return '[invalid URL]';
        }
        $scheme = strtolower($scheme);
        $port = parse_url($profile->baseUrl, PHP_URL_PORT);
        $default = match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };

        return $scheme . '://' . $host . (is_int($port) && $port !== $default ? ":{$port}" : '');
    }
}
