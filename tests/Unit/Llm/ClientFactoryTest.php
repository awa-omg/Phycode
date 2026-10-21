<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Config\Config;
use Phycode\Llm\Anthropic\AnthropicClient;
use Phycode\Llm\CapabilityCache;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\ClientFactory;
use Phycode\Llm\LlmException;
use Phycode\Llm\Ollama\OllamaClient;
use Phycode\Llm\OpenAiClient;
use Phycode\Llm\Responses\ResponsesClient;
use Phycode\Llm\ToolRouteClient;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\LlamaServer;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\TestCase;

final class ClientFactoryTest extends TestCase
{
    private ?LlamaServer $server = null;
    private ?LlamaServer $backup = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->backup?->stop();
        OpenAiClient::setLoadingWait(null, null);
    }

    public function testOneClientIsKeptPerProfileModelThinkingAndProtocol(): void
    {
        $factory = $this->factory($this->config(['base_url' => 'http://127.0.0.1:9']));

        $default = $factory->forProfile('test');
        self::assertSame($default, $factory->forProfile('test'));
        self::assertSame($default, $factory->forProfile('test', 'qwen35-2b'), 'the configured model is the same client');

        $other = $factory->forProfile('test', 'other-model');
        self::assertNotSame($default, $other);
        self::assertSame($other, $factory->forProfile('test', 'other-model'));

        self::assertNotSame($default, $factory->forProfile('test', null, 'high'));
    }

    public function testTheClientCarriesTheModelAndThinkingItWasAskedFor(): void
    {
        $factory = $this->factory($this->config(['base_url' => 'http://127.0.0.1:9']));

        $client = $factory->forProfile('test', 'other-model', 'medium');

        self::assertInstanceOf(ToolRouteClient::class, $client);
        self::assertSame('other-model', $client->profile()->model);
        self::assertSame('medium', $client->profile()->thinking);
        self::assertSame('qwen35-2b', $factory->forProfile('test')->profile()->model, 'the profile itself is not changed');
    }

    public function testTheOllamaProtocolGivesTheNativeClient(): void
    {
        $factory = $this->factory($this->config(['base_url' => 'http://127.0.0.1:9', 'protocol' => 'ollama']));

        $client = $factory->forProfile('test');

        self::assertInstanceOf(OllamaClient::class, $client);
        self::assertSame('ollama', $client->profile()->protocol);
        self::assertSame($client, $factory->forProfile('test'), 'one client is kept per profile');
    }

    public function testTheAnthropicProtocolGivesTheMessagesClient(): void
    {
        $factory = $this->factory($this->config(['base_url' => 'http://127.0.0.1:9', 'protocol' => 'anthropic']));

        $client = $factory->forProfile('test');

        self::assertInstanceOf(AnthropicClient::class, $client);
        self::assertSame('anthropic', $client->profile()->protocol);
        self::assertSame($client, $factory->forProfile('test'), 'one client is kept per profile');
    }

    public function testTheOpenAiResponsesProtocolGivesTheResponsesClient(): void
    {
        $factory = $this->factory($this->config(['base_url' => 'http://127.0.0.1:9', 'protocol' => 'openai-responses']));

        $client = $factory->forProfile('test');

        self::assertInstanceOf(ResponsesClient::class, $client);
        self::assertSame('openai-responses', $client->profile()->protocol);
        self::assertSame($client, $factory->forProfile('test'), 'one client is kept per profile');
    }

    public function testTheOpenAiProtocolGivesTheRouteThatSendsRefusedToolRequestsToResponses(): void
    {
        $factory = $this->factory($this->config(['base_url' => 'http://127.0.0.1:9', 'protocol' => 'openai']));

        $client = $factory->forProfile('test', 'gpt-6-luna', 'low');

        self::assertInstanceOf(ToolRouteClient::class, $client, 'built without a request: nothing is sent to the port');
        self::assertSame('gpt-6-luna', $client->profile()->model);
        self::assertSame('openai', $client->profile()->protocol);
        self::assertSame($client, $factory->forProfile('test', 'gpt-6-luna', 'low'), 'one client is kept per profile');
    }

    public function testASwitchedProtocolDoesNotUseTheCachedToolMode(): void
    {
        $this->server = new LlamaServer(['probe' => [['fixture' => 'probe-native.sse']]]);
        $cacheFile = Kit::folder('capabilities') . '/capabilities.json';
        $base = ['base_url' => $this->server->url('probe')];

        $openai = $this->factory($this->config($base), $cacheFile);
        self::assertSame('native', $openai->toolMode('test'), 'the first run probes the server');
        self::assertCount(1, $this->server->requests());

        $again = $this->factory($this->config($base), $cacheFile);
        self::assertSame('native', $again->toolMode('test'), 'a new run reads the cache');
        self::assertCount(1, $this->server->requests(), 'no request was needed');

        $ollama = $this->factory($this->config(['base_url' => 'http://127.0.0.1:' . $this->closedPort(), 'protocol' => 'ollama']), $cacheFile);
        try {
            $ollama->toolMode('test');
            self::fail('the cache entry of the openai protocol must not be used for ollama');
        } catch (LlmException $error) {
            self::assertStringStartsWith('connection to the model server failed', $error->getMessage(), 'ollama asks its own server');
        }
        self::assertCount(1, $this->server->requests());
    }

    public function testEachModelIsProbedOnItsOwnAndAThinkingChangeReusesThatProbe(): void
    {
        $this->server = new LlamaServer(['probe' => [['fixture' => 'probe-native.sse']]]);
        $factory = $this->factory($this->config(['base_url' => $this->server->url('probe')]));

        self::assertSame('native', $factory->toolMode('test'));
        self::assertCount(1, $this->server->requests());

        self::assertSame('native', $factory->toolMode('test', 'other-model'));
        self::assertCount(2, $this->server->requests(), 'the other model has a probe of its own');

        self::assertSame('native', $factory->toolMode('test', 'other-model', 'high'));
        self::assertCount(2, $this->server->requests(), 'the probe of that model serves every thinking level');
    }

    public function testAServerThatIsStillLoadingIsProbedAgainAtTheNextCallAndNothingIsCached(): void
    {
        $this->server = new LlamaServer(['loading' => [['status' => 503, 'body' => '{"error":{"message":"Loading model"}}', 'type' => 'application/json']]]);
        OpenAiClient::setLoadingWait(0.05, 0.2);
        $cacheFile = Kit::folder('capabilities') . '/capabilities.json';
        $factory = $this->factory($this->config(['base_url' => $this->server->url('loading')]), $cacheFile);

        $sent = 0;
        foreach ([1, 2] as $call) {
            try {
                $factory->toolMode('test');
                self::fail('a server that is still loading has no tool mode to report');
            } catch (LlmException $error) {
                self::assertStringStartsWith('HTTP 503:', $error->getMessage());
            }
            self::assertGreaterThan($sent, count($this->server->requests()), "call {$call} waited for the server and asked again");
            $sent = count($this->server->requests());
        }
        self::assertFileDoesNotExist($cacheFile, 'a transient answer is not written to the cache');
    }

    public function testA429FromTheFirstProfileEndsTheTurnWithTheFallbackAndNoKeyIsShown(): void
    {
        $this->server = new LlamaServer(['busy' => [['status' => 429, 'body' => '{"error":{"message":"too many requests"}}', 'type' => 'application/json']]]);
        $this->backup = new LlamaServer(['ok' => [['fixture' => 'text.sse']]]);
        $keys = Kit::folder('keys');
        Kit::write($keys . '/main.key', "sk-main-7f3a9c\n");
        Kit::write($keys . '/backup.key', "sk-backup-2e8d41\n");
        $factory = $this->factory(Config::fromArrays(['profiles' => [
            'main' => ['base_url' => $this->server->url('busy'), 'model' => 'qwen35-2b', 'api_key_file' => $keys . '/main.key', 'fallback' => ['backup']],
            'backup' => ['base_url' => $this->backup->url('ok'), 'model' => 'qwen35-2b', 'api_key_file' => $keys . '/backup.key'],
        ]], null, [], '/nonexistent'));
        $events = [];
        $options = new ChatOptions(static function (string $type, string $text) use (&$events): void {
            $events[] = [$type, $text];
        });

        $response = $factory->forProfile('main')->chat([['role' => 'user', 'content' => 'hello']], [], $options);

        self::assertSame('The three primary colours are red, blue, and yellow.', $response->content);
        self::assertCount(3, $this->server->requests(), 'the first profile is tried once and retried twice');
        self::assertSame(['Bearer sk-backup-2e8d41'], array_column($this->backup->requests(), 'authorization'), 'the backup sends its own key');
        $notices = array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'notice'));
        self::assertCount(1, $notices, 'one notice for the switch; the streamed text is the other events');
        self::assertStringContainsString('fallback profile backup', $notices[0][1]);
        $shown = $response->content . json_encode($events, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sk-main-7f3a9c', $shown);
        self::assertStringNotContainsString('sk-backup-2e8d41', $shown);
    }

    public function testWithoutAFallbackA429ErrorsAsItDidBefore(): void
    {
        $this->server = new LlamaServer(['busy' => [['status' => 429, 'body' => '{"error":{"message":"too many requests"}}', 'type' => 'application/json']]]);
        $factory = $this->factory($this->config(['base_url' => $this->server->url('busy')]));

        self::assertInstanceOf(ToolRouteClient::class, $factory->forProfile('test'), 'no fallback: the profile has its own client');
        try {
            $factory->forProfile('test')->chat([['role' => 'user', 'content' => 'hello']], [], new ChatOptions());
            self::fail('the 429 should end the turn');
        } catch (LlmException $error) {
            self::assertSame('HTTP 429: too many requests', $error->getMessage());
        }
        self::assertCount(3, $this->server->requests(), 'one attempt and two retries');
    }

    public function testAProbeThatIsRateLimitedIsNotAnsweredByTheFallbackAndTheTurnGoesThroughIt(): void
    {
        $this->server = new LlamaServer(['busy' => [['status' => 429, 'body' => '{"error":{"message":"too many requests"}}', 'type' => 'application/json']]]);
        $this->backup = new LlamaServer(['ok' => [['fixture' => 'text.sse']]]);
        $cacheFile = Kit::folder('capabilities') . '/capabilities.json';
        $factory = $this->factory(Config::fromArrays(['profiles' => [
            'test' => ['base_url' => $this->server->url('busy'), 'model' => 'qwen35-2b', 'fallback' => ['backup']],
            'backup' => ['base_url' => $this->backup->url('ok'), 'model' => 'qwen35-2b'],
        ]], null, [], '/nonexistent'), $cacheFile);
        $events = [];
        $options = new ChatOptions(static function (string $type, string $text) use (&$events): void {
            $events[] = [$type, $text];
        });

        self::assertSame('text', $factory->toolMode('test'), 'the rate-limited probe leaves this turn on the text protocol');
        $response = $factory->forProfile('test')->chat([['role' => 'user', 'content' => 'hello']], [], $options);

        self::assertSame('The three primary colours are red, blue, and yellow.', $response->content, 'the turn completes with the fallback');
        self::assertCount(1, $this->backup->requests(), 'the fallback answers the turn only, not the probe');
        self::assertFileDoesNotExist($cacheFile, 'a rate-limited probe is not cached');
        $notices = array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'notice'));
        self::assertCount(1, $notices, 'the switch is announced');
        self::assertStringContainsString('fallback profile backup', $notices[0][1]);
    }

    public function testAProbeThatIsRateLimitedWithoutAFallbackStillEndsTheTurn(): void
    {
        $this->server = new LlamaServer(['busy' => [['status' => 429, 'body' => '{"error":{"message":"too many requests"}}', 'type' => 'application/json']]]);
        $factory = $this->factory($this->config(['base_url' => $this->server->url('busy')]));

        try {
            $factory->toolMode('test');
            self::fail('a rate-limited probe without a fallback has no answer to report');
        } catch (LlmException $error) {
            self::assertSame('HTTP 429: too many requests', $error->getMessage());
        }
    }

    public function testARefusedRouteIsKeptInTheCacheAndTheNextClientStartsOnResponses(): void
    {
        $protocol = new ProtocolServer(['chat' => [
            '/v1/chat/completions' => [['status' => 400, 'body' => json_encode(['error' => ['message' => 'Function tools with reasoning_effort are not supported for gpt-6-luna.', 'param' => 'reasoning_effort']], JSON_THROW_ON_ERROR), 'type' => 'application/json']],
            '/v1/responses' => [['fixture' => 'responses/text.sse']],
        ]]);
        try {
            $cacheFile = Kit::folder('capabilities') . '/capabilities.json';
            $config = Config::fromArrays(['profiles' => ['test' => [
                'base_url' => $protocol->url('chat'), 'model' => 'gpt-6-luna', 'protocol' => 'openai', 'thinking' => 'low',
            ]]], null, [], '/nonexistent');
            $messages = [['role' => 'user', 'content' => 'hello']];

            $this->factory($config, $cacheFile)->forProfile('test')->chat($messages, [], new ChatOptions());
            self::assertSame(['/chat/v1/chat/completions', '/chat/v1/responses'], array_column($protocol->requests(), 'path'));
            self::assertSame('responses', (new CapabilityCache($cacheFile))->route(CapabilityCache::key($config->profile('test'))), 'the switch is kept');

            $this->factory($config, $cacheFile)->forProfile('test')->chat($messages, [], new ChatOptions());
            self::assertSame(
                ['/chat/v1/chat/completions', '/chat/v1/responses', '/chat/v1/responses'],
                array_column($protocol->requests(), 'path'),
                'a new client reads the route and does not ask Chat Completions again',
            );
        } finally {
            $protocol->stop();
        }
    }

    private function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($server, $message);
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }

    public function testWhenTheProbeFindsNoToolCallingTheReasonIsKeptForTheNotice(): void
    {
        $this->server = new LlamaServer(['nope' => [['status' => 400, 'body' => '{"error":{"message":"Invalid request"}}', 'type' => 'application/json']]]);
        $factory = $this->factory(Config::fromArrays(['profiles' => ['test' => ['base_url' => $this->server->url('nope'), 'model' => 'm']]], null, [], '/nonexistent'));

        self::assertNull($factory->whyNoTools('test'), 'nothing is known before the probe');
        self::assertSame('none', $factory->toolMode('test'));

        self::assertStringContainsString('HTTP 400: Invalid request', (string) $factory->whyNoTools('test'));
    }

    private function config(array $fields): Config
    {
        return Config::fromArrays(['profiles' => ['test' => ['model' => 'qwen35-2b'] + $fields]], null, [], '/nonexistent');
    }

    private function factory(Config $config, ?string $cacheFile = null): ClientFactory
    {
        return new ClientFactory($config, new CapabilityCache($cacheFile ?? Kit::folder('capabilities') . '/capabilities.json'));
    }
}
