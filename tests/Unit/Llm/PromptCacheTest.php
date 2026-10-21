<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Config\Profile;
use Phycode\Llm\EffortMapping;
use Phycode\Llm\PromptCache;
use PHPUnit\Framework\TestCase;

final class PromptCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        EffortMapping::setOpenAiHost(null);
        EffortMapping::setTestHost('api.x.ai', null);
    }

    private function profile(string $baseUrl, array $fields = []): Profile
    {
        return Config::fromArrays(['profiles' => ['p' => $fields + ['base_url' => $baseUrl, 'model' => 'm']]], null, [], '/nonexistent')->profile('p');
    }

    public function testAKeyNamesAConversationAndAnAgentAndHoldsNoPath(): void
    {
        $key = PromptCache::key('/home/me/project/.phycode/sessions/20261020-101010-abc.jsonl', 'build');

        self::assertMatchesRegularExpression('/^phycode-[0-9a-f]{24}$/D', $key);
        self::assertSame($key, PromptCache::key('/home/me/project/.phycode/sessions/20261020-101010-abc.jsonl', 'build'), 'it is stable');
        self::assertNotSame($key, PromptCache::key('/home/me/project/.phycode/sessions/20261020-101010-abc.jsonl', 'explore'));
        self::assertNotSame($key, PromptCache::key('/home/me/project/.phycode/sessions/other.jsonl', 'build'));
        self::assertStringNotContainsString('home', $key);
    }

    public function testOpenAiGetsTheKeyAndTheRetentionAndNobodyElseDoes(): void
    {
        $openai = $this->profile('https://api.openai.com', ['prompt_cache_retention' => '24h']);

        self::assertSame(['prompt_cache_key' => 'k', 'prompt_cache_retention' => '24h'], PromptCache::bodyFields($openai, 'k'));
        self::assertSame(['prompt_cache_retention' => '24h'], PromptCache::bodyFields($openai, null));
        self::assertSame(['prompt_cache_key' => 'k'], PromptCache::bodyFields($this->profile('https://api.openai.com'), 'k'));
        foreach (['http://127.0.0.1:8080', 'https://api.deepseek.com', 'https://openrouter.ai/api', 'https://api.groq.com/openai', 'https://api.mistral.ai'] as $url) {
            self::assertSame([], PromptCache::bodyFields($this->profile($url, ['prompt_cache_retention' => '24h']), 'k'), $url);
        }
        self::assertSame([], PromptCache::bodyFields($this->profile('https://api.openai.com', ['prompt_cache' => false, 'prompt_cache_retention' => '24h']), 'k'));
    }

    public function testXaiTakesTheKeyAsAFieldOnlyInTheResponsesApiAndAsAHeaderInChat(): void
    {
        $xai = $this->profile('https://api.x.ai');

        self::assertSame(['prompt_cache_key' => 'k'], PromptCache::bodyFields($xai, 'k', true));
        self::assertSame([], PromptCache::bodyFields($xai, 'k'));
        self::assertSame(['x-grok-conv-id: k'], PromptCache::headers($xai, 'k'));
    }

    public function testOpenRouterGetsTheSessionHeader(): void
    {
        self::assertSame(['x-session-id: k'], PromptCache::headers($this->profile('https://openrouter.ai/api'), 'k'));
        self::assertSame([], PromptCache::headers($this->profile('https://openrouter.ai/api'), null));
        self::assertSame([], PromptCache::headers($this->profile('https://openrouter.ai/api', ['prompt_cache' => false]), 'k'));
        self::assertSame([], PromptCache::headers($this->profile('https://api.openai.com'), 'k'), 'OpenAI routes by the field, not by a header');
        self::assertSame([], PromptCache::headers($this->profile('http://127.0.0.1:8082'), 'k'), 'a local server needs nothing');
    }

    public function testOnlyAnAnthropicModelOnOpenRouterGetsMarksInChat(): void
    {
        $router = fn (string $model, array $fields = []): Profile => $this->profile('https://openrouter.ai/api', ['model' => $model] + $fields);

        self::assertTrue(PromptCache::marksChat($router('anthropic/claude-sonnet-5-5')));
        self::assertTrue(PromptCache::marksChat($router('Anthropic/Claude-Opus')));
        self::assertFalse(PromptCache::marksChat($router('openai/gpt-5.5')));
        self::assertFalse(PromptCache::marksChat($router('anthropic/claude-sonnet-5-5', ['prompt_cache' => false])));
        self::assertFalse(PromptCache::marksChat($this->profile('https://api.openai.com', ['model' => 'anthropic/x'])));
    }

    public function testChatMarksGoOnTheSystemMessageAndOnTheLastUserOrAssistantTextOnly(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'assistant', 'content' => 'two', 'tool_calls' => [['id' => 'c1']]],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'result'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c2']]],
            ['role' => 'tool', 'tool_call_id' => 'c2', 'content' => 'result 2'],
        ];

        $marked = PromptCache::markChat($messages, '5m');

        self::assertSame([['type' => 'text', 'text' => 'Be brief.', 'cache_control' => ['type' => 'ephemeral']]], $marked[0]['content']);
        self::assertSame([['type' => 'text', 'text' => 'two', 'cache_control' => ['type' => 'ephemeral']]], $marked[2]['content'], 'the last message with text, which is not the empty one');
        self::assertSame('one', $marked[1]['content']);
        self::assertSame('result', $marked[3]['content'], 'a tool message is not marked');
        self::assertSame('', $marked[4]['content']);
        self::assertSame($messages[2]['tool_calls'], $marked[2]['tool_calls']);

        $hour = PromptCache::markChat([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']]]], '1h');
        self::assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $hour[0]['content'][1]['cache_control']);
        self::assertArrayNotHasKey('cache_control', $hour[0]['content'][0], 'only the last block');
        self::assertSame([['role' => 'tool', 'content' => 'x']], PromptCache::markChat([['role' => 'tool', 'content' => 'x']], '5m'), 'nothing to mark');
    }

    public function testAnthropicFullMarksTheLastToolTheSystemPromptAndTheLastUserBlock(): void
    {
        $body = [
            'model' => 'm',
            'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'first']]],
                ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't', 'name' => 'x', 'input' => []]]],
                ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't', 'content' => 'ok'], ['type' => 'text', 'text' => 'then this']]],
            ],
            'system' => 'Be brief.',
            'tools' => [['name' => 'a', 'input_schema' => []], ['name' => 'b', 'input_schema' => []]],
        ];

        $marked = PromptCache::markAnthropic($body, '5m');

        $mark = ['type' => 'ephemeral'];
        self::assertSame([['type' => 'text', 'text' => 'Be brief.', 'cache_control' => $mark]], $marked['system']);
        self::assertArrayNotHasKey('cache_control', $marked['tools'][0]);
        self::assertSame($mark, $marked['tools'][1]['cache_control']);
        self::assertSame($mark, $marked['messages'][2]['content'][1]['cache_control']);
        self::assertArrayNotHasKey('cache_control', $marked['messages'][2]['content'][0]);
        self::assertArrayNotHasKey('cache_control', $marked['messages'][0]['content'][0]);
        self::assertSame('Be brief.', $body['system'], 'the body that came in is not changed');
        self::assertLessThanOrEqual(4, substr_count(json_encode($marked), 'cache_control'), 'the API allows 4 marks');
    }

    public function testTheHourIsAskedForInEveryMark(): void
    {
        $marked = PromptCache::markAnthropic(['system' => 's', 'tools' => [['name' => 'a']], 'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'x']]]]], '1h');

        self::assertSame(3, substr_count(json_encode($marked), '"cache_control":{"type":"ephemeral","ttl":"1h"}'));
    }

    public function testMessagesModeMarksTheLastUserBlockAndLeavesTheSystemPromptAsText(): void
    {
        $marked = PromptCache::markAnthropic(['system' => 's', 'tools' => [['name' => 'a']], 'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'x']]]]], '5m', 'messages');

        self::assertSame('s', $marked['system']);
        self::assertArrayNotHasKey('cache_control', $marked['tools'][0]);
        self::assertSame(['type' => 'ephemeral'], $marked['messages'][0]['content'][0]['cache_control']);
    }

    public function testNoMarkGoesOnAThinkingBlockAnEmptyTextOrAnAssistantMessageThatIsSentBack(): void
    {
        $paused = PromptCache::markAnthropic(['messages' => [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'ask']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'let me look'], ['type' => 'server_tool_use', 'id' => 's', 'name' => 'web_search', 'input' => []]]],
        ]], '5m');
        self::assertSame(['type' => 'ephemeral'], $paused['messages'][0]['content'][0]['cache_control'], 'the paused turn goes back as it came; the user message before it is marked');
        self::assertArrayNotHasKey('cache_control', $paused['messages'][1]['content'][1]);

        $skipped = PromptCache::markAnthropic(['messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'real'],
            ['type' => 'thinking', 'thinking' => 't', 'signature' => 's'],
            ['type' => 'text', 'text' => ''],
        ]]]], '5m');
        self::assertSame(['type' => 'ephemeral'], $skipped['messages'][0]['content'][0]['cache_control']);
        self::assertArrayNotHasKey('cache_control', $skipped['messages'][0]['content'][1]);
        self::assertArrayNotHasKey('cache_control', $skipped['messages'][0]['content'][2]);

        $none = PromptCache::markAnthropic(['messages' => [['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'only']]]]], '5m');
        self::assertStringNotContainsString('cache_control', json_encode($none));
        self::assertSame(['model' => 'm'], PromptCache::markAnthropic(['model' => 'm'], '5m'), 'a body without messages, tools or system is left alone');
    }

    public function testTheProfileSettingsAreCheckedAndDefaultToOn(): void
    {
        $default = $this->profile('https://api.anthropic.com', ['protocol' => 'anthropic']);

        self::assertTrue($default->promptCache);
        self::assertSame('5m', $default->promptCacheTtl);
        self::assertNull($default->promptCacheRetention);
        $set = $this->profile('https://api.anthropic.com', ['protocol' => 'anthropic', 'prompt_cache' => false, 'prompt_cache_ttl' => '1h', 'prompt_cache_retention' => 'in_memory']);
        self::assertFalse($set->promptCache);
        self::assertSame('1h', $set->promptCacheTtl);
        self::assertSame('in_memory', $set->promptCacheRetention);
        self::assertSame('1h', $set->withModel('other')->promptCacheTtl, 'a copy keeps the settings');

        foreach ([['prompt_cache', 'yes'], ['prompt_cache_ttl', '10m'], ['prompt_cache_retention', 'forever']] as [$key, $value]) {
            try {
                $this->profile('https://api.anthropic.com', [$key => $value]);
                self::fail("accepted {$key}={$value}");
            } catch (ConfigException $error) {
                self::assertStringContainsString($key, $error->getMessage());
            }
        }
    }
}
