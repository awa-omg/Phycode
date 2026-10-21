<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Config\Config;
use Phycode\Llm\OpenAiClient;
use Phycode\Llm\Probe;
use Phycode\Tests\Support\LlamaServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProbeTest extends TestCase
{
    private ?LlamaServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        OpenAiClient::setLoadingWait(null, null);
    }

    public function testANativeCallMakesTheProbeNative(): void
    {
        $server = $this->serve(['probe' => [['fixture' => 'probe-native.sse']]]);

        $result = Probe::run(new OpenAiClient($server->profile('probe')));

        self::assertSame(['mode' => Probe::NATIVE, 'error' => null, 'transient' => false], $result);
        $request = $server->requests()[0]['json'];
        self::assertSame('probe_echo', $request['tools'][0]['function']['name']);
        self::assertSame('auto', $request['tool_choice']);
        self::assertFalse($request['parallel_tool_calls']);
        self::assertEquals(0.0, $request['temperature'], 'the probe asks for deterministic output');
    }

    public function testATextCallMakesTheProbeTextAfterTheNativeAttemptFails(): void
    {
        $server = $this->serve(['probe' => [['fixture' => 'text.sse'], ['fixture' => 'probe-text.sse']]]);

        $result = Probe::run(new OpenAiClient($server->profile('probe')));

        self::assertSame(['mode' => Probe::TEXT, 'error' => null, 'transient' => false], $result);
        $requests = $server->requests();
        self::assertCount(2, $requests);
        self::assertArrayHasKey('tools', $requests[0]['json']);
        self::assertArrayNotHasKey('tools', $requests[1]['json'], 'the text attempt offers no native tools');
        self::assertStringStartsWith('To use a tool, reply with exactly one block', $requests[1]['json']['messages'][0]['content']);
    }

    public function testNoUsableFormMakesTheProbeNone(): void
    {
        $server = $this->serve(['probe' => [['fixture' => 'text.sse'], ['fixture' => 'text.sse']]]);

        $result = Probe::run(new OpenAiClient($server->profile('probe')));

        self::assertSame(Probe::NONE, $result['mode']);
        self::assertSame('the model did not return a native tool call; no text tool call either', $result['error']);
        self::assertFalse($result['transient']);
    }

    public function testAnUnreachableServerIsTransient(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($socket, $message);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'http://' . $address, 'model' => 'qwen35-2b']]], null, [], '/nonexistent')->profile('test');

        $result = Probe::run(new OpenAiClient($profile, [0.0, 0.0]));

        self::assertSame(Probe::NONE, $result['mode']);
        self::assertTrue($result['transient']);
        self::assertStringStartsWith('connection to the model server failed', (string) $result['error']);
    }

    public static function busyStatuses(): array
    {
        return ['too many requests' => [429], 'bad gateway' => [502], 'gateway timeout' => [504]];
    }

    #[DataProvider('busyStatuses')]
    public function testAServerThatStaysBusyPastTheRetriesIsTransient(int $status): void
    {
        $server = $this->serve(['busy' => [['status' => $status, 'body' => '{"error":{"message":"Loading model"}}', 'type' => 'application/json']]]);

        $result = Probe::run(new OpenAiClient($server->profile('busy'), [0.0, 0.0]));

        self::assertSame(['mode' => Probe::NONE, 'error' => "HTTP {$status}: Loading model", 'transient' => true], $result);
        self::assertCount(3, $server->requests(), 'one attempt and two retries, and no text attempt after the native one');
    }

    public function testAServerThatStaysLoadingPastTheWaitIsTransient(): void
    {
        $server = $this->serve(['loading' => [['status' => 503, 'body' => '{"error":{"message":"Loading model"}}', 'type' => 'application/json']]]);
        OpenAiClient::setLoadingWait(0.05, 0.2);

        $result = Probe::run(new OpenAiClient($server->profile('loading')));

        self::assertSame(['mode' => Probe::NONE, 'error' => 'HTTP 503: llama-server did not finish loading the model in 0.2 s; check the server', 'transient' => true], $result);
        self::assertGreaterThan(1, count($server->requests()), 'the probe waited and asked again before it gave up');
    }

    public function testAnErrorThatIsNotBusyIsNotTransient(): void
    {
        $server = $this->serve(['probe' => [['status' => 401, 'body' => '{"error":{"message":"Invalid API Key"}}', 'type' => 'application/json']]]);

        $result = Probe::run(new OpenAiClient($server->profile('probe'), [0.0, 0.0]));

        self::assertSame(Probe::NONE, $result['mode']);
        self::assertFalse($result['transient'], 'a rejected key is an answer, so the process keeps it');
    }

    private function serve(array $scenarios): LlamaServer
    {
        $this->server?->stop();

        return $this->server = new LlamaServer($scenarios);
    }
}
