<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Mcp;

use Phycode\Mcp\McpBearer;
use Phycode\Mcp\McpHttpClient;
use Phycode\Mcp\McpRpcException;
use Phycode\Support\PhycodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpHttpClientTest extends TestCase
{
    private ?FakeMcpHttp $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        putenv('PHYCODE_TEST_MCP_TOKEN');
    }

    private function client(array $scenario = [], ?string $path = null, ?\Phycode\Mcp\McpAuth $auth = null, array $headers = [], float $timeout = 5.0): McpHttpClient
    {
        $this->server = new FakeMcpHttp($scenario);

        return new McpHttpClient('remote', $this->server->url($path ?? '/mcp'), $headers, $auth, $timeout, '4.5.6');
    }

    private function received(int $index): array
    {
        $request = $this->server->requests()[$index];
        $request['json'] = json_decode($request['body'], true);

        return $request;
    }

    public function testAModernServerIsFoundByTheFirstRequestAndEveryRequestCarriesItsHeaders(): void
    {
        $client = $this->client();

        $tools = $client->listTools();
        $result = $client->callTool('echo', ['text' => 'hi']);

        self::assertSame(['echo'], array_column($tools, 'name'));
        self::assertSame('hi', $result['content'][0]['text']);
        self::assertSame('modern', $client->era());
        self::assertSame(['server/discover', 'tools/list', 'tools/call'], $this->server->methods());
        foreach ([0 => 'server/discover', 1 => 'tools/list', 2 => 'tools/call'] as $index => $method) {
            $request = $this->received($index);
            self::assertSame('POST', $request['method']);
            self::assertSame('2026-07-28', $request['headers']['mcp-protocol-version']);
            self::assertSame($method, $request['headers']['mcp-method']);
            self::assertSame('application/json', $request['headers']['content-type']);
            self::assertSame('application/json, text/event-stream', $request['headers']['accept']);
            self::assertSame('2026-07-28', $request['json']['params']['_meta']['io.modelcontextprotocol/protocolVersion']);
            self::assertSame(['name' => 'phycode', 'version' => '4.5.6'], $request['json']['params']['_meta']['io.modelcontextprotocol/clientInfo']);
            self::assertArrayNotHasKey('mcp-session-id', $request['headers']);
        }
        self::assertSame('echo', $this->received(2)['headers']['mcp-name']);
        self::assertArrayNotHasKey('mcp-name', $this->received(1)['headers']);
    }

    public function testAnAnswerThatIsAnEventStreamIsReadUntilItsResponse(): void
    {
        $client = $this->client(['sse' => true]);

        self::assertSame('hi', $client->callTool('echo', ['text' => 'hi'])['content'][0]['text']);
        self::assertSame(['echo'], array_column($client->listTools(), 'name'));
    }

    public static function probes(): iterable
    {
        yield 'a JSON-RPC error' => ['rpc-error'];
        yield '400 without a body' => ['400'];
        yield '404' => ['404'];
        yield '405' => ['405'];
    }

    #[DataProvider('probes')]
    public function testALegacyServerThatRefusesTheProbeInAnyWayGetsTheHandshakeWithItsSession(string $probe): void
    {
        $client = $this->client(['era' => 'legacy', 'session' => true, 'probe' => $probe]);

        $tools = $client->listTools();
        $client->callTool('echo', ['text' => 'x']);

        self::assertSame(['echo'], array_column($tools, 'name'));
        self::assertSame('legacy', $client->era());
        self::assertSame(['server/discover', 'initialize', 'notifications/initialized', 'tools/list', 'tools/call'], $this->server->methods());
        self::assertArrayNotHasKey('mcp-session-id', $this->received(1)['headers'], 'initialize has no session yet');
        self::assertArrayNotHasKey('mcp-protocol-version', $this->received(1)['headers']);
        foreach ([2, 3, 4] as $index) {
            self::assertSame('sess-1', $this->received($index)['headers']['mcp-session-id'], "request {$index}");
            self::assertSame('2025-06-18', $this->received($index)['headers']['mcp-protocol-version']);
        }
        self::assertArrayNotHasKey('_meta', $this->received(3)['json']['params'] ?? []);
    }

    public function testAServerWithoutSessionsNeedsNoSessionHeader(): void
    {
        $client = $this->client(['era' => 'legacy']);

        $client->listTools();

        self::assertArrayNotHasKey('mcp-session-id', $this->received(3)['headers']);
        self::assertSame('2025-06-18', $this->received(3)['headers']['mcp-protocol-version']);
    }

    public function testClosingTheClientEndsALegacySession(): void
    {
        $client = $this->client(['era' => 'legacy', 'session' => true]);
        $client->listTools();

        $client->close();

        self::assertSame('sess-1', $this->server->deletedSession());
    }

    public function testASessionThatTheServerEndedIsStartedAgainAndTheRequestGoesThrough(): void
    {
        $client = $this->client(['era' => 'legacy', 'session' => true, 'expireSession' => 2]);
        $client->listTools();

        $result = $client->callTool('echo', ['text' => 'after expiry']);

        self::assertSame('after expiry', $result['content'][0]['text']);
        self::assertSame(
            ['server/discover', 'initialize', 'notifications/initialized', 'tools/list', 'tools/call', 'initialize', 'notifications/initialized', 'tools/call'],
            array_slice($this->server->methods(), 0, 8),
        );
        self::assertSame('sess-2', $this->received(7)['headers']['mcp-session-id']);
    }

    public function testResourcesAndPromptsWorkOverHttpInBothEras(): void
    {
        $full = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/mcp/legacy-full.json'), true);
        foreach (['modern', 'legacy'] as $era) {
            $client = $this->client($full + ['era' => $era]);

            self::assertSame("# Hello\n", $client->readResource('file:///project/README.md')[0]['text'], $era);
            self::assertSame(['review', 'hello'], array_column($client->listPrompts(), 'name'), $era);
            self::assertSame('Please review: x', $client->getPrompt('review', ['code' => 'x', 'focus' => 'y'])['messages'][0]['content']['text'], $era);
            $this->server->stop();
        }
    }

    public function testANameThatIsNotPlainAsciiTravelsBase64EncodedInMcpName(): void
    {
        $client = $this->client(['tools' => [['name' => 'héllo', 'description' => '', 'inputSchema' => ['type' => 'object']]]]);

        $client->callTool('héllo', []);

        self::assertSame('=?base64?' . base64_encode('héllo') . '?=', $this->received(1)['headers']['mcp-name']);
    }

    public function testTheParametersATollMarksWithXMcpHeaderAreMirroredAndAnInvalidToolIsLeftOut(): void
    {
        $tools = [
            ['name' => 'sql', 'description' => '', 'inputSchema' => ['type' => 'object', 'properties' => [
                'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                'limit' => ['type' => 'integer', 'x-mcp-header' => 'Limit'],
                'dry' => ['type' => 'boolean', 'x-mcp-header' => 'Dry'],
                'note' => ['type' => 'string', 'x-mcp-header' => 'Note'],
                'query' => ['type' => 'string'],
            ]]],
            ['name' => 'bad', 'description' => '', 'inputSchema' => ['type' => 'object', 'properties' => ['n' => ['type' => 'number', 'x-mcp-header' => 'N']]]],
        ];
        $client = $this->client(['tools' => $tools]);

        $listed = array_column($client->listTools(), 'name');
        $client->callTool('sql', ['region' => 'us-west1', 'limit' => 5, 'dry' => false, 'note' => "héllo\nworld", 'query' => 'select 1']);

        self::assertSame(['echo', 'sql'], $listed);
        self::assertSame(["bad: x-mcp-header 'N' is on a parameter that is not an integer, a string or a boolean"], $client->skipped());
        $headers = $this->received(2)['headers'];
        self::assertSame('us-west1', $headers['mcp-param-region']);
        self::assertSame('5', $headers['mcp-param-limit']);
        self::assertSame('false', $headers['mcp-param-dry']);
        self::assertSame('=?base64?' . base64_encode("héllo\nworld") . '?=', $headers['mcp-param-note']);
        self::assertArrayNotHasKey('mcp-param-query', $headers);
    }

    public function testATokenIsSentOnEveryRequestAndAMissingOneIsExplained(): void
    {
        putenv('PHYCODE_TEST_MCP_TOKEN=s3cret-token-value');
        $client = $this->client(['token' => 's3cret-token-value'], auth: new McpBearer('PHYCODE_TEST_MCP_TOKEN'));

        $client->listTools();

        foreach ($this->server->requests() as $request) {
            self::assertSame('Bearer s3cret-token-value', $request['headers']['authorization']);
        }

        putenv('PHYCODE_TEST_MCP_TOKEN');
        $this->server->stop();
        $missing = $this->client(['token' => 'x'], auth: new McpBearer('PHYCODE_TEST_MCP_TOKEN'));
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('the MCP server remote wants a token; set PHYCODE_TEST_MCP_TOKEN to it');
        $missing->listTools();
    }

    public function testATokenTheServerRefusesIsReportedWithoutBeingShown(): void
    {
        putenv('PHYCODE_TEST_MCP_TOKEN=wrong-token-value');
        $client = $this->client(['token' => 'right'], auth: new McpBearer('PHYCODE_TEST_MCP_TOKEN'));

        try {
            $client->listTools();
            self::fail('no error');
        } catch (PhycodeException $error) {
            self::assertSame('the token in PHYCODE_TEST_MCP_TOKEN was refused; set it to a valid token for the MCP server remote', $error->getMessage());
            self::assertStringNotContainsString('wrong-token-value', $error->getMessage());
        }
    }

    public function testAServerThatWantsCredentialsTheConfigDoesNotHaveSaysWhatToSet(): void
    {
        $client = $this->client(['token' => 'x']);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('MCP server remote refused the request (HTTP 401); it wants credentials: set token_env or oauth for it in mcp_servers');

        $client->listTools();
    }

    public function testTheHeadersOfTheConfigAreSentAndCannotReplaceTheOnesThatPhycodeSets(): void
    {
        $client = $this->client(headers: ['X-Team' => 'platform']);
        $client->listTools();
        self::assertSame('platform', $this->received(0)['headers']['x-team']);

        foreach (['Authorization', 'Content-Type', 'Mcp-Method', 'MCP-Protocol-Version', 'Host'] as $name) {
            try {
                new McpHttpClient('r', 'https://example.com/mcp', [$name => 'x']);
                self::fail($name);
            } catch (PhycodeException $error) {
                self::assertStringContainsString('is set by phycode', $error->getMessage(), $name);
            }
        }
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('must be one line of text');
        new McpHttpClient('r', 'https://example.com/mcp', ['X-A' => "a\r\nX-B: b"]);
    }

    public static function badUrls(): iterable
    {
        yield 'plain http on the network' => ['http://example.com/mcp', 'the url must be https'];
        yield 'not http' => ['file:///etc/passwd', 'the url must be an http or https address'];
        yield 'no host' => ['https:///mcp', 'the url must be an http or https address'];
        yield 'with a password' => ['https://user:pw@example.com/mcp', 'the url has no user, password or fragment'];
        yield 'with a fragment' => ['https://example.com/mcp#x', 'the url has no user, password or fragment'];
        yield 'with a space' => ['https://example.com/m cp', 'a space or a control character'];
    }

    #[DataProvider('badUrls')]
    public function testAnAddressThatCouldLeakATokenIsRefused(string $url, string $message): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage($message);

        new McpHttpClient('r', $url);
    }

    public function testHttpIsAllowedOnThisMachineAndHttpsAnywhere(): void
    {
        foreach (['http://127.0.0.1:8080/mcp', 'http://localhost/mcp', 'http://[::1]:9/mcp', 'https://mcp.example.com/mcp'] as $url) {
            McpHttpClient::checkUrl($url, 'x');
        }
        $this->addToAssertionCount(1);
    }

    public function testARedirectIsNotFollowedSoATokenNeverGoesElsewhere(): void
    {
        $client = $this->client(path: '/redirect');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('answered with a redirect (HTTP 302)');

        $client->listTools();
    }

    public function testAServerErrorAndAnUnreachableServerAreReportedNotHung(): void
    {
        $client = $this->client(['statusOnCall' => 503]);
        $client->start();
        try {
            $client->callTool('echo', ['text' => 'x']);
            self::fail('no error');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('tools/call failed: HTTP 503', $error->getMessage());
        }

        $gone = new McpHttpClient('gone', 'http://127.0.0.1:1/mcp', [], null, 2.0);
        $started = microtime(true);
        try {
            $gone->listTools();
            self::fail('no error');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('the request to its url failed', $error->getMessage());
            self::assertLessThan(5.0, microtime(true) - $started);
        }
    }

    public function testAnErrorOfTheServerKeepsItsCodeForTheCaller(): void
    {
        $client = $this->client();

        try {
            $client->readResource('file:///absent');
            self::fail('no error');
        } catch (McpRpcException $error) {
            self::assertSame(-32602, $error->rpcCode);
            self::assertStringContainsString('Resource not found', $error->getMessage());
        }
    }

    public function testAResultThatAsksForInputIsAnError(): void
    {
        $client = $this->client(['inputRequired' => true]);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('tools/call needs input from the user');

        $client->callTool('echo', ['text' => 'x']);
    }
}
