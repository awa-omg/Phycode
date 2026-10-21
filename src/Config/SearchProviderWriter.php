<?php

declare(strict_types=1);

namespace Phycode\Config;

use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;

final class SearchProviderWriter
{
    private const NAME = '/^[a-z0-9][a-z0-9_-]{0,31}$/D';

    public static function add(string $file, string $name, string $backend, ?string $url, ?string $apiKey, bool $choose = false): void
    {
        self::checkName($name);
        $preset = self::preset($backend);
        $url = self::trimmed($url);
        $key = self::trimmed($apiKey);
        if ($preset['keyRequired'] && $key === null) {
            throw new PhycodeException("{$backend} needs its API key");
        }
        if (!$preset['keyRequired'] && $key !== null) {
            throw new PhycodeException("{$backend} takes no API key");
        }
        if ($preset['urlRequired'] && $url === null) {
            throw new PhycodeException("{$backend} needs the URL of its instance");
        }

        [$content, $data] = self::load($file);
        $providers = self::providers($data);
        if (property_exists($providers, $name)) {
            throw new PhycodeException("search provider '{$name}' already exists in {$file}");
        }
        $entry = new \stdClass();
        $entry->backend = $backend;
        if ($url !== null) {
            $entry->url = $url;
        }
        if ($key !== null) {
            $entry->api_key_store = true;
        }
        $providers->{$name} = $entry;
        if ($choose) {
            self::searchObject($data)->active = $name;
        }
        self::validate($data, $file);
        if ($key === null) {
            ConfigWriter::save($file, $content, $data);

            return;
        }
        $vault = new Vault(dirname($file));
        $vault->storeSearchKey($name, $key);
        try {
            ConfigWriter::save($file, $content, $data);
        } catch (PhycodeException $error) {
            self::forget($vault, $name);

            throw $error;
        }
    }

    public static function update(string $file, string $name, ?string $url, ?string $apiKey): void
    {
        $url = self::trimmed($url);
        $key = self::trimmed($apiKey);
        [$content, $data] = self::load($file);
        $entry = self::entry($file, $data, $name);
        $backend = (string) ($entry->backend ?? '');
        $preset = self::preset($backend);
        if ($key !== null && !$preset['keyRequired']) {
            throw new PhycodeException("{$backend} takes no API key");
        }
        if ($key !== null && ($entry->api_key_store ?? false) !== true) {
            throw new PhycodeException("search provider '{$name}' reads its key from a file: change the file, or add the provider again with a key");
        }
        if ($url !== null) {
            $entry->url = $url;
            self::validate($data, $file);
        }
        if ($key === null && $url === null) {
            return;
        }

        $vault = new Vault(dirname($file));
        $previous = self::previousKey($vault, $name);
        if ($key !== null) {
            $vault->storeSearchKey($name, $key);
        }
        if ($url === null) {
            return;
        }
        try {
            ConfigWriter::save($file, $content, $data);
        } catch (PhycodeException $error) {
            $previous === null ? self::forget($vault, $name) : self::restore($vault, $name, $previous);

            throw $error;
        }
    }

    public static function remove(string $file, string $name): void
    {
        [$content, $data] = self::load($file);
        $entry = self::entry($file, $data, $name);
        if ((self::searchObject($data)->active ?? null) === $name) {
            throw new PhycodeException("search provider '{$name}' is the one in use: choose another one, or no search, first");
        }
        $stored = ($entry->api_key_store ?? false) === true;
        $providers = self::providers($data);
        unset($providers->{$name});
        self::validate($data, $file);
        ConfigWriter::save($file, $content, $data);
        if ($stored) {
            try {
                (new Vault(dirname($file)))->removeSearchKey($name);
            } catch (PhycodeException) {
            }
        }
    }

    public static function setActive(string $file, ?string $name): void
    {
        [$content, $data] = self::load($file);
        $search = self::searchObject($data);
        if ($name !== null) {
            $entry = self::entry($file, $data, $name);
            if (($entry->api_key_store ?? false) === true && !(new Vault(dirname($file)))->hasSearchKey($name)) {
                throw new PhycodeException("no key is stored for search provider '{$name}': add the provider with its key first");
            }
        }
        $search->active = $name;
        self::validate($data, $file);
        ConfigWriter::save($file, $content, $data);
    }

    private static function load(string $file): array
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            throw new PhycodeException("cannot read {$file}");
        }
        try {
            $data = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new PhycodeException("{$file} is not valid JSON: " . $error->getMessage());
        }
        if (!$data instanceof \stdClass) {
            throw new PhycodeException("{$file} must contain a JSON object");
        }

        return [$content, $data];
    }

    private static function searchObject(\stdClass $data): \stdClass
    {
        $web = self::child($data, 'web', 'web');
        $search = self::child($web, 'search', 'web.search');

        return $search;
    }

    private static function providers(\stdClass $data): \stdClass
    {
        $search = self::searchObject($data);

        return self::child($search, 'providers', 'web.search.providers');
    }

    private static function entry(string $file, \stdClass $data, string $name): \stdClass
    {
        $providers = self::providers($data);
        $entry = property_exists($providers, $name) ? $providers->{$name} : null;
        if (!$entry instanceof \stdClass) {
            throw new PhycodeException("no search provider named '{$name}' is in {$file}");
        }

        return $entry;
    }

    private static function child(\stdClass $parent, string $key, string $label): \stdClass
    {
        $child = $parent->{$key} ?? new \stdClass();
        if (!$child instanceof \stdClass) {
            throw new PhycodeException("{$label} in the config must be an object");
        }
        $parent->{$key} = $child;

        return $child;
    }

    private static function preset(string $backend): array
    {
        $preset = $backend === 'none' ? null : SearchPresets::of($backend);
        if ($preset === null) {
            throw new PhycodeException('the search backend must be ' . implode(', ', array_column(SearchPresets::all(), 'backend')));
        }

        return $preset;
    }

    private static function checkName(string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new PhycodeException('a search provider name is 1 to 32 characters: lowercase letters, digits, - and _, starting with a letter or a digit');
        }
    }

    private static function trimmed(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    private static function previousKey(Vault $vault, string $name): ?string
    {
        try {
            return $vault->searchKey($name);
        } catch (PhycodeException) {
            return null;
        }
    }

    private static function forget(Vault $vault, string $name): void
    {
        try {
            $vault->removeSearchKey($name);
        } catch (PhycodeException) {
        }
    }

    private static function restore(Vault $vault, string $name, string $key): void
    {
        try {
            $vault->storeSearchKey($name, $key);
        } catch (PhycodeException) {
        }
    }

    private static function validate(\stdClass $data, string $file): void
    {
        $global = json_decode(Json::encode($data), true, 512, JSON_THROW_ON_ERROR);
        $profiles = $global['profiles'] ?? [];
        $environment = is_array($profiles) && $profiles !== [] ? [] : ['PHYCODE_BASE_URL' => 'http://127.0.0.1:8082', 'PHYCODE_MODEL' => 'check'];
        try {
            Config::fromArrays($global, null, $environment, dirname($file));
        } catch (PhycodeException $error) {
            throw new PhycodeException('the change is refused: ' . $error->getMessage());
        }
    }
}
