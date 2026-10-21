<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llama;

use Phycode\Llm\LlmException;
use Phycode\Llm\OpenAiClient;
use PHPUnit\Framework\TestCase;

final class LlamaOpenAiTest extends TestCase
{
    private ?LlamaFake $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        OpenAiClient::setLoadingWait(null, null);
    }

    public function testTheListOfALlamaServerComesFromTheDiscoveryAndIsCheckedOnce(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props.json']],
            'GET /v1/models' => [['fixture' => 'v1-models.json']],
        ]);
        $client = new OpenAiClient($server->profile());

        $first = $client->listModels();
        $second = $client->listModels();

        self::assertSame(16384, $first[0]->contextWindow);
        self::assertSame('v1/models', $first[0]->source);
        self::assertSame($first[0]->id, $second[0]->id);
        self::assertSame(['GET /props', 'GET /v1/models', 'GET /v1/models'], $this->routesOf($server), 'GET /props only once, to tell llama.cpp apart');
    }

    public function testDescribeOfALlamaServerReadsTheContextWindowFromProps(): void
    {
        $server = $this->serve(['GET /props' => [['fixture' => 'props.json']]]);

        $info = (new OpenAiClient($server->profile()))->describe('qwen35-2b');

        self::assertSame(16384, $info->contextWindow);
        self::assertSame(['off', 'on'], $info->thinkingLevels);
        self::assertSame('props', $info->source);
    }

    public function testTheListingOfASingleModelServerCarriesItsToolsAndThinking(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props.json']],
            'GET /v1/models' => [['fixture' => 'v1-models.json']],
        ]);

        $models = (new OpenAiClient($server->profile()))->listModels();

        self::assertTrue($models[0]->tools);
        self::assertSame(['off', 'on'], $models[0]->thinkingLevels);
    }

    public function testDescribeOfAnUnloadedRouterModelOnTheOpenAiClientDoesNotLoadIt(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /models' => [['fixture' => 'models-router-unloaded.json']],
        ]);

        $info = (new OpenAiClient($server->profile(model: 'Qwen3.5-2B-Q8_0')))->describe('Qwen3.5-2B-Q8_0');

        self::assertFalse($info->loaded);
        self::assertNull($info->contextWindow);
        foreach ($server->requests() as $request) {
            self::assertSame([], $request['query'], 'no model-specific request: it would start the load');
        }
    }

    public function testAServerThatIsNotLlamaCppKeepsTheOpenAiListing(): void
    {
        $server = $this->serve([
            'GET /props' => [['status' => 404, 'body' => '{"error":{"message":"not found"}}']],
            'GET /v1/models' => [['fixture' => 'v1-models.json']],
        ]);

        $models = (new OpenAiClient($server->profile()))->listModels();

        self::assertSame(16384, $models[0]->contextWindow, 'the context still comes from meta.n_ctx');
        self::assertSame('v1/models', $models[0]->source);
        self::assertSame(['GET /props', 'GET /v1/models'], $this->routesOf($server));
    }

    public function testAServerThatIsStillLoadingIsWaitedForOnTheListing(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'health-loading.json', 'status' => 503], ['fixture' => 'health-loading.json', 'status' => 503], ['fixture' => 'props.json']],
            'GET /v1/models' => [['fixture' => 'v1-models.json']],
        ]);
        OpenAiClient::setLoadingWait(0.01, 5.0);
        $client = new OpenAiClient($server->profile());

        $models = $client->listModels();
        $client->listModels();

        self::assertSame(16384, $models[0]->contextWindow, 'the listing waited, then read the llama.cpp settings');
        self::assertSame(['GET /props', 'GET /props', 'GET /props', 'GET /v1/models', 'GET /v1/models'], $this->routesOf($server), 'the answer is kept once the server has given one');
    }

    public function testAServerThatStaysLoadingPastTheWaitFailsTheListingWithoutAWaitOfItsOwn(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'health-loading.json', 'status' => 503]],
            'GET /v1/models' => [['fixture' => 'v1-models.json']],
        ]);
        OpenAiClient::setLoadingWait(0.01, 0.05);

        try {
            (new OpenAiClient($server->profile()))->listModels();
            self::fail('the server never finished loading');
        } catch (LlmException $error) {
            self::assertSame('HTTP 503: llama-server did not finish loading the model in 0.05 s; check the server', $error->getMessage());
        }
        self::assertNotContains('GET /v1/models', $this->routesOf($server), 'the listing does not wait a second time on the model route');
    }

    public function testTheListingOfAnotherServerWaitsWhileItsModelLoads(): void
    {
        $server = $this->serve([
            'GET /props' => [['body' => '{"object":"list","data":[]}']],
            'GET /v1/models' => [['fixture' => 'health-loading.json', 'status' => 503], ['fixture' => 'v1-models.json']],
        ]);
        OpenAiClient::setLoadingWait(0.01, 5.0);

        $models = (new OpenAiClient($server->profile()))->listModels();

        self::assertSame('v1/models', $models[0]->source, 'the entries of the model list, as for any server that is not llama.cpp');
        self::assertSame(['GET /props', 'GET /v1/models', 'GET /v1/models'], $this->routesOf($server));
    }

    private function serve(array $routes): LlamaFake
    {
        $this->server?->stop();

        return $this->server = new LlamaFake($routes);
    }

    private function routesOf(LlamaFake $server): array
    {
        return array_map(static fn (array $request): string => $request['method'] . ' ' . $request['path'], $server->requests());
    }
}
