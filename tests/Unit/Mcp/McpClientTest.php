<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Mcp;

use Phycode\Mcp\McpClient;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpClientTest extends TestCase
{
    private array $clients = [];

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];
        putenv('PHYCODE_TEST_LEAK');
    }

    public function testTheHandshakeGoesInOrderAndCarriesTheClientInfo(): void
    {
        $log = FakeMcpServer::logFile();
        $client = $this->client(env: ['PHYCODE_FAKE_MCP_LOG' => $log], clientVersion: '9.9.9');

        $client->listTools();

        $messages = FakeMcpServer::messages($log);
        self::assertSame(['server/discover', 'initialize', 'notifications/initialized', 'tools/list'], FakeMcpServer::methods($messages));
        self::assertSame('2.0', $messages[1]['jsonrpc']);
        self::assertSame(2, $messages[1]['id']);
        self::assertSame('2025-11-25', $messages[1]['params']['protocolVersion']);
        self::assertSame(['name' => 'phycode', 'version' => '9.9.9'], $messages[1]['params']['clientInfo']);
        self::assertStringContainsString('"capabilities":{}', FakeMcpServer::rawLines($log)[1]);
        self::assertArrayNotHasKey('id', $messages[2]);
        self::assertSame('legacy', $client->era());
        self::assertSame('2025-06-18', $client->version(), 'the version that the server answered with');
    }

    public function testListToolsReadsEachToolDefinition(): void
    {
        self::assertSame([[
            'name' => 'echo',
            'description' => 'Echoes the text argument back.',
            'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
        ]], $this->client()->listTools());
    }

    public function testListToolsFollowsEveryNextCursor(): void
    {
        $log = FakeMcpServer::logFile();
        $client = $this->client('paged', 'paged', env: ['PHYCODE_FAKE_MCP_LOG' => $log]);

        self::assertSame(['echo', 'upper', 'options'], array_column($client->listTools(), 'name'));

        $cursors = [];
        foreach (FakeMcpServer::messages($log) as $message) {
            if (($message['method'] ?? null) === 'tools/list') {
                $cursors[] = $message['params']['cursor'] ?? null;
            }
        }
        self::assertSame([null, '1', '2'], $cursors);
    }

    public function testARepeatedCursorIsAnErrorAndNotAnEndlessLoop(): void
    {
        $client = $this->client('cursor-loop', 'loop');

        self::assertFails('MCP server loop repeats its tools/list cursor or lists too many pages', fn () => $client->listTools());
    }

    public function testCallToolReturnsTheResultOfTheServer(): void
    {
        $result = $this->client()->callTool('echo', ['text' => 'hello']);

        self::assertSame(['content' => [['type' => 'text', 'text' => 'hello']], 'isError' => false], $result);
    }

    public function testEmptyArgumentsAreSentAsAnObject(): void
    {
        $log = FakeMcpServer::logFile();
        $this->client(env: ['PHYCODE_FAKE_MCP_LOG' => $log])->callTool('echo', []);

        $lines = FakeMcpServer::rawLines($log);
        self::assertStringContainsString('"name":"echo","arguments":{}', $lines[array_key_last($lines)]);
    }

    public function testLineBreaksAndLargeMessagesGoThroughWhole(): void
    {
        $text = "line one\nline two\n" . str_repeat('0123456789', 30000);

        $result = $this->client()->callTool('echo', ['text' => $text]);

        self::assertSame($text, $result['content'][0]['text']);
    }

    public function testAnErrorAnswerBecomesAnExceptionWithTheServersMessage(): void
    {
        $client = $this->client();

        self::assertFails('MCP server echo: tools/call failed: Unknown tool: missing (code -32602)', fn () => $client->callTool('missing', []));
    }

    public function testAToolErrorIsAResultNotAnException(): void
    {
        $result = $this->client('failing')->callTool('echo', ['text' => 'x']);

        self::assertTrue($result['isError']);
        self::assertSame([['type' => 'text', 'text' => 'the file is locked']], $result['content']);
    }

    public function testALateAnswerFailsTheRequestAndTheServerStaysUsable(): void
    {
        $log = FakeMcpServer::logFile();
        $client = $this->client('slow', 'slow', timeout: 0.5, env: ['PHYCODE_FAKE_MCP_LOG' => $log]);

        self::assertFails('MCP server slow did not answer tools/call within 0.5 s', fn () => $client->callTool('echo', ['text' => 'late']));
        usleep(500000);

        self::assertSame(['echo'], array_column($client->listTools(), 'name'));
        $cancelled = array_values(array_filter(
            FakeMcpServer::messages($log),
            static fn (array $message): bool => ($message['method'] ?? null) === 'notifications/cancelled',
        ));
        self::assertCount(1, $cancelled);
        self::assertSame(['requestId' => 3, 'reason' => 'timed out'], $cancelled[0]['params']);
    }

    public function testAPingFromTheServerIsAnsweredAndAnUnaskedAnswerIsIgnored(): void
    {
        $log = FakeMcpServer::logFile();
        $client = $this->client('stray', env: ['PHYCODE_FAKE_MCP_LOG' => $log]);

        $result = $client->callTool('echo', ['text' => 'pinged']);
        $client->listTools();

        self::assertSame('pinged', $result['content'][0]['text']);
        self::assertStringContainsString('{"jsonrpc":"2.0","id":"ping-1","result":{}}', implode("\n", FakeMcpServer::rawLines($log)));
    }

    public function testACrashedServerIsAClearErrorAndIsNotRestarted(): void
    {
        $client = $this->client('crash', 'crash');
        $message = 'MCP server crash exited with code 3 while waiting for tools/call: fatal: echo tool exploded';

        self::assertFails($message, fn () => $client->callTool('echo', ['text' => 'x']));
        self::assertFails($message, fn () => $client->listTools());
    }

    public function testAServerThatExitsBeforeAnsweringIsReportedWithItsStderr(): void
    {
        $client = new McpClient('shell', ['/bin/sh', '-c', 'echo boom >&2; exit 4'], Kit::folder('mcp-cwd'));
        $this->clients[] = $client;

        self::assertFails('MCP server shell exited with code 4 while waiting for server/discover: boom', fn () => $client->start());
    }

    public function testAnUnknownProtocolVersionIsRefusedAndTheServerIsStopped(): void
    {
        $log = FakeMcpServer::logFile();
        $client = $this->client('wrong-version', 'bad', env: ['PHYCODE_FAKE_MCP_LOG' => $log]);

        self::assertFails(
            'MCP server bad answered with protocol version 1999-01-01; phycode speaks 2026-07-28, 2025-11-25, 2025-06-18, 2025-03-26, 2024-11-05',
            fn () => $client->start(),
        );
        self::assertFalse(FakeMcpServer::alive(FakeMcpServer::pid($log)));
    }

    public function testAnInitializeErrorIsReported(): void
    {
        $client = $this->client('init-error', 'initerr');

        self::assertFails('MCP server initerr: initialize failed: the server is not configured (code -32603)', fn () => $client->listTools());
    }

    public function testACommandThatCannotStartIsReported(): void
    {
        $client = new McpClient('missing', ['/nonexistent/mcp-server'], Kit::folder('mcp-cwd'));
        $this->clients[] = $client;

        self::assertFails('MCP server missing: cannot start /nonexistent/mcp-server', fn () => $client->listTools());
    }

    public function testTheServerGetsOnlyTheConfiguredEnvironment(): void
    {
        putenv('PHYCODE_TEST_LEAK=parent-secret');
        $client = new McpClient(
            'shell',
            ['/bin/sh', '-c', 'echo "[$PHYCODE_TEST_LEAK] [$MCP_CONFIGURED]" >&2; exit 4'],
            Kit::folder('mcp-cwd'),
            ['MCP_CONFIGURED' => 'yes'],
        );
        $this->clients[] = $client;

        self::assertFails('MCP server shell exited with code 4 while waiting for server/discover: [] [yes]', fn () => $client->start());
    }

    public function testTheCommandIsNotRunThroughAShell(): void
    {
        $scenario = Kit::folder('spaced; folder') . '/plain.json';
        Kit::write($scenario, '{}');
        $marker = Kit::folder('marker') . '/touched';
        $client = new McpClient('echo', [...FakeMcpServer::argv(), $scenario, '$(touch ' . $marker . ')'], Kit::folder('mcp-cwd'));
        $this->clients[] = $client;

        $result = $client->callTool('echo', ['text' => 'literal']);

        self::assertSame('literal', $result['content'][0]['text']);
        self::assertFileDoesNotExist($marker);
    }

    public function testStderrKeepsOnlyItsLastBytes(): void
    {
        $client = new McpClient(
            'echo',
            FakeMcpServer::argv(),
            Kit::folder('mcp-cwd'),
            ['PHYCODE_FAKE_MCP_STDERR_BYTES' => '200000'],
            '0.1.0',
            5.0,
            0.5,
            64,
        );
        $this->clients[] = $client;

        $client->listTools();
        $client->close();

        self::assertLessThanOrEqual(64, strlen($client->stderr()));
        self::assertStringEndsWith("END-OF-STDERR\n", $client->stderr());
    }

    public function testCloseLetsAServerExitOnItsOwnWhenItsInputCloses(): void
    {
        $log = FakeMcpServer::logFile();
        $client = $this->client(env: ['PHYCODE_FAKE_MCP_LOG' => $log]);
        $client->listTools();
        $pid = FakeMcpServer::pid($log);

        $started = microtime(true);
        $client->close();

        self::assertLessThan(0.4, microtime(true) - $started, 'the server exits as soon as its stdin closes');
        self::assertFalse(FakeMcpServer::alive($pid));
    }

    public function testCloseTerminatesAServerThatIgnoresItsInputClosing(): void
    {
        $log = FakeMcpServer::logFile();
        $client = $this->client('ignore-eof', shutdownSeconds: 0.5, env: ['PHYCODE_FAKE_MCP_LOG' => $log]);
        $client->listTools();
        $pid = FakeMcpServer::pid($log);

        $started = microtime(true);
        $client->close();
        $elapsed = microtime(true) - $started;

        self::assertGreaterThanOrEqual(0.5, $elapsed, 'the server gets its shutdown time first');
        self::assertLessThan(3.0, $elapsed);
        self::assertFalse(FakeMcpServer::alive($pid));
    }

    public function testCloseKillsAServerThatIgnoresTerminate(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl is needed for a server that ignores SIGTERM');
        }
        $log = FakeMcpServer::logFile();
        $client = $this->client('ignore-term', shutdownSeconds: 0.3, env: ['PHYCODE_FAKE_MCP_LOG' => $log]);
        $client->listTools();
        $pid = FakeMcpServer::pid($log);

        $started = microtime(true);
        $client->close();
        $elapsed = microtime(true) - $started;

        self::assertGreaterThanOrEqual(1.3, $elapsed, 'shutdown time, then one second after SIGTERM');
        self::assertLessThan(5.0, $elapsed);
        self::assertFalse(FakeMcpServer::alive($pid));
    }

    public function testCallsAfterCloseFail(): void
    {
        $client = $this->client();
        $client->listTools();
        $client->close();

        self::assertFails('MCP server echo is closed', fn () => $client->callTool('echo', ['text' => 'x']));
    }

    public function testTheDefaultsAreThirtySecondsPerRequestAndTwoSecondsToExit(): void
    {
        $defaults = [];
        foreach ((new \ReflectionMethod(McpClient::class, '__construct'))->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        self::assertSame(30.0, $defaults['timeout']);
        self::assertSame(2.0, $defaults['shutdownSeconds']);
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'name with a space' => ['bad name', ['/bin/true'], 30.0];
        yield 'empty name' => ['', ['/bin/true'], 30.0];
        yield 'no command' => ['echo', [], 30.0];
        yield 'NUL in the command' => ['echo', ["/bin/true\0"], 30.0];
        yield 'zero timeout' => ['echo', ['/bin/true'], 0.0];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationsAreRefusedBeforeAnythingStarts(string $name, array $argv, float $timeout): void
    {
        $this->expectException(PhycodeException::class);

        new McpClient($name, $argv, null, [], '0.1.0', $timeout);
    }

    private function client(
        string $scenario = '',
        string $name = 'echo',
        float $timeout = 5.0,
        float $shutdownSeconds = 0.5,
        array $env = [],
        string $clientVersion = '0.1.0',
    ): McpClient {
        $client = FakeMcpServer::client($scenario, $name, $timeout, $shutdownSeconds, $env, $clientVersion);
        $this->clients[] = $client;

        return $client;
    }

    private static function assertFails(string $message, callable $call): void
    {
        try {
            $call();
        } catch (PhycodeException $error) {
            self::assertSame($message, $error->getMessage());

            return;
        }
        self::fail("expected a PhycodeException: {$message}");
    }
}
