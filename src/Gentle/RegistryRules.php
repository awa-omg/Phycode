<?php

declare(strict_types=1);

namespace Phycode\Gentle;

final class RegistryRules
{
    public static function excluded(string $name): bool
    {
        return $name === '_shared' || $name === 'skill-registry' || str_starts_with($name, 'sdd-');
    }
}
