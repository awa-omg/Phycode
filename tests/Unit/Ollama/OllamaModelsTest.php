<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ollama;

use Phycode\Config\Config;
use Phycode\Llm\LlmException;
use Phycode\Llm\Ollama\OllamaClient;
use Phycode\Tests\Support\OllamaServer;
use PHPUnit\Framework\TestCase;

final class OllamaModelsTest extends TestCase
{
    private const JSON = 'application/json';

    private ?OllamaServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        putenv('PHYCODE_TEST_KEY');
    }

    public function testTheListingIsReadFromTheRecordedTagsAndKeepsAnEmbeddingOnlyModelMarkedAsOne(): void
    {
        $server = $this->serve(['models' => ['/api/tags' => [['fixture' => 'tags.json', 'type' => self::JSON]]]]);

        $models = (new OllamaClient($server->profile('models')))->listModels();

        self::assertSame(['nomic-embed-text:latest'], array_map(static fn ($model): string => $model->id, $models), 'every model the server lists is listed');
        self::assertSame('embedding', $models[0]->kind, 'nomic-embed-text has only the embedding capability, so it is not for chat');
        self::assertSame('GET', $server->requests()[0]['method']);
        self::assertSame('/models/api/tags', $server->requests()[0]['path']);
    }

    public function testTheRecordedListingGivesGemmaItsToolsAndTheEmbeddingModelItsKind(): void
    {
        $server = $this->serve(['models' => ['/api/tags' => [['fixture' => 'live/tags-gemma4-e2b.json', 'type' => self::JSON]]]]);

        $models = (new OllamaClient($server->profile('models')))->listModels();

        $byId = array_column(array_map(static fn ($model): array => ['id' => $model->id, 'model' => $model], $models), 'model', 'id');
        self::assertSame(['gemma4:e2b', 'nomic-embed-text:latest'], array_keys($byId));
        self::assertTrue($byId['gemma4:e2b']->tools, 'its capabilities include tools');
        self::assertNull($byId['gemma4:e2b']->kind);
        self::assertSame('embedding', $byId['nomic-embed-text:latest']->kind);
        self::assertSame('tags', $byId['gemma4:e2b']->source);
    }

    public function testABaseUrlThatEndsInV1ReachesTheSameRoutes(): void
    {
        $server = $this->serve(['models' => ['/api/tags' => [['fixture' => 'live/tags-gemma4-e2b.json', 'type' => self::JSON]]]]);
        $profile = $server->profile('models');

        $models = (new OllamaClient($profile->override($profile->baseUrl . '/v1', null)))->listModels();

        self::assertContains('gemma4:e2b', array_map(static fn ($model): string => $model->id, $models));
        self::assertSame('/models/api/tags', $server->requests()[0]['path'], 'the /v1 is dropped, as the other clients drop it');
    }

    public function testTheRecordedShowAndRunningListGiveGemmaItsWindowAndItsThinkingSwitch(): void
    {
        $server = $this->serve(['models' => [
            '/api/show' => [['fixture' => 'live/show-gemma4-e2b.json', 'type' => self::JSON]],
            '/api/ps' => [['fixture' => 'live/ps-gemma4-e2b.json', 'type' => self::JSON]],
        ]]);

        $info = (new OllamaClient($server->profile('models')))->describe('gemma4:e2b');

        self::assertSame(131072, $info->contextWindow, 'model_info gemma4.context_length');
        self::assertTrue($info->tools);
        self::assertSame(['off', 'on'], $info->thinkingLevels, 'no thinking object: the capability gives the switch only');
        self::assertTrue($info->loaded, 'the running list has gemma4:e2b');
        self::assertSame('show', $info->source);
    }

    public function testTheListingReadsToolsFromTheCapabilitiesItReports(): void
    {
        $server = $this->serve(['models' => ['/api/tags' => [['body' => '{"models":[{"name":"qwen3:4b","capabilities":["completion","tools"]},{"name":"plain:latest"}]}', 'type' => self::JSON]]]]);

        $models = (new OllamaClient($server->profile('models')))->listModels();

        self::assertTrue($models[0]->tools);
        self::assertNull($models[1]->tools, 'without a capability list the tools are unknown');
    }

    public function testAModelWhoseShowSaysEmbeddingOnlyIsListedAsOneAndAChatModelIsListedAsChat(): void
    {
        $server = $this->serve(['models' => [
            '/api/tags' => [['body' => '{"models":[{"name":"nomic-embed-text:latest"},{"name":"qwen3:4b"}]}', 'type' => self::JSON]],
            '/api/show' => [
                ['body' => '{"capabilities":["embedding"]}', 'type' => self::JSON],
                ['body' => '{"capabilities":["completion","tools"]}', 'type' => self::JSON],
            ],
        ]]);

        $models = (new OllamaClient($server->profile('models')))->listModels();

        self::assertSame(['nomic-embed-text:latest', 'qwen3:4b'], array_map(static fn ($model): string => $model->id, $models));
        self::assertSame(['embedding', null], array_map(static fn ($model): ?string => $model->kind, $models));
        self::assertTrue($models[1]->tools);
    }

    public function testDescribeReadsTheRecordedShowAndTheEmptyRunningList(): void
    {
        $server = $this->serve(['models' => [
            '/api/show' => [['fixture' => 'show-nomic-embed-text.json', 'type' => self::JSON]],
            '/api/ps' => [['fixture' => 'ps-empty.json', 'type' => self::JSON]],
        ]]);

        $info = (new OllamaClient($server->profile('models')))->describe('nomic-embed-text:latest');

        self::assertSame('nomic-embed-text:latest', $info->id);
        self::assertSame(2048, $info->contextWindow, 'model_info nomic-bert.context_length');
        self::assertFalse($info->tools);
        self::assertSame([], $info->thinkingLevels, 'no thinking capability and no thinking metadata');
        self::assertFalse($info->loaded, 'the running list is empty');
        self::assertSame('show', $info->source);

        $requests = $server->requests();
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame(['model' => 'nomic-embed-text:latest'], $requests[0]['json']);
        self::assertSame('GET', $requests[1]['method']);
        self::assertSame('/models/api/ps', $requests[1]['path']);
    }

    public function testAThinkingModelHasTheOnOffSwitchAndItsContextLength(): void
    {
        $server = $this->serve(['models' => [
            '/api/show' => [['fixture' => 'show-qwen3-thinking.json', 'type' => self::JSON]],
            '/api/ps' => [['fixture' => 'ps-loaded.json', 'type' => self::JSON]],
        ]]);

        $info = (new OllamaClient($server->profile('models')))->describe('qwen3:4b');

        self::assertSame(40960, $info->contextWindow, 'qwen3.context_length');
        self::assertTrue($info->tools);
        self::assertSame(['off', 'on'], $info->thinkingLevels, 'thinking.values holds false and true');
        self::assertTrue($info->loaded, 'the running list has qwen3:4b');
    }

    public function testAModelWithNamedThinkingLevelsListsThoseLevels(): void
    {
        $server = $this->serve(['models' => [
            '/api/show' => [['fixture' => 'show-gpt-oss.json', 'type' => self::JSON]],
            '/api/ps' => [['fixture' => 'ps-loaded.json', 'type' => self::JSON]],
        ]]);

        $info = (new OllamaClient($server->profile('models')))->describe('gpt-oss');

        self::assertSame(['low', 'medium', 'high'], $info->thinkingLevels, 'the model names its own levels');
        self::assertSame(131072, $info->contextWindow, 'gptoss.context_length');
        self::assertFalse($info->loaded, 'the running list does not have it');
    }

    public function testALoadedModelIsFoundWhetherOrNotItsTagIsGiven(): void
    {
        $server = $this->serve(['models' => [
            '/api/show' => [['fixture' => 'show-nomic-embed-text.json', 'type' => self::JSON]],
            '/api/ps' => [['fixture' => 'ps-loaded.json', 'type' => self::JSON]],
        ]]);

        $info = (new OllamaClient($server->profile('models')))->describe('nomic-embed-text');

        self::assertTrue($info->loaded, '"nomic-embed-text" means "nomic-embed-text:latest"');
    }

    public function testAnUnknownModelIsDescribedWithoutDetailsAndWithoutAskingForTheRunningList(): void
    {
        $server = $this->serve(['models' => ['/api/show' => [['fixture' => 'show-not-found.json', 'status' => 404, 'type' => self::JSON]]]]);

        $info = (new OllamaClient($server->profile('models')))->describe('no-such-model:latest');

        self::assertSame('no-such-model:latest', $info->id);
        self::assertNull($info->contextWindow);
        self::assertNull($info->tools);
        self::assertSame([], $info->thinkingLevels);
        self::assertNull($info->loaded);
        self::assertNull($info->source);
        self::assertCount(1, $server->requests(), 'no /api/ps request');
    }

    public function testAThinkingCapabilityWithoutAListHasTheSwitch(): void
    {
        $server = $this->serve(['models' => [
            '/api/show' => [['body' => '{"capabilities":["completion","thinking"]}', 'type' => self::JSON]],
            '/api/ps' => [['body' => '{"models":[]}', 'type' => self::JSON]],
        ]]);

        self::assertSame(['off', 'on'], (new OllamaClient($server->profile('models')))->describe('x:latest')->thinkingLevels);
    }

    public function testAListWithOnlyFalseMeansTheModelDoesNotThink(): void
    {
        $server = $this->serve(['models' => [
            '/api/show' => [['body' => '{"capabilities":["completion"],"thinking":{"values":[false],"default":false}}', 'type' => self::JSON]],
            '/api/ps' => [['body' => '{"models":[]}', 'type' => self::JSON]],
        ]]);

        $info = (new OllamaClient($server->profile('models')))->describe('plain:latest');

        self::assertSame([], $info->thinkingLevels);
        self::assertFalse($info->tools);
    }

    public function testADescribeWithoutCapabilitiesOrModelInfoLeavesTheDetailsUnknown(): void
    {
        $server = $this->serve(['models' => [
            '/api/show' => [['body' => '{"parameters":"temperature 0.7"}', 'type' => self::JSON]],
            '/api/ps' => [['body' => '{"models":[]}', 'type' => self::JSON]],
        ]]);

        $info = (new OllamaClient($server->profile('models')))->describe('old:latest');

        self::assertNull($info->contextWindow);
        self::assertNull($info->tools);
        self::assertSame([], $info->thinkingLevels);
    }

    public function testAFailingRunningListLeavesLoadedUnknown(): void
    {
        $server = $this->serve(['models' => [
            '/api/show' => [['fixture' => 'show-nomic-embed-text.json', 'type' => self::JSON]],
            '/api/ps' => [['status' => 404, 'body' => '404 page not found', 'type' => 'text/plain']],
        ]]);

        $info = (new OllamaClient($server->profile('models')))->describe('nomic-embed-text:latest');

        self::assertNull($info->loaded);
        self::assertSame(2048, $info->contextWindow, 'the rest of the description is kept');
    }

    public function testTheKeyIsSentWithEveryModelRequest(): void
    {
        putenv('PHYCODE_TEST_KEY=model-key');
        $server = $this->serve(['models' => [
            '/api/tags' => [['fixture' => 'tags.json', 'type' => self::JSON]],
            '/api/show' => [['fixture' => 'show-nomic-embed-text.json', 'type' => self::JSON]],
            '/api/ps' => [['fixture' => 'ps-empty.json', 'type' => self::JSON]],
        ]]);
        $client = new OllamaClient($server->profile('models', ['api_key_env' => 'PHYCODE_TEST_KEY']));

        $client->listModels();
        $client->describe('nomic-embed-text:latest');

        self::assertSame(
            ['/models/api/tags', '/models/api/show', '/models/api/ps', '/models/api/ps'],
            array_column($server->requests(), 'path'),
            'the describe reads its /api/show answer from the listing, and asks only for the running list again',
        );
        self::assertSame(['Bearer model-key', 'Bearer model-key', 'Bearer model-key', 'Bearer model-key'], array_column($server->requests(), 'authorization'));
    }

    public function testAnAnswerAboutAModelIsKeptForTenMinutes(): void
    {
        $server = $this->serve(['models' => [
            '/api/show' => [['fixture' => 'live/show-gemma4-e2b.json', 'type' => self::JSON]],
            '/api/ps' => [['fixture' => 'live/ps-gemma4-e2b.json', 'type' => self::JSON]],
        ]]);
        $client = new OllamaClient($server->profile('models'));

        $client->describe('gemma4:e2b');
        $client->describe('gemma4:e2b');

        self::assertSame(['/models/api/show', '/models/api/ps', '/models/api/ps'], array_column($server->requests(), 'path'), 'only the running list is asked again');
    }

    public function testAnAnswerIsAskedAgainOnceItsTimeIsUp(): void
    {
        $server = $this->serve(['models' => [
            '/api/show' => [['fixture' => 'live/show-gemma4-e2b.json', 'type' => self::JSON]],
            '/api/ps' => [['fixture' => 'live/ps-gemma4-e2b.json', 'type' => self::JSON]],
        ]]);
        $client = new OllamaClient($server->profile('models'), showTtl: 0.0);

        $client->describe('gemma4:e2b');
        $client->describe('gemma4:e2b');

        self::assertSame(
            ['/models/api/show', '/models/api/ps', '/models/api/show', '/models/api/ps'],
            array_column($server->requests(), 'path'),
        );
    }

    public function testAnErrorStatusFromTheListingIsReportedWithItsMessage(): void
    {
        $server = $this->serve(['models' => ['/api/tags' => [['status' => 401, 'body' => '{"error":"unauthorized"}', 'type' => self::JSON]]]]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('HTTP 401: unauthorized');
        (new OllamaClient($server->profile('models')))->listModels();
    }

    public function testAReplyThatIsNotAModelListIsAnError(): void
    {
        $server = $this->serve(['models' => ['/api/tags' => [['body' => '{"ok":true}', 'type' => self::JSON]]]]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('did not return a model list');
        (new OllamaClient($server->profile('models')))->listModels();
    }

    public function testAnUnreachableServerIsAnErrorForTheListing(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($socket, $message);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'http://' . $address, 'model' => 'qwen3:4b', 'protocol' => 'ollama']]], null, [], '/nonexistent')->profile('test');

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('connection to http://' . $address . '/api/tags failed');
        (new OllamaClient($profile))->listModels();
    }

    private function serve(array $scenarios): OllamaServer
    {
        $this->server?->stop();

        return $this->server = new OllamaServer($scenarios);
    }
}
