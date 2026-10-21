<?php

declare(strict_types=1);

namespace Phycode\Deploy;

use Phycode\Config\ConfigException;
use Phycode\Deploy\Drivers\Drivers;

final readonly class DeployTarget
{
    public const NAME = '/^[a-z0-9][a-z0-9_-]{0,31}$/D';
    public const KINDS = ['docker', 'podman', 'vercel', 'cloudflare', 'modal', 'ssh', 'custom'];
    public const COMMON = ['kind', 'environment', 'dir', 'secrets', 'vars', 'health_url', 'timeout', 'confirm'];
    public const CONFIRM = ['always', 'rules'];
    public const RESERVED = ['list', 'add', 'remove', 'secret', 'secrets', 'plan', 'history'];
    public const MAX_TIMEOUT = 3600;

    public function __construct(
        public string $name,
        public string $kind,
        public string $environment,
        public string $dir,
        public array $secrets,
        public array $vars,
        public ?string $healthUrl,
        public int $timeout,
        public string $confirm,
        public array $options,
    ) {
    }

    public static function fromArray(string $name, array $raw): self
    {
        $label = "deploy.targets.{$name}";
        if (preg_match(self::NAME, $name) !== 1 || in_array($name, self::RESERVED, true)) {
            throw new ConfigException("deploy.targets name '{$name}' must be lowercase letters, digits, '-' or '_', and start with a letter or digit (32 characters at most)");
        }
        $kind = Check::choice($raw['kind'] ?? null, "{$label}.kind", self::KINDS);
        $driver = Drivers::for($kind);
        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, [...self::COMMON, ...$driver->keys()], true)) {
                throw new ConfigException("{$label}.{$key} is not a known setting of a {$kind} target");
            }
        }
        $timeout = $raw['timeout'] ?? 900;
        if (!is_int($timeout) || $timeout < 1 || $timeout > self::MAX_TIMEOUT) {
            throw new ConfigException("{$label}.timeout must be a number of seconds from 1 to " . self::MAX_TIMEOUT);
        }
        $health = $raw['health_url'] ?? null;
        if ($health !== null && (!is_string($health) || preg_match('~^https?://[^\s/@?#]+(/[^\s?#]*)?(\?[^\s#]*)?$~D', $health) !== 1)) {
            throw new ConfigException("{$label}.health_url must be an http:// or https:// URL without a user name");
        }

        return new self(
            $name,
            $kind,
            Check::word($raw['environment'] ?? 'production', "{$label}.environment", '/^[a-z0-9][a-z0-9_-]{0,31}$/D'),
            ($raw['dir'] ?? '.') === '.' ? '.' : Check::relativePath($raw['dir'], "{$label}.dir"),
            array_values(array_unique(Check::words($raw['secrets'] ?? null, "{$label}.secrets", 16, Check::VARIABLE))),
            self::vars($raw['vars'] ?? null, "{$label}.vars"),
            $health,
            $timeout,
            Check::choice($raw['confirm'] ?? 'always', "{$label}.confirm", self::CONFIRM),
            $driver->options(array_intersect_key($raw, array_flip($driver->keys())), $label),
        );
    }

    public function toArray(): array
    {
        $data = ['kind' => $this->kind];
        foreach ([
            'environment' => $this->environment === 'production' ? null : $this->environment,
            'dir' => $this->dir === '.' ? null : $this->dir,
            'secrets' => $this->secrets === [] ? null : $this->secrets,
            'vars' => $this->vars === [] ? null : $this->vars,
            'health_url' => $this->healthUrl,
            'timeout' => $this->timeout === 900 ? null : $this->timeout,
            'confirm' => $this->confirm === 'always' ? null : $this->confirm,
        ] as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $data + array_filter($this->options, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function production(): bool
    {
        return $this->environment === 'production';
    }

    public function forcesApproval(): bool
    {
        return $this->production() || $this->confirm === 'always';
    }

    private static function vars(mixed $value, string $label): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > 32) {
            throw new ConfigException("{$label} must be an object of at most 32 variables");
        }
        foreach ($value as $name => $text) {
            if (preg_match(Check::VARIABLE, (string) $name) !== 1) {
                throw new ConfigException("{$label}.{$name} must be a variable name in capitals");
            }
            if (!is_string($text) || strlen($text) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
                throw new ConfigException("{$label}.{$name} must be one line of text of at most 1024 bytes");
            }
        }

        return $value;
    }
}
