<?php

declare(strict_types=1);

namespace Phycode\Mcp;

final class McpToken implements McpAuth
{
    public function __construct(private readonly string $token)
    {
    }

    public function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function rejected(int $status, string $challenge): bool
    {
        return false;
    }

    public function advice(string $server): string
    {
        return "the MCP server {$server} refused the token that the editor gave";
    }
}
