<?php

declare(strict_types=1);

namespace Phycode\Plugin;

use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Tools\ArgvRunner;

final class Plugins
{
    public const PARTS = ['agents', 'skills', 'prompts'];
    public const MAX_FILES = 1000;
    public const MAX_BYTES = 10485760;
    private const NAME = '/^[a-z0-9][a-z0-9_-]{0,39}$/D';
    private const REMOTE = '~^(?:https://|ssh://|file://|git@)\S+$~D';
    private const CLONE_SECONDS = 120;

    public function __construct(private readonly string $configDir)
    {
    }

    public static function agentFolders(string $configDir): array
    {
        return self::folders($configDir, 'agents');
    }

    public static function skillFolders(string $configDir): array
    {
        return self::folders($configDir, 'skills');
    }

    public static function promptFolders(string $configDir): array
    {
        return self::folders($configDir, 'prompts');
    }

    public function all(): array
    {
        $plugins = [];
        foreach (self::names($this->configDir) as $name) {
            $path = $this->configDir . '/plugins/' . $name;
            $manifest = is_file($path . '/plugin.json') ? Json::object((string) file_get_contents($path . '/plugin.json')) : null;
            $plugins[] = [
                'name' => $name,
                'description' => is_string($manifest['description'] ?? null) ? $manifest['description'] : '',
                'version' => is_string($manifest['version'] ?? null) ? $manifest['version'] : '',
                'parts' => array_values(array_filter(self::PARTS, static fn (string $part): bool => is_dir($path . '/' . $part))),
            ];
        }

        return $plugins;
    }

    public function add(string $source): array
    {
        $staging = $this->configDir . '/plugins/.staging-' . bin2hex(random_bytes(4));
        $this->makeFolder($staging);
        try {
            $label = $this->fetch($source, $staging);
            $manifest = is_file($staging . '/plugin.json') ? Json::object((string) file_get_contents($staging . '/plugin.json')) : null;
            $name = self::slug(is_string($manifest['name'] ?? null) ? $manifest['name'] : $label);
            if (preg_match(self::NAME, $name) !== 1) {
                throw new PhycodeException("the plugin has no usable name: give plugin.json a \"name\" of lowercase letters, digits, hyphens and underscores");
            }
            if (array_filter(self::PARTS, static fn (string $part): bool => is_dir($staging . '/' . $part)) === []) {
                throw new PhycodeException('there is nothing to load: a plugin is a folder with agents/, skills/ or prompts/ in it');
            }
            $target = $this->configDir . '/plugins/' . $name;
            if (file_exists($target)) {
                throw new PhycodeException("the plugin {$name} is installed already: phycode plugin remove {$name} first");
            }
            if (!rename($staging, $target)) {
                throw new PhycodeException("cannot install the plugin in {$target}");
            }
        } catch (\Throwable $error) {
            self::delete($staging);

            throw $error;
        }
        $installed = array_values(array_filter($this->all(), static fn (array $plugin): bool => $plugin['name'] === $name));

        return $installed[0];
    }

    public function remove(string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1 || !is_dir($this->configDir . '/plugins/' . $name)) {
            throw new PhycodeException("no plugin named {$name}; phycode plugin list shows them");
        }
        self::delete($this->configDir . '/plugins/' . $name);
    }

    private static function names(string $configDir): array
    {
        $folder = $configDir . '/plugins';
        $names = [];
        foreach (is_dir($folder) ? (scandir($folder) ?: []) : [] as $name) {
            if (preg_match(self::NAME, $name) === 1 && is_dir($folder . '/' . $name) && !is_link($folder . '/' . $name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function folders(string $configDir, string $part): array
    {
        $folders = [];
        foreach (self::names($configDir) as $name) {
            $folder = $configDir . '/plugins/' . $name . '/' . $part;
            if (is_dir($folder) && !is_link($folder)) {
                $folders[$name] = $folder;
            }
        }

        return $folders;
    }

    private static function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower($text)), '-');
    }

    private function makeFolder(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new PhycodeException("cannot create {$path}");
        }
    }

    private function fetch(string $source, string $into): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $source) !== 1 && is_dir($source)) {
            $real = realpath($source) ?: $source;
            $count = 0;
            $bytes = 0;
            self::copy($real, $into, $count, $bytes);

            return basename($real);
        }
        if (preg_match(self::REMOTE, $source) !== 1) {
            throw new PhycodeException("'{$source}' is neither a folder nor a git address (https://, ssh://, file:// or git@host:path)");
        }
        $clone = $into . '.clone';
        try {
            $result = ArgvRunner::run(['git', '-c', 'core.hooksPath=/dev/null', 'clone', '--depth', '1', '--quiet', '--', $source, $clone], $this->configDir, self::CLONE_SECONDS, 65536);
            if (!$result->succeeded()) {
                $why = trim(strtok(trim($result->stderr) . "\n", "\n") ?: '');

                throw new PhycodeException('git clone failed' . ($why === '' ? '' : ": {$why}"));
            }
            $count = 0;
            $bytes = 0;
            self::copy($clone, $into, $count, $bytes);
        } finally {
            self::delete($clone);
        }

        return basename(rtrim((string) preg_replace('/\.git$/', '', $source), '/'));
    }

    private static function copy(string $from, string $to, int &$count, int &$bytes): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.git') {
                continue;
            }
            $source = $from . '/' . $entry;
            if (is_link($source)) {
                throw new PhycodeException("a plugin cannot contain symbolic links: {$source}");
            }
            if (is_dir($source)) {
                mkdir($to . '/' . $entry, 0700);
                self::copy($source, $to . '/' . $entry, $count, $bytes);

                continue;
            }
            $count++;
            $bytes += (int) filesize($source);
            if ($count > self::MAX_FILES || $bytes > self::MAX_BYTES) {
                throw new PhycodeException('a plugin has at most ' . self::MAX_FILES . ' files and ' . intdiv(self::MAX_BYTES, 1048576) . ' MB');
            }
            copy($source, $to . '/' . $entry);
            chmod($to . '/' . $entry, (fileperms($source) & 0100) !== 0 ? 0700 : 0600);
        }
    }

    private static function delete(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::delete($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
