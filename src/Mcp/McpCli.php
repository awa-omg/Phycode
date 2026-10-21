<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Text;
use Phycode\Support\Vault;

final class McpCli
{
    private const USAGE = <<<'TEXT'
        usage:
          phycode mcp [list]            the servers of mcp_servers, and whether a server at an address has a login
          phycode mcp login NAME        sign in to a server that has oauth: opens the browser, and keeps the tokens in the vault
          phycode mcp logout NAME       forget the login of a server
          phycode mcp test NAME         connect to a server and show its protocol, tools, resources and prompts
        TEXT;

    public static function run(array $args, array $servers, string $cwd, Vault $vault, $out, $err, ?\Closure $open = null, float $timeout = 10.0, float $loginSeconds = 180.0): int
    {
        $command = array_shift($args) ?? 'list';
        try {
            return match ($command) {
                'list' => self::list($servers, $vault, $out),
                'login' => self::login($args, $servers, $vault, $out, $err, $open ?? McpOAuth::openBrowser(...), $loginSeconds),
                'logout' => self::logout($args, $servers, $vault, $out),
                'test' => self::test($args, $servers, $cwd, $vault, $out, $timeout),
                default => throw new PhycodeException("unknown mcp command '{$command}'\n" . self::USAGE),
            };
        } catch (PhycodeException $error) {
            fwrite($err, 'phycode: ' . Redaction::text($error->getMessage()) . PHP_EOL);

            return 1;
        }
    }

    private static function list(array $servers, Vault $vault, $out): int
    {
        if ($servers === []) {
            fwrite($out, "No MCP servers are configured. Add them under \"mcp_servers\" in the global config:\n"
                . "  \"mcp_servers\": { \"docs\": { \"command\": [\"npx\", \"-y\", \"some-mcp-server\"] },\n"
                . "                    \"remote\": { \"url\": \"https://mcp.example.com/mcp\", \"oauth\": true } }\n");

            return 0;
        }
        foreach ($servers as $name => $server) {
            $remote = isset($server['url']);
            $credentials = '';
            if ($remote && is_array($server['oauth'] ?? null)) {
                $auth = McpServers::oauth((string) $name, $server, $vault);
                $credentials = '  oauth: ' . ($auth !== null && $auth->loggedIn() ? 'signed in' : "not signed in (phycode mcp login {$name})");
            } elseif ($remote && isset($server['token_env'])) {
                $credentials = "  token: \${$server['token_env']}";
            }
            fwrite($out, sprintf(
                "%s  %s  %s  approval %s  %s%s\n",
                $name,
                $remote ? 'http' : 'stdio',
                $server['enabled'] ? 'on ' : 'off',
                $server['approval'],
                $remote ? $server['url'] : Text::oneLine(implode(' ', $server['command']), 80),
                $credentials,
            ));
        }

        return 0;
    }

    private static function server(array $args, array $servers): array
    {
        $name = $args[0] ?? '';
        if (count($args) !== 1 || !isset($servers[$name])) {
            throw new PhycodeException($name === '' || count($args) !== 1 ? "a server name is needed\n" . self::USAGE : "no MCP server named {$name} in mcp_servers; phycode mcp lists them");
        }

        return $servers[$name];
    }

    private static function login(array $args, array $servers, Vault $vault, $out, $err, \Closure $open, float $seconds): int
    {
        $server = self::server($args, $servers);
        $auth = McpServers::oauth($args[0], $server, $vault);
        if ($auth === null) {
            throw new PhycodeException("{$args[0]} has no oauth setting: add \"oauth\": true to it in mcp_servers (only a server at an address signs in)");
        }
        $result = $auth->login(static function (string $line) use ($err): void {
            fwrite($err, $line . "\n");
        }, $open, $seconds);
        fwrite($out, "Signed in to {$args[0]} (issuer {$result['issuer']}" . ($result['scope'] !== '' ? ", scope {$result['scope']}" : '') . ($result['expires_at'] !== null ? ', the token lasts until ' . gmdate('Y-m-d H:i', $result['expires_at']) . ' UTC' : '') . ").\n");

        return 0;
    }

    private static function logout(array $args, array $servers, Vault $vault, $out): int
    {
        $server = self::server($args, $servers);
        $auth = McpServers::oauth($args[0], $server, $vault);
        if ($auth === null) {
            throw new PhycodeException("{$args[0]} has no oauth setting, so it has no login to forget");
        }
        fwrite($out, $auth->logout() ? "Forgot the login of {$args[0]}.\n" : "{$args[0]} had no login.\n");

        return 0;
    }

    private static function test(array $args, array $servers, string $cwd, Vault $vault, $out, float $timeout): int
    {
        $server = self::server($args, $servers);
        $connection = McpServers::build($args[0], $server, $cwd, $vault, $timeout, '0.1.0');
        try {
            $connection->start();
            $tools = $connection->listTools();
            fwrite($out, sprintf("%s: connected, %s protocol %s\n", $args[0], $connection->era(), $connection->version()));
            $info = $connection->serverInfo();
            if ($info !== null && is_string($info['name'] ?? null)) {
                fwrite($out, 'server: ' . Text::oneLine($info['name'] . (is_string($info['version'] ?? null) ? ' ' . $info['version'] : ''), 100) . "\n");
            }
            fwrite($out, 'tools (' . count($tools) . '): ' . Text::oneLine(implode(', ', array_column($tools, 'name')), 300) . "\n");
            if ($connection->supports('resources')) {
                fwrite($out, 'resources: ' . count($connection->listResources()) . ', templates: ' . count($connection->listResourceTemplates()) . "\n");
            }
            if ($connection->supports('prompts')) {
                fwrite($out, 'prompts: ' . Text::oneLine(implode(', ', array_column($connection->listPrompts(), 'name')), 300) . "\n");
            }
        } finally {
            $connection->close();
        }

        return 0;
    }
}
