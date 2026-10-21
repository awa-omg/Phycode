<?php

declare(strict_types=1);

namespace Phycode\Config;

use Phycode\Ssh\SshHost;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class SshHostWriter
{
    public static function add(string $file, SshHost $host): void
    {
        [$content, $data] = self::load($file);
        $hosts = self::hosts($data);
        if (property_exists($hosts, $host->name)) {
            throw new PhycodeException("ssh host '{$host->name}' already exists in {$file}");
        }
        $hosts->{$host->name} = json_decode(Json::encode($host->toArray()), false, 512, JSON_THROW_ON_ERROR);
        self::validate($data, $file);
        ConfigWriter::save($file, $content, $data);
    }

    public static function remove(string $file, string $name): void
    {
        [$content, $data] = self::load($file);
        $hosts = self::hosts($data);
        if (!property_exists($hosts, $name)) {
            throw new PhycodeException("no ssh host named '{$name}' in {$file}");
        }
        unset($hosts->{$name});
        self::validate($data, $file);
        ConfigWriter::save($file, $content, $data);
    }

    public static function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        [, $data] = self::load($file);
        $hosts = [];
        foreach (get_object_vars(self::hosts($data)) as $name => $raw) {
            $hosts[(string) $name] = SshHost::fromArray((string) $name, is_object($raw) ? get_object_vars($raw) : []);
        }

        return $hosts;
    }

    private static function load(string $file): array
    {
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

        return [$content, $data];
    }

    private static function hosts(\stdClass $data): \stdClass
    {
        $ssh = $data->ssh ?? new \stdClass();
        if (!$ssh instanceof \stdClass) {
            throw new PhycodeException('ssh in the config file must be an object');
        }
        $hosts = $ssh->hosts ?? new \stdClass();
        if (!$hosts instanceof \stdClass) {
            throw new PhycodeException('ssh.hosts in the config file must be an object');
        }
        $ssh->hosts = $hosts;
        $data->ssh = $ssh;

        return $hosts;
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
