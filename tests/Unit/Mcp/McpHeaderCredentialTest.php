<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Mcp;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Mcp\McpHttpClient;
use Phycode\Support\PhycodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpHeaderCredentialTest extends TestCase
{
    public static function credentialHeaders(): iterable
    {
        yield 'X-Api-Key' => ['X-Api-Key'];
        yield 'apikey' => ['apikey'];
        yield 'X-Auth-Token' => ['X-Auth-Token'];
        yield 'X-Access-Token' => ['X-Access-Token'];
        yield 'X-Secret' => ['X-Secret'];
        yield 'Password' => ['Password'];
        yield 'Private-Token' => ['Private-Token'];
        yield 'Proxy-Authorization' => ['Proxy-Authorization'];
    }

    #[DataProvider('credentialHeaders')]
    public function testTheHeaderIsRefusedAndTheMessageNamesItAndTheWayOut(string $name): void
    {
        try {
            McpHttpClient::checkHeader($name, 'abc123', 'mcp_servers.dev');
            self::fail("{$name} was accepted");
        } catch (PhycodeException $error) {
            self::assertStringContainsString("mcp_servers.dev: the header {$name} would hold a credential", $error->getMessage());
            self::assertStringContainsString('set token_env to the name of an environment variable', $error->getMessage());
        }
    }

    public function testTheConfigRefusesAKeyInAHeaderWhenItIsLoaded(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('mcp_servers.dev: the header X-Api-Key would hold a credential in config.json');

        self::config(['X-Api-Key' => 'abc123']);
    }

    public function testTheClientRefusesTheHeaderToo(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('the header X-Api-Key would hold a credential');

        new McpHttpClient('dev', 'https://example.com/mcp', ['X-Api-Key' => 'abc123']);
    }

    public function testAnOrdinaryHeaderIsStillSent(): void
    {
        $config = self::config(['X-Trace' => 'trace-123']);

        self::assertSame(['X-Trace' => 'trace-123'], $config->mcpServers()['dev']['headers']);
        self::assertInstanceOf(McpHttpClient::class, new McpHttpClient('dev', 'https://example.com/mcp', ['X-Trace' => 'trace-123']));
    }

    private static function config(array $headers): Config
    {
        return Config::fromArrays([
            'profiles' => ['p' => ['base_url' => 'https://models.example/v1', 'model' => 'm']],
            'mcp_servers' => ['dev' => ['url' => 'https://example.com/mcp', 'headers' => $headers]],
        ], null, [], '/nonexistent');
    }
}
