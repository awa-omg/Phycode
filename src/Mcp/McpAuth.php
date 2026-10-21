<?php

declare(strict_types=1);

namespace Phycode\Mcp;

interface McpAuth
{
    public function headers(): array;

    public function rejected(int $status, string $challenge): bool;

    public function advice(string $server): string;
}
