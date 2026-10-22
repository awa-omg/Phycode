<?php

declare(strict_types=1);

namespace Phycode\Lsp;

use Phycode\Config\ConfigException;

final readonly class LspServerSpec
{
    private const NAME = '/^[A-Za-z0-9_-]{1,48}$/D';
    private const KEYS = ['command', 'extensions', 'root_markers', 'initialization_options'];
    private const EXTENSION = '/^\.?[A-Za-z0-9_+-]{1,16}$/D';
    private const MARKER = '/^[^\/\0]{1,255}$/D';

    public function __construct(
        public string $name,
        public array $command,
        public array $extensions = [],
        public array $rootMarkers = [],
        public array $initializationOptions = [],
    ) {
    }

    public static function fromArray(string $name, array $raw): self
    {
        $label = "lsp.{$name}";
        if (preg_match(self::NAME, $name) !== 1) {
            throw new ConfigException("lsp name '{$name}' may use only letters, digits, '-' and '_' (48 characters at most)");
        }
        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, self::KEYS, true)) {
                throw new ConfigException("{$label}.{$key} is not a known setting");
            }
        }
        $command = $raw['command'] ?? null;
        if (!is_array($command) || !array_is_list($command) || $command === [] || count($command) > 32) {
            throw new ConfigException("{$label}.command must be a list of 1 to 32 strings, the program first");
        }
        foreach ($command as $part) {
            if (!is_string($part) || trim($part) === '' || str_contains($part, "\0") || strlen($part) > 4096) {
                throw new ConfigException("{$label}.command must be a list of non-empty strings without NUL bytes");
            }
        }
        $options = $raw['initialization_options'] ?? [];
        if (!is_array($options) || ($options !== [] && array_is_list($options))) {
            throw new ConfigException("{$label}.initialization_options must be an object");
        }

        return new self(
            $name,
            $command,
            array_map(static fn (string $extension): string => strtolower(ltrim($extension, '.')), self::names($raw['extensions'] ?? [], "{$label}.extensions", self::EXTENSION, 'file extensions such as "php"')),
            self::names($raw['root_markers'] ?? [], "{$label}.root_markers", self::MARKER, 'file names such as "composer.json"'),
            $options,
        );
    }

    public function handles(string $path): bool
    {
        return $this->extensions === [] || in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $this->extensions, true);
    }

    private static function names(mixed $value, string $label, string $pattern, string $expected): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 32) {
            throw new ConfigException("{$label} must be a list of up to 32 {$expected}");
        }
        foreach ($value as $item) {
            if (!is_string($item) || preg_match($pattern, $item) !== 1) {
                throw new ConfigException("{$label} must be a list of up to 32 {$expected}");
            }
        }

        return $value;
    }
}
