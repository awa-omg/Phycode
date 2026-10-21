<?php

declare(strict_types=1);

namespace Phycode\Gentle;

use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class McpPresets
{
    public const NAMES = ['engram', 'context7', 'codegraph'];

    private const CONTEXT7_VERSION = '2.2.5';

    public static function command(string $name, ?string $engram = null): array
    {
        return match ($name) {
            'engram' => [$engram ?? 'engram', 'mcp', '--tools=agent'],
            'context7' => ['npx', '-y', '--package=@upstash/context7-mcp@' . self::CONTEXT7_VERSION, '--', 'context7-mcp'],
            'codegraph' => ['codegraph', 'serve', '--mcp'],
            default => throw new PhycodeException('no MCP preset named ' . $name . '; the presets are ' . implode(', ', self::NAMES)),
        };
    }

    public static function entry(string $name, ?string $engram = null): array
    {
        return ['command' => self::command($name, $engram), 'enabled' => true, 'approval' => 'ask'];
    }

    public static function add(GlobalConfig $config, string $name, ?string $engram, Approval $approve): string
    {
        $entry = self::entry($name, $engram);
        [$content, $data] = $config->read();
        $servers = $data->mcp_servers ?? new \stdClass();
        if (!$servers instanceof \stdClass) {
            throw new PhycodeException("mcp_servers in {$config->file()} must be an object");
        }
        if (property_exists($servers, $name)) {
            if (self::same($servers->{$name}, $entry)) {
                return "mcp_servers.{$name} is already set to this preset in {$config->file()}; nothing changed.";
            }

            throw new PhycodeException("mcp_servers.{$name} is already set in {$config->file()} to another entry. Nothing changed; edit that file to change it.");
        }

        $servers->{$name} = (object) $entry;
        $data->mcp_servers = $servers;
        $config->validate($data);
        $approved = $approve(
            "/mcp preset {$name}",
            "add this entry to mcp_servers in {$config->file()} (the old file is kept as {$config->file()}.bak):\n"
                . Json::encode([$name => $entry], true)
                . "\nnothing else in the file changes",
            "add the {$name} MCP server to the global config",
        );
        if (!$approved) {
            return 'not approved: nothing was changed.';
        }
        $config->save($content, $data);

        return "added mcp_servers.{$name} to {$config->file()}. Restart phycode to load it; /mcp then lists its tools.";
    }

    private static function same(mixed $current, array $entry): bool
    {
        $current = json_decode(Json::encode($current), true);
        if (!is_array($current)) {
            return false;
        }
        ksort($current);
        ksort($entry);

        return $current === $entry;
    }
}
