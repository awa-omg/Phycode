<?php

declare(strict_types=1);

namespace Phycode\Deploy;

use Phycode\Ssh\SshHosts;

final readonly class DeployContext
{
    public function __construct(
        public string $root,
        public string $directory,
        public string $token,
        public string $tag,
        public \Closure $which,
        public ?SshHosts $ssh = null,
        public ?string $version = null,
        public int $lines = 100,
    ) {
    }

    public function has(string $relative): bool
    {
        return is_file($this->directory . '/' . $relative);
    }

    public function firstOf(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($this->has($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
