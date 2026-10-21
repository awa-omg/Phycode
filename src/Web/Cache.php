<?php

declare(strict_types=1);

namespace Phycode\Web;

use Phycode\Support\Files;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class Cache
{
    private const SUFFIX = '.cache';

    public function __construct(
        private readonly string $directory,
        private readonly int $ttlMinutes = 60,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public static function key(string $method, string $url, string $body = ''): string
    {
        return hash('sha256', Json::encode([$method, $url, $body]));
    }

    public function get(string $key): ?string
    {
        $file = $this->file($key);
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $newline = is_string($raw) ? strpos($raw, "\n") : false;
        if ($newline === false || !ctype_digit(substr($raw, 0, $newline))) {
            return null;
        }
        if ((int) substr($raw, 0, $newline) <= $this->now()) {
            @unlink($file);

            return null;
        }

        return substr($raw, $newline + 1);
    }

    public function put(string $key, string $value): void
    {
        $file = $this->file($key);
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new WebException('cannot create the cache folder');
        }
        try {
            Files::writeAtomic($file, ($this->now() + $this->ttlMinutes * 60) . "\n" . $value, 0600);
        } catch (PhycodeException) {
            throw new WebException('cannot write the cache');
        }
    }

    private function file(string $key): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
            throw new WebException('invalid cache key');
        }

        return rtrim($this->directory, '/') . '/' . $key . self::SUFFIX;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
