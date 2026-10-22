<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Browser;

use Phycode\Tests\Support\Kit;

final class FakeBrowser
{
    public static function installed(): ?string
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return null;
        }
        foreach (\Phycode\Browser\BrowserSettings::MACOS_APPLICATIONS as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFUlEQVR4nGP8z8Dwn4EIwESMolGFSAAAXfkCCSrSSVQAAAAASUVORK5CYII=';
    public const JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/wAALCAACAAIBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAB//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKf/Z';

    public readonly string $folder;
    public readonly string $binary;

    public function __construct(array $scenario = [])
    {
        $this->folder = Kit::folder('fake-browser');
        $this->scenario($scenario + ['png' => self::PNG, 'jpeg' => self::JPEG]);
        $this->binary = $this->folder . '/chromium';
        file_put_contents($this->binary, "#!/bin/sh\nPHYCODE_FAKE_BROWSER=" . escapeshellarg($this->folder) . ' exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/Support/fake-browser.php') . " \"\$@\"\n");
        chmod($this->binary, 0755);
    }

    public function scenario(array $scenario): void
    {
        file_put_contents($this->folder . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR));
    }

    public function commands(): array
    {
        return $this->lines('commands.jsonl');
    }

    public function methods(?string $session = null): array
    {
        $commands = $session === null ? $this->commands() : array_filter($this->commands(), static fn (array $command): bool => ($command['sessionId'] ?? null) === $session);

        return array_values(array_map(static fn (array $command): string => (string) $command['method'], $commands));
    }

    public function sent(string $method): array
    {
        return array_values(array_filter($this->commands(), static fn (array $command): bool => ($command['method'] ?? '') === $method));
    }

    public function network(): array
    {
        return array_column($this->lines('network.jsonl'), 'url');
    }

    public function typed(): array
    {
        return array_column($this->lines('typed.jsonl'), 'text');
    }

    public function dialogs(): array
    {
        return $this->lines('dialogs.jsonl');
    }

    public function http(): array
    {
        return $this->lines('http.jsonl');
    }

    public function launches(): array
    {
        return $this->lines('argv.jsonl');
    }

    public function alive(int $pid): bool
    {
        clearstatcache();
        if (is_readable('/proc/self/stat')) {
            return is_dir('/proc/' . $pid) && !str_contains((string) @file_get_contents('/proc/' . $pid . '/stat'), ') Z');
        }
        $state = trim((string) shell_exec('ps -o stat= -p ' . $pid . ' 2>/dev/null'));

        return $state !== '' && !str_starts_with($state, 'Z');
    }

    public function stop(): void
    {
        foreach ($this->launches() as $launch) {
            if ($this->alive($launch['pid'])) {
                posix_kill($launch['pid'], SIGKILL);
            }
        }
    }

    private function lines(string $file): array
    {
        $path = $this->folder . '/' . $file;
        if (!is_file($path)) {
            return [];
        }

        return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) file_get_contents($path)))));
    }
}
