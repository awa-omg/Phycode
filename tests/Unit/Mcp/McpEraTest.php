<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Mcp;

use Phycode\Support\PhycodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpEraTest extends TestCase
{
    public function testAModernServerIsFoundByTheProbeAndNeverSeesAnInitialize(): void
    {
        $log = FakeMcpServer::logFile();
        $client = FakeMcpServer::client('modern', env: ['PHYCODE_FAKE_MCP_LOG' => $log], clientVersion: '3.2.1');

        $tools = $client->listTools();

        self::assertSame(['echo'], array_column($tools, 'name'));
        self::assertSame('modern', $client->era());
        self::assertSame('2026-07-28', $client->version());
        $messages = FakeMcpServer::messages($log);
        self::assertSame(['server/discover', 'tools/list'], FakeMcpServer::methods($messages));
        foreach ($messages as $message) {
            $meta = $message['params']['_meta'];
            self::assertSame('2026-07-28', $meta['io.modelcontextprotocol/protocolVersion']);
            self::assertSame(['name' => 'phycode', 'version' => '3.2.1'], $meta['io.modelcontextprotocol/clientInfo']);
            self::assertSame([], $meta['io.modelcontextprotocol/clientCapabilities']);
        }
        self::assertStringContainsString('"io.modelcontextprotocol/clientCapabilities":{}', FakeMcpServer::rawLines($log)[1], 'an object, not a list');
        self::assertSame('fake-echo', $client->serverInfo()['name']);
    }

    public function testALegacyServerAnswersTheProbeWithAnErrorAndGetsTheHandshake(): void
    {
        $log = FakeMcpServer::logFile();
        $client = FakeMcpServer::client('', env: ['PHYCODE_FAKE_MCP_LOG' => $log]);

        $client->listTools();

        self::assertSame('legacy', $client->era());
        self::assertSame(['server/discover', 'initialize', 'notifications/initialized', 'tools/list'], FakeMcpServer::methods(FakeMcpServer::messages($log)));
        $tools = FakeMcpServer::messages($log)[3];
        self::assertArrayNotHasKey('_meta', $tools['params'] ?? [], 'a legacy server gets no per-request metadata');
    }

    public function testAServerThatSupportsOnlyAnOlderVersionThanTheProbeGetsTheLegacyHandshake(): void
    {
        $client = FakeMcpServer::client('unsupported-fallback');

        self::assertSame(['echo'], array_column($client->listTools(), 'name'));
        self::assertSame('legacy', $client->era());
    }

    public function testAServerThatSpeaksOnlyAnUnknownVersionIsRefusedWithItsVersions(): void
    {
        $client = FakeMcpServer::client('unsupported-only');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('MCP server echo speaks the protocol versions 2027-01-01; phycode speaks 2026-07-28, 2025-11-25');

        $client->listTools();
    }

    public function testWhatTheServerSaysAboutItselfIsKept(): void
    {
        foreach (['modern-full', 'legacy-full'] as $scenario) {
            $client = FakeMcpServer::client($scenario);
            $client->start();

            self::assertSame('Use echo for tests.', $client->instructions(), $scenario);
            self::assertTrue($client->supports('tools'), $scenario);
            self::assertTrue($client->supports('resources'), $scenario);
            self::assertTrue($client->supports('prompts'), $scenario);
            self::assertSame('fake-echo', $client->serverInfo()['name'], $scenario);
        }
        $plain = FakeMcpServer::client('modern');
        $plain->start();
        self::assertFalse($plain->supports('resources'));
        self::assertNull($plain->instructions());
    }

    public static function eras(): iterable
    {
        yield 'modern' => ['modern-full'];
        yield 'legacy' => ['legacy-full'];
    }

    #[DataProvider('eras')]
    public function testResourcesAreListedAndRead(string $scenario): void
    {
        $client = FakeMcpServer::client($scenario);

        self::assertSame([
            ['uri' => 'file:///project/README.md', 'name' => 'README.md', 'description' => 'The readme', 'mimeType' => 'text/markdown'],
            ['uri' => 'file:///project/logo.png', 'name' => 'logo.png', 'description' => '', 'mimeType' => 'image/png'],
        ], $client->listResources());
        self::assertSame([['uriTemplate' => 'file:///logs/{day}.log', 'name' => 'Logs', 'description' => 'Logs by day', 'mimeType' => 'text/plain']], $client->listResourceTemplates());
        self::assertSame([['uri' => 'file:///project/README.md', 'mimeType' => 'text/markdown', 'text' => "# Hello\n"]], $client->readResource('file:///project/README.md'));
        self::assertSame('iVBORw0KGgo=', $client->readResource('file:///project/logo.png')[0]['blob']);
    }

    #[DataProvider('eras')]
    public function testAResourceThatDoesNotExistIsAnErrorWithTheCode(string $scenario): void
    {
        $client = FakeMcpServer::client($scenario);

        try {
            $client->readResource('file:///nothing');
            self::fail('no error');
        } catch (\Phycode\Mcp\McpRpcException $error) {
            self::assertSame(-32602, $error->rpcCode);
            self::assertStringContainsString('Resource not found', $error->getMessage());
        }
    }

    #[DataProvider('eras')]
    public function testPromptsAreListedAndFilledWithTheirArguments(string $scenario): void
    {
        $client = FakeMcpServer::client($scenario);

        $prompts = $client->listPrompts();
        self::assertSame(['review', 'hello'], array_column($prompts, 'name'));
        self::assertSame([
            ['name' => 'code', 'description' => 'The code', 'required' => true],
            ['name' => 'focus', 'description' => '', 'required' => false],
        ], $prompts[0]['arguments']);
        self::assertSame([], $prompts[1]['arguments']);

        $prompt = $client->getPrompt('review', ['code' => 'echo 1', 'focus' => 'style']);
        self::assertSame('prompt review', $prompt['description']);
        self::assertSame([
            ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Please review: echo 1']],
            ['role' => 'assistant', 'content' => ['type' => 'text', 'text' => 'Focus: style']],
        ], $prompt['messages']);
    }

    public function testAResultThatAsksForInputIsAnErrorBecauseTheClientOffersNone(): void
    {
        $client = FakeMcpServer::client('input-required');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('MCP server echo: tools/call needs input from the user, which phycode does not offer to a server');

        $client->callTool('echo', ['text' => 'x']);
    }

    public function testToolCallsOfAModernServerCarryTheirMetaAndReturnTheResult(): void
    {
        $log = FakeMcpServer::logFile();
        $client = FakeMcpServer::client('modern', env: ['PHYCODE_FAKE_MCP_LOG' => $log]);

        $result = $client->callTool('echo', ['text' => 'hi']);

        self::assertSame('hi', $result['content'][0]['text']);
        $call = FakeMcpServer::messages($log)[1];
        self::assertSame('tools/call', $call['method']);
        self::assertSame('echo', $call['params']['name']);
        self::assertSame('2026-07-28', $call['params']['_meta']['io.modelcontextprotocol/protocolVersion']);
    }
}
