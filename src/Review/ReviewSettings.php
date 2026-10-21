<?php

declare(strict_types=1);

namespace Phycode\Review;

use Phycode\Config\Config;
use Phycode\Config\ConfigWriter;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class ReviewSettings
{
    public function __construct(public readonly string $file)
    {
    }

    public function enabled(): bool
    {
        $mode = $this->section()['mode'] ?? 'enabled';
        if ($mode !== 'enabled' && $mode !== 'disabled') {
            throw new PhycodeException("review.mode must be enabled or disabled in {$this->file}");
        }

        return $mode === 'enabled';
    }

    public function configured(): bool
    {
        return array_key_exists('mode', $this->section());
    }

    public function testCommand(string $project): ?array
    {
        $tests = $this->section()['test'] ?? null;
        if ($tests === null) {
            return null;
        }
        if (!is_array($tests) || ($tests !== [] && array_is_list($tests))) {
            throw new PhycodeException("review.test must map project folders to command lists in {$this->file}");
        }
        $folder = realpath($project) ?: $project;
        $argv = $tests[$folder] ?? null;
        if ($argv === null) {
            return null;
        }
        if (!is_array($argv) || $argv === [] || !array_is_list($argv) || array_filter($argv, static fn (mixed $part): bool => !is_string($part) || trim($part) === '') !== []) {
            throw new PhycodeException("review.test for {$folder} must be a list of strings, such as [\"vendor/bin/phpunit\"]");
        }

        return $argv;
    }

    public function setMode(bool $enabled): void
    {
        if (is_link($this->file)) {
            throw new PhycodeException("cannot change {$this->file}: it is a symbolic link");
        }
        if (!file_exists($this->file)) {
            throw new PhycodeException(sprintf(
                "cannot change review.mode: %s does not exist. Create it with: mkdir -p %s && echo '{}' > %s, then run /review mode %s again",
                $this->file,
                escapeshellarg(dirname($this->file)),
                escapeshellarg($this->file),
                $enabled ? 'on' : 'off',
            ));
        }
        $content = @file_get_contents($this->file);
        if ($content === false) {
            throw new PhycodeException("cannot read {$this->file}");
        }
        try {
            $data = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new PhycodeException("{$this->file} is not valid JSON: " . $error->getMessage());
        }
        if (!$data instanceof \stdClass) {
            throw new PhycodeException("{$this->file} must contain a JSON object");
        }
        $review = $data->review ?? new \stdClass();
        if (!$review instanceof \stdClass) {
            throw new PhycodeException("review must be an object in {$this->file}");
        }
        $review->mode = $enabled ? 'enabled' : 'disabled';
        $data->review = $review;

        $global = json_decode(Json::encode($data), true, 512, JSON_THROW_ON_ERROR);
        $profiles = $global['profiles'] ?? [];
        $environment = is_array($profiles) && $profiles !== [] ? [] : ['PHYCODE_BASE_URL' => 'http://127.0.0.1:8082', 'PHYCODE_MODEL' => 'check'];
        try {
            Config::fromArrays($global, null, $environment, dirname($this->file));
        } catch (PhycodeException $error) {
            throw new PhycodeException('the change to review.mode is refused: ' . $error->getMessage());
        }
        ConfigWriter::save($this->file, $content, $data);
    }

    private function section(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $content = @file_get_contents($this->file);
        if ($content === false) {
            throw new PhycodeException("cannot read {$this->file}");
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new PhycodeException("{$this->file} is not valid JSON: " . $error->getMessage());
        }
        if (!is_array($data)) {
            throw new PhycodeException("{$this->file} must contain a JSON object");
        }
        $review = $data['review'] ?? [];
        if (!is_array($review) || ($review !== [] && array_is_list($review))) {
            throw new PhycodeException("review must be an object in {$this->file}");
        }

        return $review;
    }
}
