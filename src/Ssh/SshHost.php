<?php

declare(strict_types=1);

namespace Phycode\Ssh;

use Phycode\Config\ConfigException;

final readonly class SshHost
{
    public const NAME = '/^[a-z0-9][a-z0-9_-]{0,31}$/D';
    public const KEYS = ['destination', 'port', 'identity_file', 'cwd', 'confirm', 'host_key', 'known_hosts', 'config_file'];
    public const CONFIRM = ['always', 'rules'];
    public const HOST_KEY = ['strict', 'accept-new'];

    public function __construct(
        public string $name,
        public string $destination,
        public ?int $port = null,
        public ?string $identityFile = null,
        public ?string $cwd = null,
        public string $confirm = 'always',
        public string $hostKey = 'strict',
        public ?string $knownHosts = null,
        public ?string $configFile = null,
    ) {
    }

    public static function fromArray(string $name, array $raw): self
    {
        $label = "ssh.hosts.{$name}";
        if (preg_match(self::NAME, $name) !== 1) {
            throw new ConfigException("ssh.hosts name '{$name}' must be lowercase letters, digits, '-' or '_', and start with a letter or digit (32 characters at most)");
        }
        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, self::KEYS, true)) {
                throw new ConfigException("{$label}.{$key} is not a known setting");
            }
        }
        $port = $raw['port'] ?? null;
        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65535)) {
            throw new ConfigException("{$label}.port must be an integer from 1 to 65535");
        }

        return new self(
            $name,
            self::destination($raw['destination'] ?? null, $label),
            $port,
            self::path($raw['identity_file'] ?? null, "{$label}.identity_file"),
            self::remotePath($raw['cwd'] ?? null, "{$label}.cwd"),
            self::choice($raw['confirm'] ?? 'always', "{$label}.confirm", self::CONFIRM),
            self::choice($raw['host_key'] ?? 'strict', "{$label}.host_key", self::HOST_KEY),
            self::path($raw['known_hosts'] ?? null, "{$label}.known_hosts"),
            self::path($raw['config_file'] ?? null, "{$label}.config_file"),
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'destination' => $this->destination,
            'port' => $this->port,
            'identity_file' => $this->identityFile,
            'cwd' => $this->cwd,
            'confirm' => $this->confirm === 'always' ? null : $this->confirm,
            'host_key' => $this->hostKey === 'strict' ? null : $this->hostKey,
            'known_hosts' => $this->knownHosts,
            'config_file' => $this->configFile,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function hostname(): string
    {
        $at = strrpos($this->destination, '@');

        return trim($at === false ? $this->destination : substr($this->destination, $at + 1), '[]');
    }

    public static function expand(string $path): string
    {
        $home = getenv('HOME');

        return str_starts_with($path, '~/') && is_string($home) && $home !== '' ? rtrim($home, '/') . substr($path, 1) : $path;
    }

    private static function destination(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^(?:[A-Za-z0-9_][A-Za-z0-9._-]{0,63}@)?[A-Za-z0-9_\[][A-Za-z0-9._:%\]-]{0,252}$/D', $value) !== 1) {
            throw new ConfigException("{$label}.destination must be host or user@host, with letters, digits and . _ - only, not starting with a dash");
        }

        return $value;
    }

    private static function path(mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '' || strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ConfigException("{$label} must be a path on one line");
        }
        $path = self::expand(trim($value));
        if (!str_starts_with($path, '/')) {
            throw new ConfigException("{$label} must be an absolute path, or start with ~/");
        }

        return $path;
    }

    public static function remotePath(mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '' || strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ConfigException("{$label} must be a path on one line");
        }
        $path = trim($value);
        if (!str_starts_with($path, '/') && $path !== '~' && !str_starts_with($path, '~/')) {
            throw new ConfigException("{$label} must be an absolute path on the host, or start with ~/");
        }

        return $path;
    }

    private static function choice(mixed $value, string $label, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ConfigException("{$label} must be " . implode(' or ', $allowed));
        }

        return $value;
    }
}
