<?php

declare(strict_types=1);

namespace Phycode\Config;

use Phycode\Support\Files;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;

final class ConfigWriter
{
    public static function setProfileModel(string $file, string $profile, string $model, ?string $thinking = null): void
    {
        $model = trim($model);
        if ($model === '') {
            throw new PhycodeException('the model name cannot be empty');
        }
        if ($thinking !== null && !in_array($thinking, Profile::THINKING, true)) {
            throw new PhycodeException('thinking must be ' . Profile::levelsText() . ' (off and on are also accepted)');
        }
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
        $profiles = $data->profiles ?? null;
        $entry = $profiles instanceof \stdClass ? ($profiles->{$profile} ?? null) : null;
        if (!$entry instanceof \stdClass) {
            throw new PhycodeException("profile '{$profile}' is not defined in {$file}");
        }

        $entry->model = $model;
        if ($thinking !== null) {
            $entry->thinking = $thinking;
        }
        self::save($file, $content, $data);
    }

    public static function setProfileKey(string $file, string $profile, mixed $apiKey): void
    {
        $key = self::apiKey($apiKey) ?? throw new PhycodeException('no API key was given');
        [$content, $data, $entry] = self::loadProfile($file, $profile);
        foreach (['api_key_file', 'api_key_files'] as $source) {
            if (property_exists($entry, $source)) {
                throw new PhycodeException("profile '{$profile}' reads its key from {$source}: change that in the config, or take it out first");
            }
        }
        unset($entry->api_key_env);
        $entry->api_key_store = true;

        $vault = new Vault(dirname($file));
        try {
            $previous = $vault->get($profile);
        } catch (PhycodeException) {
            $previous = null;
        }
        $vault->store($profile, $key);
        try {
            self::save($file, $content, $data);
        } catch (PhycodeException $error) {
            try {
                $previous === null ? $vault->remove($profile) : $vault->store($profile, $previous);
            } catch (PhycodeException) {
            }

            throw $error;
        }
    }

    public static function removeProfile(string $file, string $profile): void
    {
        [$content, $data] = self::loadProfile($file, $profile);
        if (($data->default_profile ?? null) === $profile) {
            throw new PhycodeException("profile '{$profile}' is the default_profile: make another profile the default first");
        }
        foreach ($data->profiles as $name => $other) {
            if ($name !== $profile && $other instanceof \stdClass && is_array($other->fallback ?? null) && in_array($profile, $other->fallback, true)) {
                throw new PhycodeException("profile '{$name}' falls back to '{$profile}': take it out of its fallback first");
            }
        }
        unset($data->profiles->{$profile});
        self::save($file, $content, $data);
        try {
            (new Vault(dirname($file)))->remove($profile);
        } catch (PhycodeException) {
        }
    }

    private static function loadProfile(string $file, string $profile): array
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
        $profiles = $data->profiles ?? null;
        $entry = $profiles instanceof \stdClass ? ($profiles->{$profile} ?? null) : null;
        if (!$entry instanceof \stdClass) {
            throw new PhycodeException("profile '{$profile}' is not defined in {$file}");
        }

        return [$content, $data, $entry];
    }

    public static function setContextMax(string $file, ?int $max): void
    {
        if ($max !== null && ($max < Config::CONTEXT_MIN_TOKENS || $max > Config::CONTEXT_MAX_TOKENS)) {
            throw new PhycodeException('the context max must be off or a number of tokens from ' . Config::CONTEXT_MIN_TOKENS . ' to ' . Config::CONTEXT_MAX_TOKENS);
        }
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
        $context = $data->context ?? new \stdClass();
        if (!$context instanceof \stdClass) {
            throw new PhycodeException("context in {$file} must be an object");
        }
        $context->max = $max;
        $data->context = $context;
        self::save($file, $content, $data);
    }

    public static function addProfile(string $file, string $name, array $profile): void
    {
        $values = self::validProfile($name, $profile);
        if (is_link($file)) {
            throw new PhycodeException("cannot change {$file}: it is a symbolic link");
        }
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
        $profiles = $data->profiles ?? new \stdClass();
        if (!$profiles instanceof \stdClass) {
            throw new PhycodeException("profiles in {$file} must be an object");
        }
        if (property_exists($profiles, $name)) {
            throw new PhycodeException("profile '{$name}' already exists in {$file}");
        }

        $entry = new \stdClass();
        $entry->base_url = $values['base_url'];
        $entry->protocol = $values['protocol'];
        if ($values['model'] !== '') {
            $entry->model = $values['model'];
        }
        if ($values['api_key_env'] !== null) {
            $entry->api_key_env = $values['api_key_env'];
        }
        if ($values['api_key'] !== null) {
            $entry->api_key_store = true;
        }
        $profiles->{$name} = $entry;
        $data->profiles = $profiles;
        if ($values['api_key'] === null) {
            self::save($file, $content, $data);

            return;
        }
        $vault = new Vault(dirname($file));
        $vault->store($name, $values['api_key']);
        try {
            self::save($file, $content, $data);
        } catch (PhycodeException $error) {
            try {
                $vault->remove($name);
            } catch (PhycodeException) {
            }

            throw $error;
        }
    }

    private static function validProfile(string $name, array $profile): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $name) !== 1) {
            throw new PhycodeException('a profile name is 1 to 32 characters: lowercase letters, digits, - and _, starting with a letter or a digit');
        }
        foreach (array_keys($profile) as $key) {
            if (in_array($key, ['api_key_file', 'api_key_files'], true)) {
                throw new PhycodeException("{$key} cannot be saved in the config: name an environment variable in api_key_env instead, or give the key as api_key");
            }
            if (!in_array($key, ['protocol', 'base_url', 'api_key_env', 'api_key', 'model'], true)) {
                throw new PhycodeException("'{$key}' is not a setting a profile can be added with");
            }
        }
        $protocol = $profile['protocol'] ?? null;
        if (!is_string($protocol) || !in_array($protocol, Profile::PROTOCOLS, true)) {
            throw new PhycodeException('protocol must be ' . self::choices(Profile::PROTOCOLS));
        }
        $baseUrl = is_string($profile['base_url'] ?? null) ? trim($profile['base_url']) : '';
        self::checkBaseUrl($baseUrl);
        $apiKeyEnv = $profile['api_key_env'] ?? null;
        if ($apiKeyEnv !== null && (!is_string($apiKeyEnv) || preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $apiKeyEnv) !== 1)) {
            throw new PhycodeException('api_key_env must be the name of an environment variable: uppercase letters, digits and _, 2 to 64 characters');
        }
        $model = is_string($profile['model'] ?? null) ? trim($profile['model']) : '';
        if (mb_strlen($model) > 160) {
            throw new PhycodeException('the model name may be at most 160 characters');
        }
        $apiKey = self::apiKey($profile['api_key'] ?? null);
        if ($apiKey !== null && $apiKeyEnv !== null) {
            throw new PhycodeException('api_key and api_key_env cannot both be set: a profile has one key source');
        }
        if (($apiKey !== null || $apiKeyEnv !== null) && Config::isPlainHttp($baseUrl)) {
            throw new PhycodeException('base_url must be an https:// URL for a profile with a key, because the key is sent to it (http is allowed for localhost, 127.0.0.1 and ::1 only)');
        }

        return ['base_url' => $baseUrl, 'protocol' => $protocol, 'model' => $model, 'api_key_env' => $apiKeyEnv, 'api_key' => $apiKey];
    }

    private static function apiKey(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $key = is_string($value) ? trim($value) : '';
        if ($key === '' || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new PhycodeException('api_key must be the key itself, as one line of text');
        }

        return $key;
    }

    private static function checkBaseUrl(string $url): void
    {
        if (preg_match('~^https?://([^/]*)~', $url, $authority) === 1 && str_contains($authority[1], '@')) {
            throw new PhycodeException('base_url must not contain a user name or a password');
        }
        if (str_contains($url, '?')) {
            throw new PhycodeException('base_url must not contain a query');
        }
        $http = preg_match('~^https?://[^\s/@?#]+(/[^\s?#]*)?$~D', $url) === 1;
        $socket = preg_match('~^unix:///[^\s?#]+\.sock/?$~D', $url) === 1;
        if (!$http && !$socket) {
            throw new PhycodeException('base_url must be an http:// or https:// URL, or unix:///absolute/path.sock');
        }
        if (preg_match('~(?<!/)/v1/?$~D', $url) === 1) {
            throw new PhycodeException('remove /v1: phycode adds it');
        }
    }

    private static function choices(array $allowed): string
    {
        $last = (string) array_pop($allowed);

        return $allowed === [] ? $last : implode(', ', $allowed) . " or {$last}";
    }

    public static function save(string $file, string $original, \stdClass $data): void
    {
        $target = realpath($file) ?: $file;
        $mode = fileperms($target);
        $mode = is_int($mode) ? $mode & 0777 : null;
        $backup = $file . '.bak';
        if (is_link($backup)) {
            throw new PhycodeException("cannot write {$backup}: it is a symbolic link");
        }
        try {
            Files::writeAtomic($backup, $original, $mode);
        } catch (PhycodeException) {
            throw new PhycodeException("cannot write {$backup}");
        }
        Files::writeAtomic($target, Json::encode($data, true), $mode);
    }
}
