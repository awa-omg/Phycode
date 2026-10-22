<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Cli\Application;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;
use Phycode\Support\Vault;

final class McpCatalog
{
    public const TIMEOUT_SECONDS = 10.0;

    public static function items(array $servers, string $cwd, callable $redact, float $timeout = self::TIMEOUT_SECONDS, ?Vault $vault = null): array
    {
        $vault ??= Vault::inUserConfig();
        $items = [];
        foreach ($servers as $name => $server) {
            $name = (string) $name;
            if (!$server['enabled']) {
                $items[] = self::item($name, 'disabled', 'enabled is false in mcp_servers', 'off');
                continue;
            }

            $client = null;
            try {
                $client = McpServers::build($name, $server, $cwd, $vault, $timeout, Application::VERSION);
                $tools = $client->listTools();
            } catch (PhycodeException $error) {
                $items[] = self::item($name, 'not started', $redact($error->getMessage()), 'error');
                continue;
            } finally {
                $client?->close();
            }

            if ($tools === []) {
                $items[] = self::item($name, 'no tools', '', 'ok');
            }
            foreach ($tools as $tool) {
                $items[] = self::item($name, self::line($tool['name'], 100), $redact(self::line($tool['description'], 200)), 'ok');
            }
        }

        return $items;
    }

    public static function unconfigured(string $configFile): string
    {
        return "No MCP servers are configured.\n\n"
            . "Add them under \"mcp_servers\" in the global config, {$configFile}, for example:\n"
            . "  \"mcp_servers\": { \"docs\": { \"command\": [\"npx\", \"-y\", \"some-mcp-server\"] } }\n\n"
            . 'Each server is started when you run /mcp, and stopped again after its tools are listed.';
    }

    private static function item(string $server, string $title, string $detail, string $status): array
    {
        return ['category' => $server, 'title' => $title, 'detail' => $detail, 'status' => $status];
    }

    private static function line(string $text, int $max): string
    {
        return Text::oneLine((string) preg_replace('/\p{Cc}+/u', ' ', mb_scrub($text, 'UTF-8')), $max);
    }
}
