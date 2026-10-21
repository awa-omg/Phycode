<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Config\Config;
use Phycode\Llm\LlmException;
use Phycode\Llm\OpenAiClient;
use Phycode\Tests\Support\LlamaServer;
use PHPUnit\Framework\TestCase;

final class ModelListTest extends TestCase
{
    private ?LlamaServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        putenv('PHYCODE_TEST_KEY');
    }

    public function testTheListIsReadWithItsContextWindowAndSource(): void
    {
        $server = $this->serve(['models' => [['fixture' => 'v1-models.json', 'type' => 'application/json']]]);

        $models = (new OpenAiClient($server->profile('models')))->listModels();

        self::assertCount(1, $models);
        self::assertSame('qwen35-2b', $models[0]->id);
        self::assertSame('test', $models[0]->profile);
        self::assertSame('openai', $models[0]->protocol);
        self::assertSame(16384, $models[0]->contextWindow, 'meta.n_ctx is the context the server runs with');
        self::assertNull($models[0]->tools);
        self::assertSame([], $models[0]->thinkingLevels);
        self::assertNull($models[0]->loaded);
        self::assertSame('v1/models', $models[0]->source);
        self::assertSame('GET', $server->requests()[0]['method']);
    }

    public function testDescribeReturnsTheListedModelAndAnEmptyDescriptionForAnUnlistedOne(): void
    {
        $server = $this->serve(['models' => [['fixture' => 'v1-models.json', 'type' => 'application/json']]]);
        $client = new OpenAiClient($server->profile('models'));

        $listed = $client->describe('qwen35-2b');
        self::assertSame(16384, $listed->contextWindow);
        self::assertSame('v1/models', $listed->source);

        $unlisted = $client->describe('some-other-name');
        self::assertSame('some-other-name', $unlisted->id);
        self::assertSame('test', $unlisted->profile);
        self::assertNull($unlisted->contextWindow);
        self::assertNull($unlisted->source);
    }

    public function testTheAuthorizationHeaderIsSentWithTheListRequest(): void
    {
        putenv('PHYCODE_TEST_KEY=list-key');
        $server = $this->serve(['models' => [['fixture' => 'v1-models.json', 'type' => 'application/json']]]);

        (new OpenAiClient($server->profile('models', ['api_key_env' => 'PHYCODE_TEST_KEY'])))->listModels();

        self::assertSame('Bearer list-key', $server->requests()[0]['authorization']);
    }

    public function testAnErrorStatusIsReportedWithTheServerMessage(): void
    {
        $server = $this->serve(['models' => [['status' => 401, 'body' => '{"error":{"message":"Invalid API Key"}}', 'type' => 'application/json']]]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('HTTP 401: Invalid API Key');
        (new OpenAiClient($server->profile('models')))->listModels();
    }

    public function testAReplyThatIsNotAModelListIsAnError(): void
    {
        $server = $this->serve(['models' => [['body' => '{"ok":true}', 'type' => 'application/json']]]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('did not return a model list');
        (new OpenAiClient($server->profile('models')))->listModels();
    }

    public function testAnUnreachableServerIsAnError(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($socket, $message);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'http://' . $address, 'model' => 'qwen35-2b']]], null, [], '/nonexistent')->profile('test');

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('connection to http://' . $address . '/v1/models failed');
        (new OpenAiClient($profile))->listModels();
    }

    private function serve(array $scenarios): LlamaServer
    {
        $this->server?->stop();

        return $this->server = new LlamaServer($scenarios);
    }
}
