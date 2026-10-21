<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Anthropic;

use Phycode\Llm\Anthropic\AnthropicClient;
use Phycode\Llm\LlmException;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\TestCase;

final class AnthropicModelsTest extends TestCase
{
    private ?ProtocolServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        putenv('PHYCODE_TEST_ANTHROPIC_KEY');
    }

    public function testEveryPageIsReadAndTheModelsKeepTheirOrder(): void
    {
        $server = $this->serve(['/v1/models' => [
            ['type' => 'application/json', 'body' => json_encode([
                'data' => [['type' => 'model', 'id' => 'claude-a', 'display_name' => 'A', 'max_input_tokens' => 200000, 'capabilities' => ['thinking' => ['supported' => true, 'types' => ['adaptive' => ['supported' => true]]]]]],
                'first_id' => 'claude-a', 'last_id' => 'claude-a', 'has_more' => true,
            ], JSON_THROW_ON_ERROR)],
            ['type' => 'application/json', 'body' => json_encode([
                'data' => [['type' => 'model', 'id' => 'claude-b', 'display_name' => 'B']],
                'first_id' => 'claude-b', 'last_id' => 'claude-b', 'has_more' => false,
            ], JSON_THROW_ON_ERROR)],
        ]]);

        $models = (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic'])))->listModels();

        self::assertSame(['claude-a', 'claude-b'], array_map(static fn ($model): string => $model->id, $models));
        self::assertSame([200000, null], array_map(static fn ($model): ?int => $model->contextWindow, $models));
        self::assertSame(['limit=1000', 'limit=1000&after_id=claude-a'], array_column($server->requests(), 'query'));
        self::assertSame(['anthropic', 'v1/models'], [$models[0]->protocol, $models[0]->source]);
    }

    public function testTheThinkingSwitchIsOfferedOnlyToAModelThatReportsThinking(): void
    {
        $server = $this->serve(['/v1/models' => [['type' => 'application/json', 'body' => json_encode(['data' => [
            ['id' => 'thinks', 'capabilities' => ['thinking' => ['supported' => true]]],
            ['id' => 'typed', 'capabilities' => ['thinking' => ['types' => ['enabled' => ['supported' => true]]]]],
            ['id' => 'plain', 'capabilities' => ['thinking' => ['supported' => false]]],
            ['id' => 'none'],
        ]], JSON_THROW_ON_ERROR)]]]);

        $models = (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic'])))->listModels();

        self::assertSame([['off', 'on'], ['off', 'on'], [], []], array_map(static fn ($model): array => $model->thinkingLevels, $models));
    }

    public function testTheWindowFallsBackToTheMetaWindowOfAServerThatSendsIt(): void
    {
        $server = $this->serve(['/v1/models' => [['type' => 'application/json', 'body' => json_encode(['data' => [
            ['id' => 'qwen35-2b', 'meta' => ['n_ctx' => 16384]],
        ]], JSON_THROW_ON_ERROR)]]]);

        $models = (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic'])))->listModels();

        self::assertSame(16384, $models[0]->contextWindow);
    }

    public function testTheKeyAndTheVersionAreSentWithTheListing(): void
    {
        putenv('PHYCODE_TEST_ANTHROPIC_KEY=sk-ant-models');
        $server = $this->serve(['/v1/models' => [['type' => 'application/json', 'body' => '{"data":[]}']]]);

        (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic', 'api_key_env' => 'PHYCODE_TEST_ANTHROPIC_KEY', 'anthropic_version' => '2024-10-22'])))->listModels();

        self::assertSame(['sk-ant-models', '2024-10-22'], [$server->requests()[0]['headers']['x-api-key'], $server->requests()[0]['headers']['anthropic-version']]);
        self::assertSame('GET', $server->requests()[0]['method']);
    }

    public function testDescribeGivesTheListedWindowAndTheUnknownOnesNothing(): void
    {
        $server = $this->serve(['/v1/models' => [['type' => 'application/json', 'body' => json_encode(['data' => [
            ['id' => 'claude-a', 'max_input_tokens' => 200000, 'capabilities' => ['thinking' => ['supported' => true]]],
        ]], JSON_THROW_ON_ERROR)]]]);
        $client = new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic']));

        $known = $client->describe('claude-a');
        $unknown = $client->describe('claude-z');

        self::assertSame([200000, ['off', 'on'], 'claude-a'], [$known->contextWindow, $known->thinkingLevels, $known->id]);
        self::assertSame(['claude-z', null, null, []], [$unknown->id, $unknown->contextWindow, $unknown->tools, $unknown->thinkingLevels]);
    }

    public function testAnErrorReplyIsAnExceptionWithTheServersMessage(): void
    {
        $server = $this->serve(['/v1/models' => [['status' => 401, 'type' => 'application/json', 'body' => '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}']]]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('HTTP 401: invalid x-api-key');
        (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic'])))->listModels();
    }

    public function testAReplyThatIsNotAModelListIsAnError(): void
    {
        $server = $this->serve(['/v1/models' => [['type' => 'application/json', 'body' => '{"ok":true}']]]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('did not return a model list');
        (new AnthropicClient($server->profile('chat', ['protocol' => 'anthropic'])))->listModels();
    }

    private function serve(array $endpoints): ProtocolServer
    {
        $this->server?->stop();

        return $this->server = new ProtocolServer(['chat' => $endpoints]);
    }
}
