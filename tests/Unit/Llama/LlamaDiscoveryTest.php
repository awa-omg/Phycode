<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llama;

use Phycode\Config\Config;
use Phycode\Llm\LlmException;
use Phycode\Llm\Llama\LlamaDiscovery;
use PHPUnit\Framework\TestCase;

final class LlamaDiscoveryTest extends TestCase
{
    private ?LlamaFake $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        putenv('PHYCODE_TEST_LLAMA_KEY');
    }

    public function testPropsReadTheContextWindowTheTemplateAndTheModelPath(): void
    {
        $discovery = new LlamaDiscovery($this->serve(['GET /props' => [['fixture' => 'props.json']]])->profile());

        $props = $discovery->props();

        self::assertSame(16384, $props->contextWindow, 'default_generation_settings.n_ctx');
        self::assertSame('/root/models/Qwen3.5-2B-Q8_0.gguf', $props->modelPath);
        self::assertSame('b11721-ff5a23376', $props->build);
        self::assertFalse($props->router, 'a single-model server reports no role');
        self::assertTrue($props->tools, 'chat_template_caps.supports_tools');
        self::assertStringContainsString('<tool_call>', (string) $props->chatTemplate);
    }

    public function testThePropsOfARouterHaveNoContextNoModelAndNoTemplate(): void
    {
        $discovery = new LlamaDiscovery($this->serve(['GET /props' => [['fixture' => 'props-router.json']]])->profile());

        $props = $discovery->props();

        self::assertTrue($props->router);
        self::assertNull($props->contextWindow, 'a router runs no context of its own: n_ctx is 0');
        self::assertNull($props->modelPath, 'a router reports model_path "none"');
        self::assertNull($props->chatTemplate);
        self::assertNull($props->tools);
    }

    public function testThePropsOfARouterModelAreAskedForByItsName(): void
    {
        $server = $this->serve(['GET /props' => [['fixture' => 'props-model.json']]]);

        $props = (new LlamaDiscovery($server->profile()))->props('Qwen3.5-2B-Q8_0');

        self::assertSame(4096, $props->contextWindow, 'the context the loaded model runs with');
        self::assertTrue($props->tools);
        self::assertSame(['model' => 'Qwen3.5-2B-Q8_0'], $server->requests()[0]['query']);
    }

    public function testAServerThatAnswersWithoutLlamaCppSettingsIsNotLlamaCpp(): void
    {
        $discovery = new LlamaDiscovery($this->serve(['GET /props' => [['body' => '{"object":"list","data":[]}']]])->profile());

        self::assertFalse($discovery->llamaServer());
        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('is not llama.cpp: GET /props has no default_generation_settings');
        $discovery->props();
    }

    public function testAnErrorStatusFromPropsIsReportedWithTheServerMessage(): void
    {
        $discovery = new LlamaDiscovery($this->serve(['GET /props' => [['status' => 401, 'body' => '{"error":{"message":"Invalid API Key","type":"authentication_error","code":401}}']]])->profile());

        self::assertFalse($discovery->llamaServer(), 'the server answered: it is not one that accepts this key');
        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('HTTP 401: Invalid API Key');
        $discovery->props();
    }

    public function testSlotsCountTheIdleAndTheBusyOnes(): void
    {
        $idle = new LlamaDiscovery($this->serve(['GET /slots' => [['fixture' => 'slots.json']]])->profile());
        self::assertSame(['total' => 1, 'busy' => 0, 'idle' => 1], $idle->slots());
    }

    public function testASlotThatIsProcessingIsBusy(): void
    {
        $busy = new LlamaDiscovery($this->serve(['GET /slots' => [['fixture' => 'slots-busy.json']]])->profile());

        self::assertSame(['total' => 2, 'busy' => 1, 'idle' => 1], $busy->slots());
    }

    public function testARouterAsksForSlotsByModel(): void
    {
        $server = $this->serve(['GET /slots' => [['fixture' => 'slots-router.json']]]);

        self::assertSame(['total' => 4, 'busy' => 0, 'idle' => 4], (new LlamaDiscovery($server->profile()))->slots('Qwen3.5-2B-Q8_0'));
        self::assertSame(['model' => 'Qwen3.5-2B-Q8_0'], $server->requests()[0]['query']);
    }

    public function testASlotsRequestWithoutAModelIsRefusedByARouter(): void
    {
        $discovery = new LlamaDiscovery($this->serve(['GET /slots' => [['fixture' => 'slots-router-missing-model.json', 'status' => 400]]])->profile());

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('HTTP 400: model name is missing from the request');
        $discovery->slots();
    }

    public function testTheSlotsEndpointOffIsAnHttp501(): void
    {
        $discovery = new LlamaDiscovery($this->serve(['GET /slots' => [['fixture' => 'slots-disabled.json', 'status' => 501]]])->profile());

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('HTTP 501: This server does not support slots endpoint');
        $discovery->slots();
    }

    public function testTheModelsOfASingleModelServerCarryTheirContextWindow(): void
    {
        $discovery = new LlamaDiscovery($this->serve(['GET /v1/models' => [['fixture' => 'v1-models.json']]])->profile());

        $models = $discovery->models();

        self::assertCount(1, $models);
        self::assertSame('qwen35-2b', $models[0]->id);
        self::assertSame(16384, $models[0]->contextWindow, 'meta.n_ctx');
        self::assertNull($models[0]->loaded, 'a single-model server reports no load state');
        self::assertSame('test', $models[0]->profile);
        self::assertSame('openai', $models[0]->protocol);
        self::assertSame('v1/models', $models[0]->source);
    }

    public function testTheModelsOfARouterReportTheLoadStateAndTheContextOfTheLoadedModel(): void
    {
        $discovery = new LlamaDiscovery($this->serve(['GET /v1/models' => [['fixture' => 'v1-models-router-loaded.json']]])->profile());

        $models = $discovery->models();

        self::assertTrue($models[0]->loaded);
        self::assertSame(4096, $models[0]->contextWindow, 'meta.n_ctx of the loaded model, not the trained maximum');
    }

    public function testTheModelsOfASingleModelServerReportTheToolsAndThinkingOfItsSettings(): void
    {
        $server = $this->serve([
            'GET /v1/models' => [['fixture' => 'v1-models.json']],
            'GET /props' => [['fixture' => 'props.json']],
        ]);
        $discovery = new LlamaDiscovery($server->profile());

        $first = $discovery->models();
        $second = $discovery->models();

        self::assertTrue($first[0]->tools, 'chat_template_caps.supports_tools');
        self::assertSame(['off', 'on'], $first[0]->thinkingLevels, 'the chat template reads enable_thinking');
        self::assertTrue($second[0]->tools);
        self::assertSame(['GET /v1/models', 'GET /props', 'GET /v1/models'], $this->routesOf($server), 'the settings are asked for once and kept');
    }

    public function testTheModelsOfARouterAskForNoSettings(): void
    {
        $server = $this->serve(['GET /v1/models' => [['fixture' => 'v1-models-router-loaded.json']]]);

        $models = (new LlamaDiscovery($server->profile()))->models();

        self::assertTrue($models[0]->loaded);
        self::assertNull($models[0]->tools);
        self::assertSame(['GET /v1/models'], $this->routesOf($server), 'no model-specific read: it would start a load');
    }

    public function testASingleModelServerHasNoRouterModels(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props.json']],
            'GET /models' => [['fixture' => 'models-single.json']],
        ]);

        self::assertSame([], (new LlamaDiscovery($server->profile()))->routerModels());
        self::assertSame(['GET /props'], $this->routesOf($server), 'a single-model server is not asked for /models');
    }

    public function testTheRouterModelsReportWhetherEachIsLoaded(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /models' => [['fixture' => 'models-router-unloaded.json'], ['fixture' => 'models-router-loading.json'], ['fixture' => 'models-router-loaded.json']],
        ]);
        $discovery = new LlamaDiscovery($server->profile());

        $states = [];
        foreach ([1, 2, 3] as $_) {
            $states[] = $discovery->routerModels()[0]->loaded;
        }

        self::assertSame([false, false, true], $states, 'unloaded and loading are not loaded; loaded is');
    }

    public function testRouterStatesReadTheRoleOfPropsAndTheStatusOfEachModel(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /models' => [['fixture' => 'models-router-unloaded.json']],
        ]);
        $discovery = new LlamaDiscovery($server->profile());

        self::assertSame(['Qwen3.5-2B-Q8_0' => 'unloaded'], $discovery->routerStates(), 'a router sends no models key');
        self::assertSame(['GET /props', 'GET /models'], $this->routesOf($server), 'no model-specific read: it would load the model');
    }

    public function testRouterStatesFollowTheLoadingOfTheModel(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /models' => [['fixture' => 'models-router-loading.json'], ['fixture' => 'models-router-loaded.json']],
        ]);
        $discovery = new LlamaDiscovery($server->profile());

        self::assertSame(['Qwen3.5-2B-Q8_0' => 'loading'], $discovery->routerStates());
        self::assertSame(['Qwen3.5-2B-Q8_0' => 'loaded'], $discovery->routerStates());
    }

    public function testASingleModelServerIsNoRouterEvenThoughItsModelsListHasAModelsKey(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props.json']],
            'GET /models' => [['fixture' => 'models-single.json']],
        ]);

        self::assertNull((new LlamaDiscovery($server->profile()))->routerStates());
    }

    public function testARouterWithoutModelsHasNoStates(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /models' => [['body' => '{"object":"list","data":[]}']],
        ]);

        self::assertSame([], (new LlamaDiscovery($server->profile()))->routerStates());
    }

    public function testAServerWithoutTheRouterRoutesIsNoRouter(): void
    {
        self::assertNull((new LlamaDiscovery($this->serve([])->profile()))->routerStates());
    }

    public function testRouterStatesSendTheBearerKey(): void
    {
        putenv('PHYCODE_TEST_LLAMA_KEY=llama-test-key');
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /models' => [['fixture' => 'models-router-loaded.json']],
        ], 'llama-test-key');

        (new LlamaDiscovery($server->profile(['api_key_env' => 'PHYCODE_TEST_LLAMA_KEY'])))->routerStates();

        self::assertSame(['Bearer llama-test-key', 'Bearer llama-test-key'], array_column($server->requests(), 'authorization'));
    }

    public function testRouterStatesAreUnknownWhenTheServerRejectsTheKey(): void
    {
        $discovery = new LlamaDiscovery($this->serve([
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /models' => [['fixture' => 'models-router-loaded.json']],
        ], 'llama-test-key')->profile());

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('HTTP 401: Invalid API Key');
        $discovery->routerStates();
    }

    public function testDescribeOfASingleModelServerReadsTheContextAndTheToolsFromProps(): void
    {
        $server = $this->serve(['GET /props' => [['fixture' => 'props.json']]]);

        $info = (new LlamaDiscovery($server->profile()))->describe('qwen35-2b');

        self::assertSame(16384, $info->contextWindow);
        self::assertTrue($info->tools);
        self::assertSame(['off', 'on'], $info->thinkingLevels, 'the template reads enable_thinking');
        self::assertNull($info->loaded);
        self::assertSame('props', $info->source);
        self::assertSame(['GET /props'], $this->routesOf($server));
    }

    public function testDescribeOfAnUnloadedRouterModelDoesNotAskForItsPropsBecauseThatWouldLoadIt(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /models' => [['fixture' => 'models-router-unloaded.json']],
        ]);

        $info = (new LlamaDiscovery($server->profile()))->describe('Qwen3.5-2B-Q8_0');

        self::assertFalse($info->loaded);
        self::assertNull($info->contextWindow, 'the runtime context is unknown until the model loads');
        self::assertNull($info->tools);
        self::assertSame('models', $info->source);
        self::assertSame(['GET /props', 'GET /models'], $this->routesOf($server));
    }

    public function testDescribeOfALoadedRouterModelReadsItsRuntimeContextAndTools(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props-router.json'], ['fixture' => 'props-model.json']],
            'GET /models' => [['fixture' => 'models-router-loaded.json']],
        ]);

        $info = (new LlamaDiscovery($server->profile()))->describe('Qwen3.5-2B-Q8_0');

        self::assertTrue($info->loaded);
        self::assertSame(4096, $info->contextWindow);
        self::assertTrue($info->tools);
        self::assertSame('props', $info->source);
        self::assertSame(['GET /props', 'GET /models', 'GET /props?model=Qwen3.5-2B-Q8_0'], $this->routesOf($server));
        self::assertSame(['model' => 'Qwen3.5-2B-Q8_0'], $server->requests()[2]['query']);
    }

    public function testDescribeOfAModelTheRouterDoesNotListIsNotLoaded(): void
    {
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /models' => [['fixture' => 'models-router-loaded.json']],
        ]);

        $info = (new LlamaDiscovery($server->profile()))->describe('some-other-model');

        self::assertFalse($info->loaded);
        self::assertNull($info->contextWindow);
        self::assertSame(['GET /props', 'GET /models'], $this->routesOf($server));
    }

    public function testEveryDiscoveryRequestSendsTheBearerKey(): void
    {
        putenv('PHYCODE_TEST_LLAMA_KEY=llama-test-key');
        $server = $this->serve([
            'GET /props' => [['fixture' => 'props-router.json'], ['fixture' => 'props-model.json']],
            'GET /models' => [['fixture' => 'models-router-loaded.json']],
            'GET /slots' => [['fixture' => 'slots-router.json']],
            'GET /v1/models' => [['fixture' => 'v1-models-router-loaded.json']],
        ]);
        $discovery = new LlamaDiscovery($server->profile(['api_key_env' => 'PHYCODE_TEST_LLAMA_KEY']));

        $discovery->describe('Qwen3.5-2B-Q8_0');
        $discovery->slots('Qwen3.5-2B-Q8_0');
        $discovery->models();

        $requests = $server->requests();
        self::assertCount(5, $requests, 'props, models, props of the model, slots and v1 models');
        foreach ($requests as $request) {
            self::assertSame('Bearer llama-test-key', $request['authorization'], $request['method'] . ' ' . $request['target']);
        }
    }

    public function testWithoutAKeyNoAuthorizationHeaderIsSent(): void
    {
        $server = $this->serve(['GET /props' => [['fixture' => 'props.json']]]);

        (new LlamaDiscovery($server->profile()))->props();

        self::assertNull($server->requests()[0]['authorization']);
    }

    public function testTheAnswerIsTrueForLlamaCppAndAStillLoadingServerIsWaitedFor(): void
    {
        $llama = new LlamaDiscovery($this->serve(['GET /props' => [['fixture' => 'props.json']]])->profile());
        self::assertTrue($llama->llamaServer());

        $server = $this->serve(['GET /props' => [['fixture' => 'health-loading.json', 'status' => 503], ['fixture' => 'props.json']]]);
        self::assertTrue((new LlamaDiscovery($server->profile(), [0.01], 5.0))->llamaServer());
        self::assertCount(2, $server->requests(), 'the loading answer was waited out and the question asked again');
    }

    public function testAServerThatStaysLoadingPastTheWaitIsAnErrorNotAnAnswer(): void
    {
        $server = $this->serve(['GET /props' => [['fixture' => 'health-loading.json', 'status' => 503]]]);
        $discovery = new LlamaDiscovery($server->profile(), [0.01], 0.05);

        try {
            $discovery->llamaServer();
            self::fail('the server never finished loading');
        } catch (LlmException $error) {
            self::assertSame('HTTP 503: llama-server did not finish loading the model in 0.05 s; check the server', $error->getMessage());
        }
        self::assertGreaterThan(1, count($server->requests()), 'the question was asked again during the wait');
    }

    public function testA503WithoutTheLoadingMessageIsAnUnknownAnswerAndNotWaitedOn(): void
    {
        $server = $this->serve(['GET /props' => [['status' => 503, 'body' => '{"error":{"message":"unavailable"}}', 'type' => 'application/json']]]);

        self::assertNull((new LlamaDiscovery($server->profile()))->llamaServer());
        self::assertCount(1, $server->requests());
    }

    public function testTheAnswerIsUnknownWhenTheServerCannotBeReached(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($socket, $message);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'http://' . $address, 'model' => 'qwen35-2b']]], null, [], '/nonexistent')->profile('test');

        self::assertNull((new LlamaDiscovery($profile))->llamaServer());
    }

    private function serve(array $routes, ?string $apiKey = null): LlamaFake
    {
        $this->server?->stop();

        return $this->server = new LlamaFake($routes, apiKey: $apiKey);
    }

    private function routesOf(LlamaFake $server): array
    {
        return array_map(static fn (array $request): string => $request['method'] . ' ' . $request['path'] . ($request['query'] === [] ? '' : '?' . http_build_query($request['query'])), $server->requests());
    }
}
