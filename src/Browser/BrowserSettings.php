<?php

declare(strict_types=1);

namespace Phycode\Browser;

use Phycode\Config\ConfigException;
use Phycode\Exec\ProcessRunner;

final readonly class BrowserSettings
{
    public const DEFAULT_HOSTS = ['localhost', '127.0.0.1'];
    public const BINARY_NAMES = ['chromium', 'chromium-browser', 'google-chrome', 'google-chrome-stable', 'chrome'];
    public const MACOS_APPLICATIONS = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
    ];

    private const KEYS = ['enabled', 'command', 'allow_hosts', 'max_pages', 'no_sandbox'];
    private const HOST = '/^(\*\.)?[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*$/D';

    public function __construct(
        public bool $enabled = false,
        public ?string $command = null,
        public array $allowHosts = self::DEFAULT_HOSTS,
        public int $maxPages = 3,
        public bool $noSandbox = false,
    ) {
    }

    public static function fromRaw(mixed $raw): self
    {
        if ($raw === null) {
            return new self();
        }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new ConfigException('browser must be an object');
        }
        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, self::KEYS, true)) {
                throw new ConfigException("browser.{$key} is not a known setting");
            }
        }
        foreach (['enabled', 'no_sandbox'] as $flag) {
            if (isset($raw[$flag]) && !is_bool($raw[$flag])) {
                throw new ConfigException("browser.{$flag} must be true or false");
            }
        }
        $command = $raw['command'] ?? null;
        if ($command !== null && (!is_string($command) || trim($command) === '' || str_contains($command, "\0"))) {
            throw new ConfigException('browser.command must be the path of a Chrome or Chromium binary, or its name on the PATH');
        }
        $pages = $raw['max_pages'] ?? 3;
        if (!is_int($pages) || $pages < 1 || $pages > 10) {
            throw new ConfigException('browser.max_pages must be an integer from 1 to 10');
        }
        $hosts = $raw['allow_hosts'] ?? self::DEFAULT_HOSTS;
        if (!is_array($hosts) || !array_is_list($hosts)) {
            throw new ConfigException('browser.allow_hosts must be a list of host names or addresses');
        }
        foreach ($hosts as $host) {
            if (!is_string($host) || (preg_match(self::HOST, $host) !== 1 && filter_var($host, FILTER_VALIDATE_IP) === false)) {
                throw new ConfigException('browser.allow_hosts must be a list of host names such as localhost or *.test, or IP addresses');
            }
        }

        return new self($raw['enabled'] ?? false, $command === null ? null : trim($command), array_map('strtolower', $hosts), $pages, $raw['no_sandbox'] ?? false);
    }

    public function binary(): ?string
    {
        if ($this->command !== null) {
            if (str_contains($this->command, '/')) {
                return is_file($this->command) && is_executable($this->command) ? $this->command : null;
            }

            return ProcessRunner::which($this->command);
        }
        foreach (self::BINARY_NAMES as $name) {
            $found = ProcessRunner::which($name);
            if ($found !== null) {
                return $found;
            }
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            foreach ([...self::MACOS_APPLICATIONS, ...array_map(static fn (string $path): string => (string) getenv('HOME') . $path, self::MACOS_APPLICATIONS)] as $path) {
                if (is_file($path) && is_executable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }
}
