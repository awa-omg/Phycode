<?php

declare(strict_types=1);

namespace Phycode\Config;

use Phycode\Deploy\DeployTarget;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class DeployTargetWriter
{
    public static function add(string $file, DeployTarget $target): void
    {
        [$content, $data] = self::load($file);
        $targets = self::targets($data);
        if (property_exists($targets, $target->name)) {
            throw new PhycodeException("deploy target '{$target->name}' already exists in {$file}");
        }
        $targets->{$target->name} = json_decode(Json::encode($target->toArray()), false, 512, JSON_THROW_ON_ERROR);
        self::validate($data, $file);
        ConfigWriter::save($file, $content, $data);
    }

    public static function remove(string $file, string $name): void
    {
        [$content, $data] = self::load($file);
        $targets = self::targets($data);
        if (!property_exists($targets, $name)) {
            throw new PhycodeException("no deploy target named '{$name}' in {$file}");
        }
        unset($targets->{$name});
        self::validate($data, $file);
        ConfigWriter::save($file, $content, $data);
    }

    public static function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        [, $data] = self::load($file);
        $targets = [];
        foreach (get_object_vars(self::targets($data)) as $name => $raw) {
            $targets[(string) $name] = DeployTarget::fromArray((string) $name, is_object($raw) ? (json_decode(Json::encode($raw), true) ?? []) : []);
        }

        return $targets;
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

    private static function targets(\stdClass $data): \stdClass
    {
        $deploy = $data->deploy ?? new \stdClass();
        if (!$deploy instanceof \stdClass) {
            throw new PhycodeException('deploy in the config file must be an object');
        }
        $targets = $deploy->targets ?? new \stdClass();
        if (!$targets instanceof \stdClass) {
            throw new PhycodeException('deploy.targets in the config file must be an object');
        }
        $deploy->targets = $targets;
        $data->deploy = $deploy;

        return $targets;
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
