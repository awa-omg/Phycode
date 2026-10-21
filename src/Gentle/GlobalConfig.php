<?php

declare(strict_types=1);

namespace Phycode\Gentle;

use Phycode\Config\Config;
use Phycode\Config\ConfigWriter;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class GlobalConfig
{
    public function __construct(private readonly string $file)
    {
    }

    public function file(): string
    {
        return $this->file;
    }

    public function read(): array
    {
        if (is_link($this->file)) {
            throw new PhycodeException("cannot change {$this->file}: it is a symbolic link");
        }
        $content = @file_get_contents($this->file);
        if ($content === false) {
            throw new PhycodeException("cannot read {$this->file}: create the global config first");
        }
        try {
            $data = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new PhycodeException("{$this->file} is not valid JSON: " . $error->getMessage());
        }
        if (!$data instanceof \stdClass) {
            throw new PhycodeException("{$this->file} must contain a JSON object");
        }

        return [$content, $data];
    }

    public function validate(\stdClass $data): void
    {
        $global = json_decode(Json::encode($data), true, 512, JSON_THROW_ON_ERROR);
        $profiles = $global['profiles'] ?? [];
        $environment = is_array($profiles) && $profiles !== [] ? [] : ['PHYCODE_BASE_URL' => 'http://127.0.0.1:8082', 'PHYCODE_MODEL' => 'check'];
        try {
            Config::fromArrays($global, null, $environment, dirname($this->file));
        } catch (PhycodeException $error) {
            throw new PhycodeException('the change is refused: ' . $error->getMessage());
        }
    }

    public function save(string $content, \stdClass $data): void
    {
        $this->validate($data);
        ConfigWriter::save($this->file, $content, $data);
    }

    public function gentleAiValue(string $key): ?string
    {
        if (is_link($this->file) || !is_file($this->file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->file), true);
        $section = is_array($data) ? ($data['gentle_ai'] ?? null) : null;
        $value = is_array($section) ? ($section[$key] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
