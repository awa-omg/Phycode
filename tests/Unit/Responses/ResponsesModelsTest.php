<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Responses;

use Phycode\Llm\LlmException;
use Phycode\Llm\Responses\ResponsesClient;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\TestCase;

final class ResponsesModelsTest extends TestCase
{
    private ?ProtocolServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testTheListIsReadFromV1ModelsWithTheWindowThatTheServerReports(): void
    {
        $server = $this->serve([['fixture' => 'llama/v1-models.json', 'type' => 'application/json']]);

        $models = (new ResponsesClient($server->profile('models', ['protocol' => 'openai-responses'])))->listModels();

        self::assertSame(['qwen35-2b'], array_map(static fn ($model): string => $model->id, $models));
        self::assertSame([16384, 'openai-responses', 'v1/models'], [$models[0]->contextWindow, $models[0]->protocol, $models[0]->source]);
        self::assertSame('GET', $server->requests()[0]['method']);
        self::assertSame('/models/v1/models', $server->requests()[0]['path']);
    }

    public function testDescribeGivesTheListedWindowAndAnUnlistedModelNothing(): void
    {
        $server = $this->serve([['fixture' => 'llama/v1-models.json', 'type' => 'application/json']]);
        $client = new ResponsesClient($server->profile('models', ['protocol' => 'openai-responses']));

        $known = $client->describe('qwen35-2b');
        $unknown = $client->describe('gpt-5');

        self::assertSame([16384, 'qwen35-2b'], [$known->contextWindow, $known->id]);
        self::assertSame(['gpt-5', null, null], [$unknown->id, $unknown->contextWindow, $unknown->tools]);
    }

    public function testAModelWithoutAWindowIsListedWithoutOne(): void
    {
        $server = $this->serve([['body' => '{"data":[{"id":"gpt-5","object":"model"}]}', 'type' => 'application/json']]);

        $models = (new ResponsesClient($server->profile('models', ['protocol' => 'openai-responses'])))->listModels();

        self::assertSame([['gpt-5', null]], array_map(static fn ($model): array => [$model->id, $model->contextWindow], $models));
    }

    public function testAnErrorReplyIsAnExceptionWithTheServersMessage(): void
    {
        $server = $this->serve([['status' => 401, 'body' => '{"error":{"message":"Invalid API Key"}}', 'type' => 'application/json']]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('HTTP 401: Invalid API Key');
        (new ResponsesClient($server->profile('models', ['protocol' => 'openai-responses'])))->listModels();
    }

    public function testAReplyThatIsNotAModelListIsAnError(): void
    {
        $server = $this->serve([['body' => '{"ok":true}', 'type' => 'application/json']]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('did not return a model list');
        (new ResponsesClient($server->profile('models', ['protocol' => 'openai-responses'])))->listModels();
    }

    private function serve(array $steps): ProtocolServer
    {
        $this->server?->stop();

        return $this->server = new ProtocolServer(['models' => ['/v1/models' => $steps]]);
    }
}
