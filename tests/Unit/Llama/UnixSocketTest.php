<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llama;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\OpenAiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnixSocketTest extends TestCase
{
    private ?LlamaFake $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        putenv('PHYCODE_TEST_LLAMA_KEY');
    }

    public function testASocketProfileNamesThePathAndAddressesTheServerAsLocalhost(): void
    {
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'unix:///tmp/opencode/x.sock', 'model' => 'm']]], null, [], '/nonexistent')->profile('test');

        self::assertSame('/tmp/opencode/x.sock', $profile->unixSocket());
        self::assertSame('http://localhost/v1/models', $profile->url('/v1/models'));
    }

    public function testAnEnvironmentBaseUrlWithATrailingSlashIsNotAddressedWithADoubleSlash(): void
    {
        $env = Config::fromArrays(null, null, ['PHYCODE_BASE_URL' => 'http://127.0.0.1:8082/', 'PHYCODE_MODEL' => 'm'], '/nonexistent')->profile();
        $socket = Config::fromArrays(null, null, ['PHYCODE_BASE_URL' => 'unix:///tmp/opencode/x.sock/', 'PHYCODE_MODEL' => 'm'], '/nonexistent')->profile();

        self::assertSame('http://127.0.0.1:8082/v1/models', $env->url('/v1/models'));
        self::assertSame('/tmp/opencode/x.sock', $socket->unixSocket());
    }

    public function testAnHttpProfileHasNoSocketAndKeepsItsOwnAddress(): void
    {
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'http://127.0.0.1:8082/', 'model' => 'm']]], null, [], '/nonexistent')->profile('test');

        self::assertNull($profile->unixSocket());
        self::assertSame('http://127.0.0.1:8082', $profile->baseUrl);
        self::assertSame('http://127.0.0.1:8082/v1/models', $profile->url('/v1/models'));
    }

    public static function socketAddresses(): iterable
    {
        yield 'absolute path' => ['unix:///tmp/opencode/x.sock', true];
        yield 'trailing slash' => ['unix:///tmp/opencode/x.sock/', true];
        yield 'relative path' => ['unix://x.sock', false];
        yield 'not a socket file' => ['unix:///tmp/opencode/x', false];
        yield 'space in the path' => ['unix:///tmp/open code/x.sock', false];
    }

    #[DataProvider('socketAddresses')]
    public function testTheConfigAcceptsOnlyAbsoluteSocketPaths(string $address, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(ConfigException::class);
            $this->expectExceptionMessage('or unix:///absolute/path.sock');
        }
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => $address, 'model' => 'm']]], null, [], '/nonexistent')->profile('test');

        self::assertSame('/tmp/opencode/x.sock', $profile->unixSocket());
    }

    public function testTheListChatAndDiscoveryReachTheServerThroughTheSocketWithTheKey(): void
    {
        putenv('PHYCODE_TEST_LLAMA_KEY=socket-key');
        $this->server = new LlamaFake([
            'GET /props' => [['fixture' => 'props.json']],
            'GET /v1/models' => [['fixture' => 'v1-models.json']],
            'POST /v1/chat/completions' => [['fixture' => 'probe-native.sse']],
        ], socket: true);
        $client = new OpenAiClient($this->server->profile(['api_key_env' => 'PHYCODE_TEST_LLAMA_KEY']));

        $models = $client->listModels();
        $reply = $client->chat([['role' => 'user', 'content' => 'hi']], [], new ChatOptions());

        self::assertSame(16384, $models[0]->contextWindow);
        self::assertSame('probe_echo', $reply->toolCalls[0]->name);
        $requests = $this->server->requests();
        self::assertSame(['GET /props', 'GET /v1/models', 'POST /v1/chat/completions'], array_map(static fn (array $request): string => $request['method'] . ' ' . $request['path'], $requests));
        foreach ($requests as $request) {
            self::assertSame('localhost', $request['host'], 'the request goes to the socket with localhost as its host');
            self::assertSame('Bearer socket-key', $request['authorization']);
        }
    }

    public function testAPlainListingOverTheSocketAlsoWorksWhenTheServerIsNotLlamaCpp(): void
    {
        $this->server = new LlamaFake([
            'GET /props' => [['status' => 404, 'body' => '{"error":{"message":"not found"}}']],
            'GET /v1/models' => [['body' => '{"object":"list","data":[{"id":"other","object":"model"}]}']],
        ], socket: true);

        $models = (new OpenAiClient($this->server->profile()))->listModels();

        self::assertSame('other', $models[0]->id);
        self::assertSame('localhost', $this->server->requests()[1]['host']);
    }
}
