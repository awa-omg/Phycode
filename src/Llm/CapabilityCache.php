<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Config\Profile;
use Phycode\Support\Files;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class CapabilityCache
{
    public function __construct(private readonly string $file)
    {
    }

    public static function key(Profile $profile): string
    {
        return sha1($profile->protocol . '|' . $profile->baseUrl . '|' . $profile->model);
    }

    public function get(string $key): ?array
    {
        $entry = $this->read()[$key] ?? null;

        return is_array($entry) && is_string($entry['mode'] ?? null)
            ? ['mode' => $entry['mode'], 'error' => is_string($entry['error'] ?? null) ? $entry['error'] : null]
            : null;
    }

    public function route(string $key): ?string
    {
        return ($this->entry($key)['route'] ?? null) === 'responses' ? 'responses' : null;
    }

    public function capabilities(string $key): ?array
    {
        $kept = $this->entry($key)['capabilities'] ?? null;
        if (!is_array($kept) || !is_array($kept['effort'] ?? null) || !is_array($kept['thinking'] ?? null)) {
            return null;
        }

        return [
            'effort' => array_values(array_filter($kept['effort'], static fn (mixed $level): bool => is_string($level))),
            'thinking' => array_values(array_filter($kept['thinking'], static fn (mixed $type): bool => is_string($type))),
        ];
    }

    public function levels(string $key, string $model): ?array
    {
        $kept = $this->entry($key)['levels'][$model] ?? null;
        if (!is_array($kept) || !is_array($kept['effort'] ?? null)) {
            return null;
        }

        return [
            'effort' => array_values(array_filter($kept['effort'], static fn (mixed $level): bool => is_string($level))),
            'mandatory' => ($kept['mandatory'] ?? false) === true,
        ];
    }

    public function putLevels(string $key, array $levels): void
    {
        $this->merge($key, ['levels' => $levels]);
    }

    public function put(string $key, string $mode, ?string $error): void
    {
        $this->merge($key, ['mode' => $mode, 'error' => $error, 'checked' => date('c')]);
    }

    public function merge(string $key, array $fields): void
    {
        $data = $this->read();
        $data[$key] = array_merge($this->entry($key), $fields);
        $this->write($data);
    }

    private function entry(string $key): array
    {
        $entry = $this->read()[$key] ?? null;

        return is_array($entry) ? $entry : [];
    }

    private function write(array $data): void
    {
        if (is_link($this->file)) {
            return;
        }
        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        try {
            Files::writeAtomic($this->file, Json::encode($data, true), 0600);
        } catch (PhycodeException) {
        }
    }

    private function read(): array
    {
        if (!is_file($this->file) || is_link($this->file)) {
            return [];
        }

        return Json::object((string) file_get_contents($this->file)) ?? [];
    }
}
