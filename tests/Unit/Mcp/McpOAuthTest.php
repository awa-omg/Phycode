<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Mcp;

use Phycode\Mcp\McpHttpClient;
use Phycode\Mcp\McpOAuth;
use Phycode\Mcp\OAuthHttp;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Web\FixtureServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpOAuthTest extends TestCase
{
    private ?FakeMcpHttp $server = null;
    private Vault $vault;

    protected function setUp(): void
    {
        $this->vault = new Vault(Kit::folder('mcp-vault'));
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        putenv('PHYCODE_TEST_CLIENT_SECRET');
    }

    private function auth(array $scenario = [], array $settings = []): McpOAuth
    {
        $this->server = new FakeMcpHttp($scenario + ['oauth' => true]);

        return new McpOAuth('remote', $this->server->url(), $this->vault, $settings + ['client_id' => null, 'client_secret_env' => null, 'scopes' => [], 'callback_port' => 0]);
    }

    private function browser(?\Closure $change = null): \Closure
    {
        return static function (string $url) use ($change): void {
            $response = OAuthHttp::request('GET', $url);
            $location = $response['headers']['location'] ?? throw new \RuntimeException('no redirect from ' . $url . ': ' . $response['body']);
            if ($change !== null) {
                $location = $change($location);
            }
            $parts = parse_url($location);
            $socket = fsockopen('127.0.0.1', (int) $parts['port'], $errno, $message, 2);
            fwrite($socket, "GET {$parts['path']}?{$parts['query']} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
            fclose($socket);
        };
    }

    private static function send(string $location, string $host = '127.0.0.1')
    {
        $parts = parse_url($location);
        $socket = fsockopen('127.0.0.1', (int) $parts['port'], $errno, $message, 2);
        fwrite($socket, "GET {$parts['path']}?{$parts['query']} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");

        return $socket;
    }

    private function login(McpOAuth $auth, ?\Closure $browser = null, float $wait = 10.0): array
    {
        return $auth->login(static function (string $line): void {
        }, $browser ?? $this->browser(), $wait);
    }

    private function stored(): array
    {
        return json_decode((string) $this->vault->mcpLogin('remote'), true);
    }

    public function testALoginRegistersTheClientAsksWithPkceAndTheResourceAndKeepsTheTokens(): void
    {
        $auth = $this->auth();

        $result = $this->login($auth);

        self::assertSame(['issuer' => $this->server->url(''), 'scope' => 'files:read', 'expires_at' => $result['expires_at']], $result);
        $registration = array_values($this->server->clients())[0];
        self::assertSame('native', $registration['application_type']);
        self::assertSame(['authorization_code', 'refresh_token'], $registration['grant_types']);
        self::assertMatchesRegularExpression('~^http://127\.0\.0\.1:\d+/callback$~', $registration['redirect_uris'][0]);

        $authorize = $this->server->lines('authorize-requests.jsonl')[0];
        self::assertSame('code', $authorize['response_type']);
        self::assertSame('client-1', $authorize['client_id']);
        self::assertSame('S256', $authorize['code_challenge_method']);
        self::assertSame($this->server->url(), $authorize['resource']);
        self::assertSame('files:read', $authorize['scope'], 'the scope of the challenge');
        self::assertSame($registration['redirect_uris'][0], $authorize['redirect_uri']);
        self::assertGreaterThanOrEqual(32, strlen($authorize['state']));

        $token = $this->server->lines('token-requests.jsonl')[0];
        self::assertSame('authorization_code', $token['form']['grant_type']);
        self::assertSame($this->server->url(), $token['form']['resource']);
        self::assertSame('client-1', $token['form']['client_id']);
        self::assertFalse($token['has_secret']);

        self::assertSame(['Authorization' => 'Bearer at-1'], $auth->headers());
        self::assertTrue($auth->loggedIn());
        self::assertStringNotContainsString('at-1', json_encode($result));
    }

    public function testTheTokenOpensTheServerThroughTheHttpClient(): void
    {
        $auth = $this->auth();
        $this->login($auth);

        $client = new McpHttpClient('remote', $this->server->url(), [], $auth, 5.0);

        self::assertSame(['echo'], array_column($client->listTools(), 'name'));
        self::assertSame('Bearer at-1', $this->server->requests()[array_key_last($this->server->requests())]['headers']['authorization']);
    }

    public static function discoveries(): iterable
    {
        yield 'the address is in the challenge' => [['prm' => 'header']];
        yield 'the well-known address with the path' => [['prm' => 'wellknown-path']];
        yield 'the well-known address of the root' => [['prm' => 'wellknown-root']];
        yield 'OpenID Connect discovery' => [['asMeta' => 'openid']];
    }

    #[DataProvider('discoveries')]
    public function testTheAuthorizationServerIsFoundEachWayTheSpecificationNames(array $scenario): void
    {
        $auth = $this->auth($scenario);

        $this->login($auth);

        self::assertSame(['Authorization' => 'Bearer at-1'], $auth->headers());
    }

    public function testAClientThatThePersonRegisteredIsUsedWithItsSecretAndNothingIsRegistered(): void
    {
        putenv('PHYCODE_TEST_CLIENT_SECRET=shh-its-a-secret');
        $auth = $this->auth(['preregistered' => ['my-client'], 'dcr' => false], ['client_id' => 'my-client', 'client_secret_env' => 'PHYCODE_TEST_CLIENT_SECRET']);

        $this->login($auth);

        self::assertSame([], $this->server->clients());
        $token = $this->server->lines('token-requests.jsonl')[0];
        self::assertSame('my-client', $token['form']['client_id']);
        self::assertTrue($token['has_secret'], 'a client secret goes in the token request');
    }

    public function testAnAddressAsClientIdIsAClientIdMetadataDocumentWhenTheServerSupportsThem(): void
    {
        $auth = $this->auth(['cimd' => true, 'dcr' => false], ['client_id' => 'https://app.example.com/client.json']);

        $this->login($auth);

        self::assertSame('https://app.example.com/client.json', $this->server->lines('authorize-requests.jsonl')[0]['client_id']);
    }

    public function testWithoutAClientIdAndWithoutRegistrationTheLoginSaysWhatToSet(): void
    {
        $auth = $this->auth(['dcr' => false]);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('does not register clients by itself; register phycode there and set oauth.client_id for remote');

        $this->login($auth);
    }

    public function testTheScopeOfTheConfigIsAskedForBesideTheOneTheServerChallenged(): void
    {
        $auth = $this->auth([], ['scopes' => ['files:write']]);

        $this->login($auth);

        self::assertSame('files:read files:write', $this->server->lines('authorize-requests.jsonl')[0]['scope']);
    }

    public function testATokenThatEndsIsRenewedBeforeItIsUsedAndTheNewRefreshTokenIsKept(): void
    {
        $auth = $this->auth(['expiresIn' => 10]);
        $this->login($auth);
        $first = $this->stored();

        $headers = $auth->headers();

        self::assertSame(['Authorization' => 'Bearer at-2'], $headers);
        $refresh = $this->server->lines('token-requests.jsonl')[1];
        self::assertSame('refresh_token', $refresh['form']['grant_type']);
        self::assertSame($this->server->url(), $refresh['form']['resource']);
        self::assertSame('rt-2', $this->stored()['refresh_token']);
        self::assertNotSame($first['refresh_token'], $this->stored()['refresh_token']);
        self::assertSame($first['client_id'], $this->stored()['client_id']);
    }

    public function testAServerThatDoesNotRotateKeepsTheOldRefreshToken(): void
    {
        $auth = $this->auth(['expiresIn' => 10, 'noRotate' => true]);
        $this->login($auth);

        $auth->headers();

        self::assertSame('rt-1', $this->stored()['refresh_token']);
        self::assertSame('at-2', $this->stored()['access_token']);
    }

    public function testATokenTheServerRevokedIsRenewedAndTheRequestIsSentAgain(): void
    {
        $auth = $this->auth();
        $this->login($auth);
        $client = new McpHttpClient('remote', $this->server->url(), [], $auth, 5.0);
        $client->listTools();

        $this->server->revokeAccessTokens();
        $result = $client->callTool('echo', ['text' => 'after revoke']);

        self::assertSame('after revoke', $result['content'][0]['text']);
        self::assertSame('at-2', $this->stored()['access_token']);
    }

    public function testARefreshTokenThatIsRefusedEndsTheLoginButKeepsTheClient(): void
    {
        $auth = $this->auth(['expiresIn' => 10, 'refreshFails' => true]);
        $this->login($auth);

        self::assertSame([], $auth->headers());

        self::assertFalse($auth->loggedIn());
        self::assertSame('client-1', $this->stored()['client_id'], 'the client is kept, so the next login does not register again');
        self::assertArrayNotHasKey('refresh_token', $this->stored());
        self::assertSame('the MCP server remote needs you to sign in: run phycode mcp login remote', $auth->advice('remote'));
    }

    public function testTheAdviceSaysToLoginAgainWhenTheServerRefusesTheToken(): void
    {
        $auth = $this->auth();
        $this->login($auth);

        self::assertSame('the MCP server remote refused the login that phycode has; run phycode mcp login remote again', $auth->advice('remote'));
        self::assertFalse($auth->rejected(403, 'Bearer error="insufficient_scope"'), 'a 403 is about scopes, not about an old token');
    }

    public function testLogoutForgetsTheLogin(): void
    {
        $auth = $this->auth();
        $this->login($auth);

        self::assertTrue($auth->logout());

        self::assertFalse($auth->loggedIn());
        self::assertSame([], $auth->headers());
        self::assertFalse($auth->logout());
    }

    public function testTheLoginOfAnotherAddressIsNotUsed(): void
    {
        $auth = $this->auth();
        $this->login($auth);

        $other = new McpOAuth('remote', 'https://other.example.com/mcp', $this->vault);

        self::assertSame([], $other->headers());
    }

    public static function refusals(): iterable
    {
        yield 'an issuer that is not the one asked' => [['issuerOverride' => 'https://evil.example'], 'says its issuer is https://evil.example'];
        yield 'no PKCE' => [['pkce' => false], 'does not say that it supports PKCE with S256'];
        yield 'metadata about another resource' => [['prmResource' => 'https://other.example/mcp'], 'is about https://other.example/mcp'];
        yield 'an answer from another issuer' => [['issWrong' => true], 'names another issuer than the one phycode asked'];
        yield 'an iss that the server promised and left out' => [['issParam' => true, 'issMissing' => true], 'has no iss, which it said it sends'];
        yield 'a person who said no' => [['denyLogin' => true], 'did not let phycode in: The user said no'];
    }

    #[DataProvider('refusals')]
    public function testALoginThatCannotBeTrustedIsRefusedBeforeAnyCodeIsUsed(array $scenario, string $message): void
    {
        $auth = $this->auth($scenario);

        try {
            $this->login($auth);
            self::fail('no error');
        } catch (PhycodeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertSame([], $this->server->lines('token-requests.jsonl'), 'no code went to the token endpoint');
        self::assertFalse($auth->loggedIn());
    }

    public function testAStrayCallbackWithAnotherStateDoesNotEndTheLogin(): void
    {
        $auth = $this->auth();
        $strays = [];
        $browser = static function (string $url) use (&$strays): void {
            $location = OAuthHttp::request('GET', $url)['headers']['location'] ?? throw new \RuntimeException('no redirect from ' . $url);
            $strays[] = self::send((string) preg_replace('/state=[^&]+/', 'state=guessed', $location));
            fclose(self::send($location));
        };

        $this->login($auth, $browser);

        self::assertSame('HTTP/1.1 400 Bad Request', rtrim((string) fgets($strays[0])));
        self::assertCount(1, $this->server->lines('token-requests.jsonl'), 'only the answer with the right state was used');
        self::assertSame(['Authorization' => 'Bearer at-1'], $auth->headers());
        foreach ($strays as $stray) {
            fclose($stray);
        }
    }

    public function testACallbackThatNamesAnotherHostDoesNotEndTheLogin(): void
    {
        $auth = $this->auth();
        $strays = [];
        $browser = static function (string $url) use (&$strays): void {
            $location = OAuthHttp::request('GET', $url)['headers']['location'] ?? throw new \RuntimeException('no redirect from ' . $url);
            $strays[] = self::send($location, 'rebound.example:' . parse_url($location, PHP_URL_PORT));
            fclose(self::send($location));
        };

        $this->login($auth, $browser);

        self::assertSame('HTTP/1.1 400 Bad Request', rtrim((string) fgets($strays[0])));
        self::assertCount(1, $this->server->lines('token-requests.jsonl'));
        self::assertSame(['Authorization' => 'Bearer at-1'], $auth->headers());
        foreach ($strays as $stray) {
            fclose($stray);
        }
    }

    public function testWhenTheBrowserDoesNotComeBackTheLoginEnds(): void
    {
        $auth = $this->auth();
        $started = microtime(true);

        try {
            $this->login($auth, static function (string $url): void {
            }, 0.6);
            self::fail('no error');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('the browser did not come back within 0.6 seconds', $error->getMessage());
            self::assertLessThan(4.0, microtime(true) - $started);
        }
    }

    public function testAChallengeThatNamesAPrivateAddressIsRefusedBeforeItIsRequested(): void
    {
        $challenge = 'Bearer resource_metadata="https://10.255.255.1/.well-known/oauth-protected-resource", scope="files:read"';
        $fixture = new FixtureServer(['/mcp' => [['status' => 401, 'headers' => ['WWW-Authenticate' => $challenge]]]]);

        try {
            $this->login(new McpOAuth('remote', $fixture->url('/mcp'), $this->vault));
            self::fail('the login must not reach a private address that the server names');
        } catch (PhycodeException $error) {
            self::assertSame('cannot find where remote signs in: refused: 10.255.255.1 is a private address', $error->getMessage());
        } finally {
            $fixture->stop();
        }
    }

    public function testAServerThatAsksForNoLoginIsSaidSo(): void
    {
        $this->server = new FakeMcpHttp([]);
        $auth = new McpOAuth('remote', $this->server->url(), $this->vault);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('did not ask for a login (HTTP 200)');

        $this->login($auth);
    }

    public static function challenges(): iterable
    {
        yield 'both parameters' => ['Bearer resource_metadata="https://a.example/.well-known/oauth-protected-resource/mcp", scope="files:read files:write"', ['resource_metadata' => 'https://a.example/.well-known/oauth-protected-resource/mcp', 'scope' => 'files:read files:write']];
        yield 'with a realm and an error' => ['Bearer realm="mcp", error="insufficient_scope", scope="x"', ['realm' => 'mcp', 'error' => 'insufficient_scope', 'scope' => 'x']];
        yield 'a value without quotes' => ['Bearer error=invalid_token', ['error' => 'invalid_token']];
        yield 'an escaped quote' => ['Bearer error_description="say \\"no\\""', ['error_description' => 'say "no"']];
        yield 'nothing' => ['', []];
        yield 'no parameters' => ['Bearer', []];
    }

    #[DataProvider('challenges')]
    public function testTheParametersOfAChallengeAreRead(string $header, array $expected): void
    {
        self::assertSame($expected, McpOAuth::parseChallenge($header));
    }

    public static function insecure(): iterable
    {
        yield 'plain http on the network' => ['http://auth.example.com/token'];
        yield 'a password in the address' => ['https://user:pw@auth.example.com/token'];
        yield 'a fragment' => ['https://auth.example.com/token#x'];
        yield 'another scheme' => ['ftp://auth.example.com/token'];
    }

    #[DataProvider('insecure')]
    public function testAnAddressThatCouldLeakACodeOrATokenIsNotUsed(string $url): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('is not an address that a login may use');

        OAuthHttp::check($url, 'the address');
    }
}
