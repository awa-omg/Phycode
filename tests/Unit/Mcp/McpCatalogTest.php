<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Mcp;

use Phycode\Mcp\McpCatalog;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class McpCatalogTest extends TestCase
{
    public function testAWorkingServerListsItsToolsAndAMissingCommandIsAnErrorWithItsReason(): void
    {
        $items = McpCatalog::items([
            'fake' => ['command' => FakeMcpServer::argv(), 'enabled' => true, 'approval' => 'ask'],
            'missing' => ['command' => ['/nonexistent/phycode-mcp'], 'enabled' => true, 'approval' => 'ask'],
            'off' => ['command' => ['/nonexistent/phycode-mcp'], 'enabled' => false, 'approval' => 'ask'],
        ], Kit::folder('mcp-cwd'), static fn (string $message): string => $message, 5.0);

        self::assertSame([
            ['category' => 'fake', 'title' => 'echo', 'detail' => 'Echoes the text argument back.', 'status' => 'ok'],
            ['category' => 'missing', 'title' => 'not started', 'detail' => 'MCP server missing: cannot start /nonexistent/phycode-mcp', 'status' => 'error'],
            ['category' => 'off', 'title' => 'disabled', 'detail' => 'enabled is false in mcp_servers', 'status' => 'off'],
        ], $items);
    }

    public function testTheReasonGoesThroughTheRedactionOfTheCaller(): void
    {
        $items = McpCatalog::items([
            'shell' => ['command' => ['sh', '-c', 'echo hunter2-secret >&2; exit 3'], 'enabled' => true, 'approval' => 'ask'],
        ], Kit::folder('mcp-cwd'), static fn (string $message): string => str_replace('hunter2-secret', '[redacted]', $message), 5.0);

        self::assertSame(['error'], array_column($items, 'status'));
        self::assertStringContainsString('exited with code 3', $items[0]['detail']);
        self::assertStringContainsString('[redacted]', $items[0]['detail']);
        self::assertStringNotContainsString('hunter2-secret', $items[0]['detail']);
    }

    public function testNoServersGiveNoItems(): void
    {
        self::assertSame([], McpCatalog::items([], Kit::folder('mcp-cwd'), static fn (string $message): string => $message));
    }

    public function testTheUnconfiguredTextSaysWhereToConfigureServers(): void
    {
        $text = McpCatalog::unconfigured('/home/me/.config/phycode/config.json');

        self::assertStringContainsString('"mcp_servers"', $text);
        self::assertStringContainsString('/home/me/.config/phycode/config.json', $text);
    }
}
