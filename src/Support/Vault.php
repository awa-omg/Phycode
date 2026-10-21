<?php

declare(strict_types=1);

namespace Phycode\Support;

final class Vault
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_BYTES = 32;
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;
    private const MODE = 0600;
    private const NAME = '/^[^\x00-\x1F\x7F]{1,128}$/D';
    private const LINE = '/[\x00-\x1F\x7F]/';

    public function __construct(private readonly string $directory)
    {
    }

    public static function inUserConfig(): self
    {
        $home = (string) (getenv('HOME') ?: '/root');

        return new self(rtrim((string) (getenv('XDG_CONFIG_HOME') ?: $home . '/.config'), '/') . '/phycode');
    }

    public static function validProfile(string $profile): bool
    {
        return preg_match(self::NAME, $profile) === 1 && !str_contains($profile, ':');
    }

    public function store(string $profile, string $apiKey): void
    {
        self::checkProfile($profile);
        $this->storeEntry($profile, $apiKey);
    }

    public function storeMcpLogin(string $server, string $json): void
    {
        $this->storeEntry(self::mcpName($server), $json);
    }

    public function mcpLogin(string $server): ?string
    {
        return $this->readEntry(self::mcpName($server));
    }

    public function removeMcpLogin(string $server): bool
    {
        return $this->removeEntry(self::mcpName($server));
    }

    public function storeSearchKey(string $provider, string $apiKey): void
    {
        $this->storeEntry(self::searchName($provider), $apiKey);
    }

    public function get(string $profile): ?string
    {
        self::checkProfile($profile);

        return $this->readEntry($profile);
    }

    public function searchKey(string $provider): ?string
    {
        return $this->readEntry(self::searchName($provider));
    }

    public function hasSearchKey(string $provider): bool
    {
        return array_key_exists(self::searchName($provider), $this->entries());
    }

    public function profiles(): array
    {
        $names = array_values(array_filter(array_map('strval', array_keys($this->entries())), static fn (string $name): bool => !str_contains($name, ':')));
        sort($names, SORT_STRING);

        return $names;
    }

    public function remove(string $profile): bool
    {
        self::checkProfile($profile);

        return $this->removeEntry($profile);
    }

    public function storeDeploySecret(string $target, string $variable, string $value): void
    {
        $this->storeEntry(self::deployName($target, $variable), $value);
    }

    public function deploySecret(string $target, string $variable): ?string
    {
        return $this->readEntry(self::deployName($target, $variable));
    }

    public function deploySecretNames(string $target): array
    {
        $prefix = self::deployName($target, '');
        $names = [];
        foreach (array_keys($this->entries()) as $name) {
            $name = (string) $name;
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                $names[] = substr($name, strlen($prefix));
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    public function removeDeploySecret(string $target, string $variable): bool
    {
        return $this->removeEntry(self::deployName($target, $variable));
    }

    public function removeSearchKey(string $provider): bool
    {
        return $this->removeEntry(self::searchName($provider));
    }

    private function storeEntry(string $name, string $apiKey): void
    {
        $key = trim($apiKey);
        if ($key === '') {
            throw new PhycodeException('no API key was given');
        }
        if (preg_match(self::LINE, $key) === 1) {
            throw new PhycodeException('an API key is one line of text');
        }
        $master = $this->masterKey(true);
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $data = openssl_encrypt($key, self::CIPHER, $master, OPENSSL_RAW_DATA, $iv, $tag, self::aad($name), self::TAG_BYTES);
        if ($data === false) {
            throw new PhycodeException('the API key cannot be encrypted');
        }
        $entries = $this->entries();
        $entries[$name] = ['iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($data)];
        $this->write($entries);
    }

    private function readEntry(string $name): ?string
    {
        $entries = $this->entries();
        if (!array_key_exists($name, $entries)) {
            return null;
        }
        $master = $this->masterKey(false);
        if ($master === null) {
            throw new PhycodeException("the stored key of {$name} cannot be read: master.key is missing");
        }
        $entry = $entries[$name];
        $iv = self::field($entry, 'iv');
        $tag = self::field($entry, 'tag');
        $data = self::field($entry, 'data');
        if ($iv === null || $tag === null || $data === null || $data === '' || strlen($iv) !== self::IV_BYTES || strlen($tag) !== self::TAG_BYTES) {
            throw new PhycodeException("the stored key of {$name} is damaged");
        }
        $plain = openssl_decrypt($data, self::CIPHER, $master, OPENSSL_RAW_DATA, $iv, $tag, self::aad($name));
        if ($plain === false) {
            throw new PhycodeException("the stored key of {$name} cannot be decrypted: master.key does not match, or the entry was changed");
        }

        return $plain;
    }

    private function removeEntry(string $name): bool
    {
        $entries = $this->entries();
        if (!array_key_exists($name, $entries)) {
            return false;
        }
        unset($entries[$name]);
        $this->write($entries);

        return true;
    }

    private function entries(): array
    {
        $raw = $this->readPrivate($this->directory . '/keys.json');
        if ($raw === null) {
            return [];
        }
        try {
            $data = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PhycodeException($this->directory . '/keys.json is not valid JSON');
        }
        if (!$data instanceof \stdClass) {
            throw new PhycodeException($this->directory . '/keys.json must contain a JSON object');
        }

        return get_object_vars($data);
    }

    private function masterKey(bool $create): ?string
    {
        $path = $this->directory . '/master.key';
        $key = $this->readPrivate($path);
        if ($key !== null) {
            if (strlen($key) !== self::KEY_BYTES) {
                throw new PhycodeException("{$path} does not hold a 32-byte key");
            }

            return $key;
        }
        if (!$create) {
            return null;
        }
        if ($this->entries() !== []) {
            throw new PhycodeException("{$path} is missing, so the stored keys cannot be read; restore it, or delete keys.json to start again");
        }

        return $this->createMasterKey($path);
    }

    private function createMasterKey(string $path): string
    {
        $this->ensureDirectory();
        $key = random_bytes(self::KEY_BYTES);
        $temporary = $this->directory . '/.master-' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new PhycodeException("cannot write a temporary file in {$this->directory}");
        }
        chmod($temporary, self::MODE);
        $written = fwrite($handle, $key);
        fclose($handle);
        $linked = $written === self::KEY_BYTES && @link($temporary, $path);
        @unlink($temporary);
        if ($linked) {
            return $key;
        }
        $existing = $this->masterKey(false);
        if ($existing === null) {
            throw new PhycodeException("cannot create {$path}");
        }

        return $existing;
    }

    private function write(array $entries): void
    {
        $this->ensureDirectory();
        Files::writeAtomic($this->directory . '/keys.json', Json::encode((object) $entries, true) . "\n", self::MODE);
    }

    private function readPrivate(string $path): ?string
    {
        if (is_link($path)) {
            throw new PhycodeException("{$path} is a symbolic link; phycode does not follow it");
        }
        if (!file_exists($path)) {
            return null;
        }
        $mode = fileperms($path);
        if ($mode === false || !is_file($path)) {
            throw new PhycodeException("{$path} is not a regular file");
        }
        if (($mode & 0777 & ~self::MODE) !== 0) {
            throw new PhycodeException(sprintf('%s must be readable only by its owner (mode %04o), but its mode is %04o; run: chmod 600 %s', $path, self::MODE, $mode & 0777, $path));
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new PhycodeException("cannot read {$path}");
        }

        return $content;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new PhycodeException("cannot create the folder {$this->directory}");
        }
    }

    private static function field(mixed $entry, string $name): ?string
    {
        $value = $entry instanceof \stdClass ? ($entry->{$name} ?? null) : null;
        $bytes = is_string($value) ? base64_decode($value, true) : false;

        return $bytes === false ? null : $bytes;
    }

    private static function deployName(string $target, string $variable): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $target) !== 1 || ($variable !== '' && preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $variable) !== 1)) {
            throw new PhycodeException('a deploy secret is a target name and an environment variable name in capitals');
        }

        return "deploy:{$target}:{$variable}";
    }

    private static function checkProfile(string $profile): void
    {
        if (!self::validProfile($profile)) {
            throw new PhycodeException('a profile name is 1 to 128 characters, none of them a control character or a colon');
        }
    }

    private static function mcpName(string $server): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $server) !== 1) {
            throw new PhycodeException("MCP server name '{$server}' may only use letters, digits, '-' and '_'");
        }

        return 'mcp:' . $server;
    }

    private static function searchName(string $provider): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $provider) !== 1) {
            throw new PhycodeException('a search provider name is 1 to 32 characters: lowercase letters, digits, - and _, starting with a letter or a digit');
        }

        return 'web-search:' . $provider;
    }

    private static function aad(string $profile): string
    {
        return 'phycode-vault:' . $profile;
    }
}
