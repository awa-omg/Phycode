<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llama;

use Phycode\Config\Config;
use Phycode\Config\Profile;
use Phycode\Llm\Llama\LlamaDoctor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LlamaDoctorTest extends TestCase
{
    private ?LlamaFake $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        putenv('PHYCODE_TEST_LLAMA_KEY');
    }

    public function testAHealthySingleModelServerPassesEveryCheck(): void
    {
        $server = $this->serve($this->healthy());

        $lines = LlamaDoctor::report($server->profile());

        self::assertSame(['llama health', 'llama server', 'llama model', 'llama router mode', 'llama context', 'llama slots', 'llama template', 'llama tools'], $this->labels($lines));
        self::assertSame(true, $this->line($lines, 'llama health')['pass']);
        self::assertSame("test: ok at {$server->url()}", $this->line($lines, 'llama health')['detail']);
        self::assertSame('llama.cpp b11721-ff5a23376', $this->line($lines, 'llama server')['detail']);
        self::assertSame('qwen35-2b (the profile asks for qwen35-2b)', $this->line($lines, 'llama model')['detail']);
        self::assertNull($this->line($lines, 'llama router mode')['pass'], 'a single-model server is information, not a failure');
        self::assertSame('no (single-model server)', $this->line($lines, 'llama router mode')['detail']);
        self::assertSame('16384 tokens', $this->line($lines, 'llama context')['detail']);
        self::assertSame('1 total: 1 idle, 0 busy', $this->line($lines, 'llama slots')['detail']);
        self::assertSame('native tool calls work', $this->line($lines, 'llama tools')['detail']);
        self::assertSame([], $this->fixes($lines));
    }

    public function testWithoutJinjaTheToolsFailAndTheFixNamesThePortOfTheProfile(): void
    {
        $server = $this->serve($this->healthy([
            'GET /props' => [['fixture' => 'props-no-jinja.json']],
            'POST /v1/chat/completions' => [['fixture' => 'tool-no-jinja.json', 'status' => 500]],
        ]));

        $lines = LlamaDoctor::report($server->profile(['context_limit' => 2048]));
        $port = parse_url($server->url(), PHP_URL_PORT);

        self::assertFalse($this->line($lines, 'llama tools')['pass']);
        self::assertSame('native tool calls need --jinja (HTTP 500: tools param requires --jinja flag)', $this->line($lines, 'llama tools')['detail']);
        self::assertSame(["restart llama-server with --jinja on port {$port} (this build enables --jinja by default; check with llama-server --help)"], $this->fixes($lines));
        self::assertTrue($this->line($lines, 'llama template')['pass'], 'the template is still there without jinja; the tools are what breaks');
    }

    public function testASlotsEndpointThatIsOffGetsTheSlotsFix(): void
    {
        $server = $this->serve($this->healthy(['GET /slots' => [['fixture' => 'slots-disabled.json', 'status' => 501]]]));

        $lines = LlamaDoctor::report($server->profile());
        $port = parse_url($server->url(), PHP_URL_PORT);

        self::assertNull($this->line($lines, 'llama slots')['pass']);
        self::assertSame('the slots endpoint is off', $this->line($lines, 'llama slots')['detail']);
        self::assertSame(["restart llama-server with --slots on port {$port}"], $this->fixes($lines));
    }

    public function testAContextSmallerThanTheConfiguredLimitGetsTheContextFix(): void
    {
        $server = $this->serve($this->healthy(['GET /props' => [['fixture' => 'props-model.json']]]));

        $lines = LlamaDoctor::report($server->profile());
        $port = parse_url($server->url(), PHP_URL_PORT);

        self::assertNull($this->line($lines, 'llama context')['pass']);
        self::assertSame('4096 tokens, below context_limit 16384', $this->line($lines, 'llama context')['detail']);
        self::assertSame(["restart llama-server with -c 16384 on port {$port}"], $this->fixes($lines));
    }

    public function testAnUnreachableServerIsNamedWithTheFlagsThatStartItOnTheProfilePort(): void
    {
        $port = self::closedPort();
        $profile = $this->profileAt("http://127.0.0.1:{$port}");

        $lines = LlamaDoctor::report($profile);

        self::assertFalse($this->line($lines, 'llama health')['pass']);
        self::assertStringStartsWith("test: cannot reach http://127.0.0.1:{$port} (connection to http://127.0.0.1:{$port}/health failed", $this->line($lines, 'llama health')['detail']);
        self::assertSame(["start llama-server with -m <model.gguf> --host 127.0.0.1 --port {$port} --jinja"], $this->fixes($lines));
    }

    public function testAMissingSocketIsNamedWithTheSocketFlag(): void
    {
        $socket = '/tmp/opencode/phpunit/no-such-llama-' . bin2hex(random_bytes(4)) . '/llama.sock';

        $lines = LlamaDoctor::report($this->profileAt('unix://' . $socket));

        self::assertFalse($this->line($lines, 'llama health')['pass']);
        self::assertSame(["start llama-server with -m <model.gguf> --host {$socket} --jinja"], $this->fixes($lines));
    }

    public function testAServerThatIsStillLoadingIsNotReportedAsUnreachable(): void
    {
        $server = $this->serve($this->healthy(['GET /health' => [['fixture' => 'health-loading.json', 'status' => 503]]]));

        $lines = LlamaDoctor::report($server->profile());

        self::assertFalse($this->line($lines, 'llama health')['pass']);
        self::assertSame('test: the model is still loading (HTTP 503)', $this->line($lines, 'llama health')['detail']);
        self::assertSame(['wait until the model has loaded, then run doctor again'], $this->fixes($lines));
        self::assertSame(['llama health'], $this->labels($lines), 'nothing else is asked of a server that is loading');
    }

    public function testAServerThatIsNotLlamaCppIsInformationAndNoFailure(): void
    {
        $server = $this->serve([
            'GET /health' => [['fixture' => 'health.json']],
            'GET /props' => [['status' => 404, 'body' => '{"error":{"message":"File Not Found"}}']],
        ]);

        $lines = LlamaDoctor::report($server->profile());

        self::assertSame(['llama health', 'llama server'], $this->labels($lines));
        self::assertNull($this->line($lines, 'llama server')['pass']);
        self::assertSame('not llama.cpp: HTTP 404: File Not Found', $this->line($lines, 'llama server')['detail']);
        self::assertNotContains(false, array_column($lines, 'pass'));
    }

    public function testAWrongApiKeyIsAFailureWithTheKeyFix(): void
    {
        $server = $this->serve([
            'GET /health' => [['fixture' => 'health.json']],
            'GET /props' => [['status' => 401, 'body' => '{"error":{"message":"Invalid API Key","type":"authentication_error","code":401}}']],
        ]);

        $lines = LlamaDoctor::report($server->profile());

        self::assertFalse($this->line($lines, 'llama server')['pass']);
        self::assertSame('the server rejects the API key (HTTP 401: Invalid API Key)', $this->line($lines, 'llama server')['detail']);
        self::assertSame(['set api_key_env or api_key_file in profile test; the key is sent as Authorization: Bearer'], $this->fixes($lines));
        self::assertSame(['GET /health', 'GET /props'], $this->requestLines($server), 'an unknown router mode gets no test request');
    }

    public function testARouterModelThatIsNotLoadedIsNotLoadedByTheDoctor(): void
    {
        $server = $this->serve([
            'GET /health' => [['fixture' => 'health.json']],
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /models' => [['fixture' => 'models-router-unloaded.json']],
        ]);

        $lines = LlamaDoctor::report($server->profile(model: 'Qwen3.5-2B-Q8_0'));

        self::assertSame('yes (1 models, 0 loaded)', $this->line($lines, 'llama router mode')['detail']);
        self::assertSame('Qwen3.5-2B-Q8_0 is not loaded; its first request loads it, so the checks below wait', $this->line($lines, 'llama model')['detail']);
        self::assertSame(['GET /health', 'GET /props', 'GET /models'], $this->requestLines($server), 'no chat request and no model-specific read');
    }

    public function testARouterThatDoesNotListTheProfileModelNamesTheListedOnes(): void
    {
        $server = $this->serve([
            'GET /health' => [['fixture' => 'health.json']],
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /models' => [['fixture' => 'models-router-loaded.json']],
        ]);

        $lines = LlamaDoctor::report($server->profile(model: 'other-model'));

        self::assertFalse($this->line($lines, 'llama model')['pass']);
        self::assertSame('other-model is not listed by the router (listed: Qwen3.5-2B-Q8_0)', $this->line($lines, 'llama model')['detail']);
        self::assertSame(['set model in profile test to one of the listed names, or add other-model to the --models-dir or --models-preset of llama-server'], $this->fixes($lines));
    }

    public function testALoadedRouterModelGetsItsOwnContextSlotsAndToolCheck(): void
    {
        $server = $this->serve([
            'GET /health' => [['fixture' => 'health.json']],
            'GET /props' => [['fixture' => 'props-router.json'], ['fixture' => 'props-model.json']],
            'GET /models' => [['fixture' => 'models-router-loaded.json']],
            'GET /slots' => [['fixture' => 'slots-router.json']],
            'POST /v1/chat/completions' => [['fixture' => 'probe-native.sse']],
        ]);

        $lines = LlamaDoctor::report($server->profile(['context_limit' => 4096], 'Qwen3.5-2B-Q8_0'));

        self::assertSame(['llama health', 'llama server', 'llama router mode', 'llama model', 'llama context', 'llama slots', 'llama template', 'llama tools'], $this->labels($lines));
        self::assertSame('yes (1 models, 1 loaded)', $this->line($lines, 'llama router mode')['detail']);
        self::assertSame('4096 tokens', $this->line($lines, 'llama context')['detail']);
        self::assertSame('4 total: 4 idle, 0 busy', $this->line($lines, 'llama slots')['detail']);
        self::assertSame(['GET /health', 'GET /props', 'GET /models', 'GET /props?model=Qwen3.5-2B-Q8_0', 'GET /slots?model=Qwen3.5-2B-Q8_0', 'POST /v1/chat/completions'], $this->requestLines($server));
        self::assertSame(['model' => 'Qwen3.5-2B-Q8_0'], $server->requests()[4]['query'], 'the slots are asked for the loaded model');
    }

    public function testAHealthyServerOnASocketIsCheckedThroughTheSocket(): void
    {
        $server = new LlamaFake($this->healthy(), socket: true);
        $this->server = $server;

        $lines = LlamaDoctor::report($server->profile());

        self::assertSame("test: ok at {$server->url()}", $this->line($lines, 'llama health')['detail']);
        self::assertSame([], $this->fixes($lines));
        foreach ($server->requests() as $request) {
            self::assertSame('localhost', $request['host']);
        }
    }

    public static function serverAddresses(): iterable
    {
        yield 'unix socket' => ['unix:///tmp/opencode/x.sock', 'openai', true];
        yield 'loopback' => ['http://127.0.0.1:8082', 'openai', true];
        yield 'loopback name' => ['http://localhost:8080', 'openai', true];
        yield 'private network' => ['http://192.168.1.10:8080', 'openai', true];
        yield 'ten network' => ['http://10.0.0.5:8080', 'openai', true];
        yield 'single label name' => ['http://gpu-box:8080', 'openai', true];
        yield 'ipv6 loopback' => ['http://[::1]:8082', 'openai', true];
        yield 'hosted' => ['https://api.openai.com', 'openai', false];
        yield 'public address' => ['http://8.8.8.8:8080', 'openai', false];
        yield 'ollama protocol' => ['http://127.0.0.1:11434', 'ollama', false];
    }

    #[DataProvider('serverAddresses')]
    public function testOnlyLocalOpenAiProfilesGetTheLlamaBlock(string $baseUrl, string $protocol, bool $applies): void
    {
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => $baseUrl, 'model' => 'm', 'protocol' => $protocol]]], null, [], '/nonexistent')->profile('test');

        self::assertSame($applies, LlamaDoctor::applies($profile));
    }

    public static function probeDecisions(): iterable
    {
        $remote = 'skipped: remote server (use phycode run to test it)';
        yield 'unix socket' => ['unix:///tmp/opencode/x.sock', 'openai', null];
        yield 'loopback' => ['http://127.0.0.1:8082', 'openai', null];
        yield 'another loopback address' => ['http://127.0.0.2:8082', 'openai', null];
        yield 'localhost' => ['http://localhost:8080', 'openai', null];
        yield 'ipv6 loopback' => ['http://[::1]:8082', 'openai', null];
        yield 'private network' => ['http://192.168.1.10:8080', 'openai', $remote];
        yield 'single label name' => ['http://gpu-box:8080', 'openai', $remote];
        yield 'hosted' => ['https://api.openai.com', 'openai', $remote];
        yield 'ollama on loopback' => ['http://127.0.0.1:11434', 'ollama', 'skipped: protocol ollama; doctor checks llama.cpp servers only'];
    }

    #[DataProvider('probeDecisions')]
    public function testOnlyALoopbackOpenAiServerIsProbed(string $baseUrl, string $protocol, ?string $skip): void
    {
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => $baseUrl, 'model' => 'm', 'protocol' => $protocol]]], null, [], '/nonexistent')->profile('test');

        self::assertSame($skip, LlamaDoctor::skipReason($profile));
    }

    public function testAServerOnAnotherHostIsReportedAsSkippedWithoutAnyRequest(): void
    {
        $lines = LlamaDoctor::report($this->profileAt('http://192.168.1.10:8080'));

        self::assertSame([['pass' => null, 'label' => 'llama health', 'detail' => 'skipped: remote server (use phycode run to test it)']], $lines);
    }

    public function testAnOllamaProfileIsNeverProbed(): void
    {
        $server = $this->serve($this->healthy());

        $lines = LlamaDoctor::report($server->profile(['protocol' => 'ollama']));

        self::assertSame(['skipped: protocol ollama; doctor checks llama.cpp servers only'], array_column($lines, 'detail'));
        self::assertSame([], $server->requests(), 'no health check and no chat request reach an Ollama server');
    }

    private function healthy(array $overrides = []): array
    {
        return $overrides + [
            'GET /health' => [['fixture' => 'health.json']],
            'GET /props' => [['fixture' => 'props.json']],
            'GET /v1/models' => [['fixture' => 'v1-models.json']],
            'GET /slots' => [['fixture' => 'slots.json']],
            'POST /v1/chat/completions' => [['fixture' => 'probe-native.sse']],
        ];
    }

    private function serve(array $routes): LlamaFake
    {
        $this->server?->stop();

        return $this->server = new LlamaFake($routes);
    }

    private function profileAt(string $baseUrl): Profile
    {
        return Config::fromArrays(['profiles' => ['test' => ['base_url' => $baseUrl, 'model' => 'qwen35-2b']]], null, [], '/nonexistent')->profile('test');
    }

    private static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($socket, $message);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }

    private function line(array $lines, string $label): array
    {
        foreach ($lines as $line) {
            if ($line['label'] === $label) {
                return $line;
            }
        }
        self::fail("no line named {$label}");
    }

    private function labels(array $lines): array
    {
        return array_values(array_filter(array_column($lines, 'label'), static fn (string $label): bool => $label !== 'fix'));
    }

    private function fixes(array $lines): array
    {
        return array_values(array_column(array_filter($lines, static fn (array $line): bool => $line['label'] === 'fix'), 'detail'));
    }

    private function requestLines(LlamaFake $server): array
    {
        return array_map(static fn (array $request): string => $request['method'] . ' ' . $request['path'] . ($request['query'] === [] ? '' : '?' . http_build_query($request['query'])), $server->requests());
    }
}
