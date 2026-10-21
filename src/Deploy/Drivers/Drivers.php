<?php

declare(strict_types=1);

namespace Phycode\Deploy\Drivers;

use Phycode\Deploy\DeployDriver;

final class Drivers
{
    public static function for(string $kind): DeployDriver
    {
        return match ($kind) {
            'docker' => new DockerDriver('docker'),
            'podman' => new DockerDriver('podman'),
            'vercel' => new VercelDriver(),
            'cloudflare' => new CloudflareDriver(),
            'modal' => new ModalDriver(),
            'ssh' => new SshDriver(),
            'custom' => new CustomDriver(),
            default => throw new \InvalidArgumentException("no deploy driver for '{$kind}'"),
        };
    }

    public static function missing(\Closure $which, array $command): ?string
    {
        return $which($command[0]) === null ? "{$command[0]} was not found on PATH; install it, or set command in the target" : null;
    }

    public static function notes(array $notes): array
    {
        return array_values(array_filter($notes, static fn (?string $note): bool => $note !== null));
    }
}
