<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Support\PhycodeException;

final class McpBearer implements McpAuth
{
    public function __construct(private readonly string $variable)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $variable) !== 1) {
            throw new PhycodeException("'{$variable}' is not the name of an environment variable");
        }
    }

    public function headers(): array
    {
        $token = getenv($this->variable);

        return is_string($token) && $token !== '' ? ['Authorization' => 'Bearer ' . $token] : [];
    }

    public function rejected(int $status, string $challenge): bool
    {
        return false;
    }

    public function advice(string $server): string
    {
        $set = getenv($this->variable);

        return is_string($set) && $set !== ''
            ? "the token in {$this->variable} was refused; set it to a valid token for the MCP server {$server}"
            : "the MCP server {$server} wants a token; set {$this->variable} to it";
    }
}
