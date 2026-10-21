<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Mcp;

use Phycode\Mcp\McpCli;
use Phycode\Mcp\OAuthHttp;
use Phycode\Support\Vault;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Cli\PhycodeRun;
use PHPUnit\Framework\TestCase;

final class McpCliTest extends TestCase
{
    private ?FakeMcpHttp $http = null;

    protected function tearDown(): void
    {
        $this->http?->stop();
    }

    private function cli(array $args, array $servers, ?Vault $vault = null, ?\Closure $open = null): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $status = McpCli::run($args, $servers, Kit::folder('mcp-cli-cwd'), $vault ?? new Vault(Kit::folder('mcp-cli-vault')), $out, $err, $open, 5.0, 10.0);
        rewind($out);
        rewind($err);

        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private function remote(string $url, array $extra = []): array
    {
        return $extra + ['command' => [], 'url' => $url, 'headers' => [], 'token_env' => null, 'oauth' => ['client_id' => null, 'client_secret_env' => null, 'scopes' => [], 'callback_port' => 0], 'enabled' => true, 'approval' => 'ask'];
    }

    public function testListShowsEveryServerAndWhetherARemoteOneHasALogin(): void
    {
        $vault = new Vault(Kit::folder('mcp-cli-vault'));
        $servers = [
            'docs' => ['command' => ['npx', '-y', 'docs-mcp'], 'enabled' => true, 'approval' => 'ask'],
            'remote' => $this->remote('https://mcp.example.com/mcp'),
            'keyed' => $this->remote('https://keyed.example.com/mcp', ['oauth' => null, 'token_env' => 'KEYED_TOKEN', 'approval' => 'allow', 'enabled' => false]),
        ];

        [$status, $out] = $this->cli([], $servers, $vault);

        self::assertSame(0, $status);
        self::assertSame(
            "docs  stdio  on   approval ask  npx -y docs-mcp\n"
            . "remote  http  on   approval ask  https://mcp.example.com/mcp  oauth: not signed in (phycode mcp login remote)\n"
            . "keyed  http  off  approval allow  https://keyed.example.com/mcp  token: \$KEYED_TOKEN\n",
            $out,
        );
    }

    public function testWithoutServersListSaysHowToAddOne(): void
    {
        [$status, $out] = $this->cli(['list'], []);

        self::assertSame(0, $status);
        self::assertStringContainsString('No MCP servers are configured', $out);
        self::assertStringContainsString('"oauth": true', $out);
    }

    public function testTestConnectsAndShowsWhatTheServerOffers(): void
    {
        $servers = ['docs' => ['command' => FakeMcpServer::argv('modern-full'), 'enabled' => true, 'approval' => 'ask']];

        [$status, $out] = $this->cli(['test', 'docs'], $servers);

        self::assertSame(0, $status);
        self::assertSame(
            "docs: connected, modern protocol 2026-07-28\nserver: fake-echo 1.0.0\ntools (1): echo\nresources: 2, templates: 1\nprompts: review, hello\n",
            $out,
        );
    }

    public function testTestOfAServerThatCannotStartFailsWithTheReason(): void
    {
        [$status, , $err] = $this->cli(['test', 'broken'], ['broken' => ['command' => ['/nonexistent/phycode-mcp'], 'enabled' => true, 'approval' => 'ask']]);

        self::assertSame(1, $status);
        self::assertStringContainsString('cannot start /nonexistent/phycode-mcp', $err);
    }

    public function testLoginSignsInAndLogoutForgetsTheLogin(): void
    {
        $this->http = new FakeMcpHttp(['oauth' => true]);
        $vault = new Vault(Kit::folder('mcp-cli-vault'));
        $servers = ['remote' => $this->remote($this->http->url())];
        $browser = static function (string $url): void {
            $response = OAuthHttp::request('GET', $url);
            $parts = parse_url($response['headers']['location']);
            $socket = fsockopen('127.0.0.1', (int) $parts['port'], $errno, $message, 2);
            fwrite($socket, "GET {$parts['path']}?{$parts['query']} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
            fclose($socket);
        };

        [$status, $out, $err] = $this->cli(['login', 'remote'], $servers, $vault, $browser);

        self::assertSame(0, $status, $err);
        self::assertStringStartsWith('Signed in to remote (issuer ' . $this->http->url(''), $out);
        self::assertStringContainsString('Sign in to the MCP server remote at this address:', $err);
        self::assertStringNotContainsString('at-1', $out . $err);
        self::assertStringContainsString('oauth: signed in', $this->cli(['list'], $servers, $vault)[1]);

        self::assertSame([0, "Forgot the login of remote.\n"], array_slice($this->cli(['logout', 'remote'], $servers, $vault), 0, 2));
        self::assertSame([0, "remote had no login.\n"], array_slice($this->cli(['logout', 'remote'], $servers, $vault), 0, 2));
    }

    public function testLoginAndLogoutNeedAServerThatHasOauth(): void
    {
        $servers = ['docs' => ['command' => ['x'], 'enabled' => true, 'approval' => 'ask'], 'plain' => $this->remote('https://example.com/mcp', ['oauth' => null])];

        foreach ([['login', 'docs'], ['login', 'plain'], ['logout', 'plain']] as $args) {
            [$status, , $err] = $this->cli($args, $servers);
            self::assertSame(1, $status, implode(' ', $args));
            self::assertStringContainsString('has no oauth setting', $err, implode(' ', $args));
        }
        [$status, , $err] = $this->cli(['login', 'nobody'], $servers);
        self::assertSame(1, $status);
        self::assertStringContainsString('no MCP server named nobody in mcp_servers', $err);
        self::assertStringContainsString('a server name is needed', $this->cli(['login'], $servers)[2]);
        self::assertStringContainsString("unknown mcp command 'dance'", $this->cli(['dance'], $servers)[2]);
    }

    public function testTheCommandRunsAsAProcessAndReadsTheGlobalConfig(): void
    {
        $run = PhycodeRun::run(['mcp', '--cwd', Kit::folder('mcp-cli-process')], ['base_url' => 'http://127.0.0.1:9', 'model' => 'x'], global: ['mcp_servers' => [
            'docs' => ['command' => FakeMcpServer::argv('modern')],
        ]]);

        self::assertSame(0, $run->status, $run->stderr);
        self::assertStringStartsWith('docs  stdio  on   approval ask  ', $run->stdout);

        $test = PhycodeRun::run(['mcp', 'test', 'docs', '--cwd', Kit::folder('mcp-cli-process')], ['base_url' => 'http://127.0.0.1:9', 'model' => 'x'], global: ['mcp_servers' => [
            'docs' => ['command' => FakeMcpServer::argv('modern')],
        ]]);
        self::assertSame(0, $test->status, $test->stderr);
        self::assertStringContainsString('docs: connected, modern protocol 2026-07-28', $test->stdout);
    }
}
