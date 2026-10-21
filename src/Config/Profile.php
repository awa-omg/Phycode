<?php

declare(strict_types=1);

namespace Phycode\Config;

use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;

final readonly class Profile
{
    public const PROTOCOLS = ['openai', 'ollama', 'openai-responses', 'anthropic'];
    public const EFFORTS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];
    public const ALIASES = ['off' => 'none', 'on' => 'medium'];
    public const THINKING = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max', 'off', 'on'];
    public const TOKEN_PARAMS = ['max_tokens', 'max_completion_tokens'];
    public const EFFORT_FORMATS = ['auto', 'openai', 'openrouter', 'deepseek', 'chat_template', 'none'];
    public const CACHE_TTLS = ['5m', '1h'];
    public const CACHE_RETENTIONS = ['in_memory', '24h'];
    public const COMPACT_MODES = ['auto', 'shared', 'standalone'];

    public function __construct(
        public string $name,
        public string $baseUrl,
        public string $model,
        public string $toolMode,
        public int $contextLimit,
        public int $maxOutputTokens,
        public array $sampling,
        public array $extraBody,
        private ?string $apiKeyFile,
        private ?string $apiKeyEnv,
        public string $protocol = 'openai',
        public string $thinking = 'none',
        public string $tokenParam = 'max_tokens',
        public string $keepAlive = '5m',
        public bool $contextLimitSet = false,
        private bool $responsesStore = false,
        private string $anthropicVersion = '2023-06-01',
        private array $anthropicBeta = [],
        private array $apiKeyFiles = [],
        private array $fallback = [],
        private ?array $llama = null,
        private ?Vault $vault = null,
        public string $effortFormat = 'auto',
        public ?array $effortLevels = null,
        public bool $promptCache = true,
        public string $promptCacheTtl = '5m',
        public ?string $promptCacheRetention = null,
        public string $compactMode = 'auto',
        public ?bool $vision = null,
    ) {
    }

    public function supportsVision(): bool
    {
        if ($this->vision !== null) {
            return $this->vision;
        }
        if (in_array($this->protocol, ['anthropic', 'openai-responses'], true)) {
            return true;
        }
        $host = strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));

        return in_array($host, ['api.openai.com', 'openrouter.ai', 'api.x.ai'], true);
    }

    public function compactsShared(): bool
    {
        return $this->compactMode === 'shared' || ($this->compactMode === 'auto' && !$this->isLocal());
    }

    public function override(?string $baseUrl, ?string $model): self
    {
        return $this->copy(baseUrl: $baseUrl, model: $model);
    }

    public function withModel(string $model): self
    {
        return $this->copy(model: $model);
    }

    public function withProtocol(string $protocol): self
    {
        return $this->copy(protocol: $protocol);
    }

    public function withThinking(string $thinking): self
    {
        $level = self::canonical($thinking);
        if ($level === null) {
            throw new ConfigException("profile {$this->name}: thinking must be " . self::levelsText() . ' (off and on are also accepted)');
        }

        return $this->copy(thinking: $level);
    }

    public static function canonical(string $level): ?string
    {
        if (isset(self::ALIASES[$level])) {
            return self::ALIASES[$level];
        }

        return in_array($level, self::EFFORTS, true) ? $level : null;
    }

    public static function levelsText(): string
    {
        $levels = self::EFFORTS;
        $last = (string) array_pop($levels);

        return implode(', ', $levels) . " or {$last}";
    }

    public function protocol(): string
    {
        return $this->protocol;
    }

    public function responsesStore(): bool
    {
        return $this->responsesStore;
    }

    public function anthropicVersion(): string
    {
        return $this->anthropicVersion;
    }

    public function anthropicBeta(): array
    {
        return $this->anthropicBeta;
    }

    public function apiKeyFiles(): array
    {
        return $this->apiKeyFile === null ? $this->apiKeyFiles : [$this->apiKeyFile, ...$this->apiKeyFiles];
    }

    public function fallback(): array
    {
        return $this->fallback;
    }

    public function llamaBlock(): ?array
    {
        return $this->llama;
    }

    public function unixSocket(): ?string
    {
        return str_starts_with($this->baseUrl, 'unix://') ? rtrim(substr($this->baseUrl, strlen('unix://')), '/') : null;
    }

    public function isLocal(): bool
    {
        return self::localUrl($this->baseUrl);
    }

    public static function localUrl(string $url): bool
    {
        if (str_starts_with($url, 'unix://')) {
            return true;
        }
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === '') {
            return false;
        }
        if ($host === 'localhost' || !str_contains($host, '.') && !str_contains($host, ':')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                || (bool) preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $host);
        }

        return (bool) preg_match('/\.(local|lan|internal|home\.arpa)$/', $host);
    }

    public function url(string $path): string
    {
        $base = (string) preg_replace('~(?<!/)/v1$~', '', rtrim($this->baseUrl, '/'));

        return ($this->unixSocket() === null ? $base : 'http://localhost') . $path;
    }

    public function keyInVault(): bool
    {
        return $this->vault !== null;
    }

    public function hasKeySource(): bool
    {
        return $this->vault !== null || $this->apiKeyEnv !== null || $this->apiKeyFile !== null || $this->apiKeyFiles !== [];
    }

    public function apiKey(): ?string
    {
        if ($this->vault !== null) {
            return $this->vault->get($this->name);
        }
        if ($this->apiKeyEnv !== null) {
            $value = getenv($this->apiKeyEnv);

            return is_string($value) && $value !== '' ? $value : null;
        }
        $files = $this->apiKeyFiles();
        if ($files === []) {
            return null;
        }
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $key = trim($content);

                return $key === '' ? null : $key;
            }
        }

        throw new PhycodeException("profile {$this->name}: cannot read api_key_file " . implode(' or ', $files));
    }

    private function copy(?string $baseUrl = null, ?string $model = null, ?string $thinking = null, ?string $protocol = null): self
    {
        return new self(
            name: $this->name,
            baseUrl: $baseUrl ?? $this->baseUrl,
            model: $model ?? $this->model,
            toolMode: $this->toolMode,
            contextLimit: $this->contextLimit,
            maxOutputTokens: $this->maxOutputTokens,
            sampling: $this->sampling,
            extraBody: $this->extraBody,
            apiKeyFile: $this->apiKeyFile,
            apiKeyEnv: $this->apiKeyEnv,
            protocol: $protocol ?? $this->protocol,
            thinking: $thinking ?? $this->thinking,
            tokenParam: $this->tokenParam,
            keepAlive: $this->keepAlive,
            contextLimitSet: $this->contextLimitSet,
            responsesStore: $this->responsesStore,
            anthropicVersion: $this->anthropicVersion,
            anthropicBeta: $this->anthropicBeta,
            apiKeyFiles: $this->apiKeyFiles,
            fallback: $this->fallback,
            llama: $this->llama,
            vault: $this->vault,
            effortFormat: $this->effortFormat,
            effortLevels: $this->effortLevels,
            promptCache: $this->promptCache,
            promptCacheTtl: $this->promptCacheTtl,
            promptCacheRetention: $this->promptCacheRetention,
            compactMode: $this->compactMode,
            vision: $this->vision,
        );
    }
}
