<?php

declare(strict_types=1);

namespace Phycode\Deploy;

use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class DeployHistory
{
    public function __construct(private readonly string $root)
    {
    }

    public function file(): string
    {
        return $this->root . '/.phycode/deploys.jsonl';
    }

    public function append(array $entry): void
    {
        $folder = $this->root . '/.phycode';
        if (is_link($folder) || is_link($this->file())) {
            throw new PhycodeException('.phycode is a symbolic link, so phycode keeps no deploy history');
        }
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new PhycodeException('cannot create .phycode for the deploy history');
        }
        if (!is_file($folder . '/.gitignore')) {
            file_put_contents($folder . '/.gitignore', "*\n");
        }
        $handle = fopen($this->file(), 'ab');
        if ($handle === false) {
            throw new PhycodeException('cannot write the deploy history');
        }
        chmod($this->file(), 0600);
        flock($handle, LOCK_EX);
        fwrite($handle, Json::encode(['time' => gmdate('Y-m-d\TH:i:s\Z')] + $entry) . "\n");
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function entries(?string $target = null, int $limit = 20): array
    {
        if (!is_file($this->file())) {
            return [];
        }
        $entries = [];
        foreach (array_reverse(explode("\n", trim((string) file_get_contents($this->file())))) as $line) {
            $entry = Json::object($line);
            if ($entry !== null && ($target === null || ($entry['target'] ?? null) === $target)) {
                $entries[] = $entry;
                if (count($entries) >= $limit) {
                    break;
                }
            }
        }

        return $entries;
    }
}
