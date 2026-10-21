<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\CapabilityCache;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\EffortMapping;
use Phycode\Llm\OpenAiClient;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\TestCase;

final class EffortListingTest extends TestCase
{
    private const MESSAGES = [['role' => 'system', 'content' => 'You are concise.'], ['role' => 'user', 'content' => 'Say hello.']];

    private ?ProtocolServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        EffortMapping::setTestHost('openrouter.ai', null);
        EffortMapping::setTestHost('api.x.ai', null);
        EffortMapping::setTestHost('api.deepseek.com', null);
    }

    private function cacheFile(): string
    {
        return Kit::folder('effort-listing') . '/capabilities.json';
    }

    private function listings(): int
    {
        return count(array_filter($this->server?->requests('GET') ?? [], static fn (array $request): bool => str_ends_with($request['path'], '/v1/models')));
    }

    public function testTheListingIsAskedOnceForAClientAndTheLevelsOfTheModelDecideTheField(): void
    {
        $this->server = new ProtocolServer(['or' => [
            '/v1/models' => [['fixture' => 'openrouter/models.json']],
            '/v1/chat/completions' => [['fixture' => 'llama/text.sse']],
        ]]);
        $cache = new CapabilityCache($this->cacheFile());
        $client = new OpenAiClient($this->server->profile('or', ['model' => 'openai/gpt-oss-120b', 'thinking' => 'max', 'effort_format' => 'openrouter']), [], $cache);

        $client->chat(self::MESSAGES, [], new ChatOptions());
        $client->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame(1, $this->listings(), 'one listing per client, not one per request');
        self::assertSame(
            ['effort' => ['minimal', 'low', 'medium', 'high'], 'mandatory' => false],
            $cache->levels(CapabilityCache::key($client->profile()), 'openai/gpt-oss-120b'),
            'the levels of each model are kept under the key of the profile',
        );
        $sent = $this->server->requests('POST');
        self::assertSame(['effort' => 'high'], $sent[0]['json']['reasoning'], 'max goes to the highest level the listing gives below it');
        self::assertSame(['effort' => 'high'], $sent[1]['json']['reasoning']);
    }

    public function testAClientThatStartsLaterReadsTheKeptLevelsWithoutAskingAgain(): void
    {
        $this->server = new ProtocolServer(['or' => [
            '/v1/models' => [['fixture' => 'openrouter/models.json']],
            '/v1/chat/completions' => [['fixture' => 'llama/text.sse']],
        ]]);
        $file = $this->cacheFile();
        $profile = $this->server->profile('or', ['model' => 'anthropic/claude-example', 'thinking' => 'none', 'effort_format' => 'openrouter']);

        (new OpenAiClient($profile, [], new CapabilityCache($file)))->chat(self::MESSAGES, [], new ChatOptions());
        (new OpenAiClient($profile, [], new CapabilityCache($file)))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame(1, $this->listings(), 'the next process reads the cache');
        self::assertSame(['effort' => 'none'], $this->server->requests('POST')[1]['json']['reasoning'], 'a model that takes every level takes none as it is');
    }

    public function testAListingThatFailsLeavesTheFieldToTheTablesAndIsNotAskedAgainByTheClient(): void
    {
        EffortMapping::setTestHost('openrouter.ai', '127.0.0.1');
        $this->server = new ProtocolServer(['or' => [
            '/v1/models' => [['status' => 500, 'body' => '{"error":{"message":"the listing is down"}}']],
            '/v1/chat/completions' => [['fixture' => 'llama/text.sse']],
        ]]);
        $client = new OpenAiClient($this->server->profile('or', ['model' => 'openai/gpt-oss-120b', 'thinking' => 'high']), [], new CapabilityCache($this->cacheFile()));

        $client->chat(self::MESSAGES, [], new ChatOptions());
        $client->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame(1, $this->listings());
        self::assertArrayNotHasKey('reasoning', $this->server->requests('POST')[0]['json'], 'an automatic format sends nothing for a model it does not know');
    }

    public function testTheListingFillsTheEffortLevelsOfTheModelsThatReportThem(): void
    {
        $this->server = new ProtocolServer(['or' => ['/v1/models' => [['fixture' => 'openrouter/models.json']]]]);
        $models = (new OpenAiClient($this->server->profile('or', ['effort_format' => 'openrouter']), [], new CapabilityCache($this->cacheFile())))->listModels();

        $levels = [];
        foreach ($models as $model) {
            $levels[$model->id] = $model->effortLevels;
        }
        self::assertSame([
            'openai/gpt-oss-120b' => ['minimal', 'low', 'medium', 'high'],
            'google/gemini-3.5-flash' => ['minimal', 'low', 'medium', 'high'],
            'anthropic/claude-example' => ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
            'meta-llama/plain' => [],
        ], $levels);
    }

    public function testDeepSeekListsNoneWithTheLevelsItsModelsSupport(): void
    {
        EffortMapping::setTestHost('api.deepseek.com', '127.0.0.1');
        $this->server = new ProtocolServer(['ds' => ['/v1/models' => [['fixture' => 'deepseek/models.json']]]]);
        $models = (new OpenAiClient($this->server->profile('ds', ['model' => 'deepseek-v4-pro']), [], new CapabilityCache($this->cacheFile())))->listModels();

        self::assertSame(['none', 'low', 'high', 'max'], $models[0]->effortLevels);
        self::assertSame([], $models[1]->effortLevels, 'a model with no effort field names no levels');
    }

    public function testXaiTakesTheLevelsItsListingGivesAndNoPenaltiesWithReasoning(): void
    {
        EffortMapping::setTestHost('api.x.ai', '127.0.0.1');
        $this->server = new ProtocolServer(['xai' => [
            '/v1/models' => [['fixture' => 'xai/models.json']],
            '/v1/chat/completions' => [['fixture' => 'llama/text.sse']],
        ]]);
        $profile = $this->server->profile('xai', [
            'model' => 'grok-4.6',
            'thinking' => 'max',
            'sampling' => ['temperature' => 0.2, 'presence_penalty' => 0.0, 'frequency_penalty' => 0.0, 'stop' => ['END']],
        ]);

        (new OpenAiClient($profile, [], new CapabilityCache($this->cacheFile())))->chat(self::MESSAGES, [], new ChatOptions());

        $body = $this->server->requests('POST')[0]['json'];
        self::assertSame('xhigh', $body['reasoning_effort'], 'grok-4.6 lists xhigh, the highest level below max');
        self::assertSame(0.2, $body['temperature'], 'the sampling that reasoning accepts stays');
        self::assertSame([], array_intersect_key($body, array_flip(['presence_penalty', 'frequency_penalty', 'stop'])), 'xAI refuses these with reasoning');
    }
}
