<?php

declare(strict_types=1);

namespace Phycode\Web;

use Phycode\Config\SearchPresets;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;

final class SearchKeys
{
    public static function read(array $provider, ?Vault $vault): string
    {
        if (($provider['api_key_store'] ?? false) === true) {
            return self::fromVault((string) ($provider['name'] ?? ''), $vault);
        }

        return self::fromFile($provider['api_key_file']);
    }

    public static function status(string $name, array $provider, Vault $vault): string
    {
        if (!self::needsKey($provider['backend'])) {
            return 'none';
        }
        if ($provider['api_key_store']) {
            return $vault->hasSearchKey($name) ? 'stored' : 'missing';
        }

        return $provider['api_key_file'] !== null && is_file($provider['api_key_file']) && is_readable($provider['api_key_file']) ? 'file' : 'missing';
    }

    public static function secrets(array $web, Vault $vault): array
    {
        $entries = [];
        foreach ($web['providers'] as $name => $provider) {
            $entries[] = ['name' => (string) $name] + $provider;
        }
        if ($web['search']['name'] === null) {
            $entries[] = $web['search'];
        }

        $secrets = [];
        foreach ($entries as $provider) {
            if (!self::needsKey((string) $provider['backend'])) {
                continue;
            }
            try {
                $secrets[] = self::read($provider, $vault);
            } catch (WebException) {
                continue;
            }
        }

        return $secrets;
    }

    private static function needsKey(string $backend): bool
    {
        return (SearchPresets::of($backend) ?? ['keyRequired' => false])['keyRequired'];
    }

    private static function fromVault(string $name, ?Vault $vault): string
    {
        if ($vault === null) {
            throw new WebException('the search key is kept in the vault, which is not open');
        }
        try {
            $key = $vault->searchKey($name);
        } catch (PhycodeException $error) {
            throw new WebException($error->getMessage());
        }
        if ($key === null) {
            throw new WebException("no key is stored for the search provider {$name}: add the provider with its key");
        }

        return $key;
    }

    private static function fromFile(?string $path): string
    {
        if ($path === null) {
            throw new WebException('the search backend needs web.search.api_key_file');
        }
        $key = is_file($path) && is_readable($path) ? trim((string) file_get_contents($path)) : null;
        if ($key === null) {
            throw new WebException("the search key file cannot be read: {$path}");
        }
        if ($key === '') {
            throw new WebException("the search key file is empty: {$path}");
        }

        return $key;
    }
}
