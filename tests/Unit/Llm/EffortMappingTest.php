<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Config\Profile;
use Phycode\Llm\EffortMapping;
use Phycode\Llm\LlmException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EffortMappingTest extends TestCase
{
    private const LEVELS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];
    private const OPENAI = 'https://api.openai.com/v1';

    protected function tearDown(): void
    {
        EffortMapping::setOpenAiHost(null);
        EffortMapping::setTestHost('api.x.ai', null);
    }

    public static function responsesEfforts(): iterable
    {
        foreach (self::LEVELS as $level) {
            yield "the level {$level}" => [$level, $level];
        }
        yield 'off is none' => ['off', 'none'];
        yield 'on is medium' => ['on', 'medium'];
    }

    public static function llamaSwitches(): iterable
    {
        foreach (self::LEVELS as $level) {
            yield "the level {$level}" => [$level, $level !== 'none'];
        }
        yield 'off is none' => ['off', false];
        yield 'on is medium' => ['on', true];
    }

    public static function clampCases(): iterable
    {
        yield 'a level the model takes is itself' => [4, ['low', 'high'], 'high'];
        yield 'xhigh between high and max goes to the weaker, high' => [5, ['high', 'max'], 'high'];
        yield 'max above the top of gpt-5.5 goes to xhigh, the highest it takes below' => [6, ['none', 'low', 'medium', 'high', 'xhigh'], 'xhigh'];
        yield 'high above a model that takes low goes to low' => [4, ['low'], 'low'];
        yield 'xhigh on a model without xhigh goes to high' => [5, ['low', 'medium', 'high', 'max'], 'high'];
        yield 'minimal below every level goes to the lowest one the model takes' => [1, ['low', 'high'], 'low'];
        yield 'minimal on GPT-6 Luna, which takes none, goes to low' => [1, ['none', 'low', 'medium', 'high', 'xhigh', 'max'], 'low'];
        yield 'low below gpt-5.5-pro, which takes medium up, goes to medium' => [2, ['medium', 'high', 'xhigh'], 'medium'];
        yield 'none on a model without none goes to the lowest level it takes' => [0, ['low', 'medium', 'high', 'xhigh', 'max'], 'low'];
        yield 'none on gpt-5, which takes minimal up, goes to minimal' => [0, ['minimal', 'low', 'medium', 'high'], 'minimal'];
        yield 'none on a model that takes none is none' => [0, ['none', 'low'], 'none'];
        yield 'minimal with none and medium takes medium, the lowest level that is not none' => [1, ['none', 'medium'], 'medium'];
        yield 'a model that takes no level but none gives none' => [2, ['none'], 'none'];
    }

    #[DataProvider('clampCases')]
    public function testTheLevelSentIsTheOneTheModelTakesAndNeverAboveTheOneAsked(int $index, array $accepted, string $expected): void
    {
        self::assertSame($expected, EffortMapping::clamp(self::LEVELS[$index], $accepted));
    }

    #[DataProvider('responsesEfforts')]
    public function testOpenAiResponsesOnAServerWithoutATableSendsEachLevelAsReasoningEffort(string $thinking, string $effort): void
    {
        self::assertSame($effort, EffortMapping::responses($this->profile('https://openrouter.ai/api', $thinking, 'openai-responses', 'gpt-6-astra')));
    }

    #[DataProvider('responsesEfforts')]
    public function testChatCompletionsOnAnUnlistedModelOfOpenAisEndpointSendsTheLevelAsItIs(string $thinking, string $effort): void
    {
        self::assertSame(['reasoning_effort' => $effort], EffortMapping::chatFields($this->profile(self::OPENAI, $thinking, 'openai', 'some-model')));
    }

    #[DataProvider('llamaSwitches')]
    public function testAHostedApiThatIsNotOpenAiGetsNeitherTheTemplateSwitchNorAReasoningEffort(string $thinking): void
    {
        self::assertSame([], EffortMapping::chatFields($this->profile('https://api.groq.com/openai', $thinking)));
        self::assertSame([], EffortMapping::chatFields($this->profile('https://openrouter.ai/api', $thinking)));
    }

    #[DataProvider('llamaSwitches')]
    public function testLlamaCppSendsTheSwitchAndNoReasoningEffortAtEveryLevel(string $thinking, bool $enabled): void
    {
        self::assertSame(['chat_template_kwargs' => ['enable_thinking' => $enabled]], EffortMapping::chatFields($this->profile('http://127.0.0.1:8082', $thinking)));
    }

    public static function openAiModels(): iterable
    {
        yield 'GPT-6 Luna takes none, so none is sent as none' => ['gpt-6-luna', 'none', 'none', 'none'];
        yield 'GPT-6 Luna has no minimal, so minimal is sent as low' => ['gpt-6-luna', 'minimal', 'low', 'low'];
        yield 'GPT-6 Astra has no none, so none is sent as low' => ['gpt-6-astra', 'none', 'low', 'low'];
        yield 'GPT-6.1 Sol has no none, so none is sent as low' => ['gpt-6.1-sol', 'none', 'low', 'low'];
        yield 'GPT-5 has no none, so none is sent as minimal' => ['gpt-5', 'none', 'minimal', 'minimal'];
        yield 'GPT-5.5 has no max, so max is sent as xhigh' => ['gpt-5.5', 'max', 'xhigh', 'xhigh'];
        yield 'GPT-5.5 Pro takes no none, so none is sent as medium' => ['gpt-5.5-pro', 'none', 'medium', 'medium'];
        yield 'GPT-5 Pro takes only high, so low is sent as high' => ['gpt-5-pro', 'low', 'high', 'high'];
        yield 'GPT-5 Pro takes only high, so max is sent as high too' => ['gpt-5-pro', 'max', 'high', 'high'];
        yield 'a dated id is the model without its date' => ['gpt-6-luna-2026-05-18', 'minimal', 'low', 'low'];
    }

    #[DataProvider('openAiModels')]
    public function testAnOpenAiModelIsSentTheLevelItTakes(string $model, string $thinking, string $chat, string $responses): void
    {
        self::assertSame(['reasoning_effort' => $chat], EffortMapping::chatFields($this->profile(self::OPENAI, $thinking, 'openai', $model)));
        self::assertSame($responses, EffortMapping::responses($this->profile(self::OPENAI, $thinking, 'openai-responses', $model)));
    }

    public function testAnIdThatOnlyStartsLikeATableModelIsNotInTheTable(): void
    {
        self::assertSame(['reasoning_effort' => 'minimal'], EffortMapping::chatFields($this->profile(self::OPENAI, 'minimal', 'openai', 'gpt-5.1-codex')));
        self::assertSame(['reasoning_effort' => 'low'], EffortMapping::chatFields($this->profile(self::OPENAI, 'minimal', 'openai', 'gpt-5.1')));
    }

    public function testAHostThatIsNotOpenAisDoesNotUseTheOpenAiTable(): void
    {
        self::assertSame('none', EffortMapping::responses($this->profile('https://openrouter.ai/api', 'none', 'openai-responses', 'gpt-6-astra')));
        self::assertSame([], EffortMapping::chatFields($this->profile('https://openrouter.ai/api', 'none', 'openai', 'gpt-6-astra')));
    }

    public function testTheTableAppliesToTheHostThatTheTestHookNames(): void
    {
        EffortMapping::setOpenAiHost('127.0.0.1');
        self::assertSame(['reasoning_effort' => 'low'], EffortMapping::chatFields($this->profile('http://127.0.0.1:8082', 'minimal', 'openai', 'gpt-6-luna')));

        EffortMapping::setOpenAiHost(null);
        self::assertSame(['chat_template_kwargs' => ['enable_thinking' => true]], EffortMapping::chatFields($this->profile('http://127.0.0.1:8082', 'minimal', 'openai', 'gpt-6-luna')));
    }

    public static function routes(): iterable
    {
        yield 'Astra with tools goes to Responses without asking Chat Completions' => ['gpt-6-astra', 'low', true, true];
        yield 'Astra without tools stays on Chat Completions' => ['gpt-6-astra', 'low', false, false];
        yield 'Luna with tools at low goes to Responses' => ['gpt-6-luna', 'low', true, true];
        yield 'Luna with tools at none stays on Chat Completions' => ['gpt-6-luna', 'none', true, false];
        yield 'Luna without tools stays on Chat Completions' => ['gpt-6-luna', 'low', false, false];
        yield 'GPT-5.5 Pro takes no Chat Completions, so every request goes to Responses' => ['gpt-5.5-pro', 'high', false, true];
        yield 'GPT-5.5 with tools stays on Chat Completions, and the refusal decides' => ['gpt-5.5', 'low', true, false];
        yield 'a model that is not in the table stays on Chat Completions' => ['gpt-5.6-cyber', 'low', true, false];
    }

    #[DataProvider('routes')]
    public function testTheTableSendsToResponsesTheRequestsThatAModelRefusesOnChatCompletions(string $model, string $thinking, bool $tools, bool $responses): void
    {
        self::assertSame($responses, EffortMapping::viaResponses($this->profile(self::OPENAI, $thinking, 'openai', $model), $tools));
    }

    public function testNoOtherHostIsRoutedByTheTable(): void
    {
        self::assertFalse(EffortMapping::viaResponses($this->profile('http://127.0.0.1:8082', 'low', 'openai', 'gpt-6-astra'), true));
    }

    public function testTheGpt6RuleTakesTheSamplingValuesOutWithAReasoningLevel(): void
    {
        $body = [
            'model' => 'gpt-6-luna', 'temperature' => 0.6, 'top_p' => 0.95, 'top_logprobs' => 2, 'logprobs' => true,
            'include' => ['message.output_text.logprobs', 'reasoning.encrypted_content'], 'messages' => [],
        ];

        $sent = EffortMapping::withoutSampling($body, $this->profile(self::OPENAI, 'low', 'openai', 'gpt-6-luna'));

        self::assertSame(['model', 'include', 'messages'], array_keys($sent));
        self::assertSame(['reasoning.encrypted_content'], $sent['include']);
        self::assertSame($body, EffortMapping::withoutSampling($body, $this->profile(self::OPENAI, 'none', 'openai', 'gpt-6-luna')), 'at none the values stay');
        self::assertSame($body, EffortMapping::withoutSampling($body, $this->profile(self::OPENAI, 'low', 'openai', 'gpt-5.1')), 'a model without the rule is not touched');
    }

    public static function claudeModels(): iterable
    {
        yield 'haiku 5.5 takes xhigh' => ['claude-haiku-5-5', 'xhigh', ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'xhigh']]];
        yield 'haiku 5.5 with minimal is adaptive at low, the lowest level it takes' => ['claude-haiku-5-5', 'minimal', ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'low']]];
        yield 'haiku 5.5 turns thinking off with disabled, which it takes up to high' => ['claude-haiku-5-5', 'none', ['thinking' => ['type' => 'disabled']]];
        yield 'sonnet 4.6 has no xhigh, so it is sent as high' => ['claude-sonnet-4-6', 'xhigh', ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'high']]];
        yield 'sonnet 5 turns thinking off with disabled, which it takes' => ['claude-sonnet-5', 'none', ['thinking' => ['type' => 'disabled']]];
        yield 'sonnet 5.5 turns up-front thinking off with between_tools' => ['claude-sonnet-5-5', 'none', ['thinking' => ['type' => 'between_tools']]];
        yield 'opus 5.5 cannot turn thinking off, so none is adaptive at its lowest level' => ['claude-opus-5-5', 'none', ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'low']]];
        yield 'mythos preview cannot turn thinking off, so none is adaptive at low' => ['claude-mythos-preview', 'none', ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'low']]];
        yield 'mythos preview has no xhigh, so it is sent as high' => ['claude-mythos-preview', 'xhigh', ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'high']]];
        yield 'a model the table does not list takes the level as it is' => ['some-future-model', 'max', ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'max']]];
        yield 'a model the table does not list turns thinking off with disabled' => ['some-future-model', 'none', ['thinking' => ['type' => 'disabled']]];
    }

    #[DataProvider('claudeModels')]
    public function testAClaudeModelIsSentTheThinkingAndTheEffortItTakes(string $model, string $thinking, array $expected): void
    {
        self::assertSame($expected, EffortMapping::anthropic($thinking, $model, 2048));
    }

    public function testAModelThatThinksOnABudgetGetsTheBudgetOfItsLevelAndTheEffortItTakes(): void
    {
        self::assertSame(
            ['thinking' => ['type' => 'enabled', 'budget_tokens' => 1024], 'output_config' => ['effort' => 'low']],
            EffortMapping::anthropic('low', 'claude-opus-4-5-20251101', 2048),
        );
        self::assertSame(
            ['thinking' => ['type' => 'enabled', 'budget_tokens' => 8000], 'output_config' => ['effort' => 'high']],
            EffortMapping::anthropic('max', 'claude-opus-4-5-20251101', 16000),
            'max has no level on Opus 4.5, so it takes the high budget, which half of max_tokens 16000 keeps at 8000, and high effort',
        );
        self::assertSame(
            ['thinking' => ['type' => 'enabled', 'budget_tokens' => 1024], 'output_config' => ['effort' => 'low']],
            EffortMapping::anthropic('minimal', 'claude-opus-4-5-20251101', 16000),
            'minimal takes the lowest budget',
        );
    }

    public static function budgets(): iterable
    {
        yield 'medium asks for 4096, and max_tokens 2048 keeps the budget at half, 1024' => ['medium', 2048, 1024];
        yield 'high at max_tokens 4096 takes 2048' => ['high', 4096, 2048];
        yield 'high at max_tokens 16384 takes its own 8192' => ['high', 16384, 8192];
        yield 'the lowest budget, 1024, needs max_tokens 2048' => ['low', 2048, 1024];
        yield 'max_tokens 2047 leaves 1023, below the minimum: thinking is off' => ['minimal', 2047, null];
        yield 'max_tokens 1024 leaves 512: thinking is off' => ['high', 1024, null];
    }

    #[DataProvider('budgets')]
    public function testTheBudgetIsAtMostHalfOfMaxTokensAndThinkingIsOffBelowTheMinimum(string $thinking, int $maxTokens, ?int $budget): void
    {
        $expected = $budget === null
            ? ['thinking' => ['type' => 'disabled']]
            : ['thinking' => ['type' => 'enabled', 'budget_tokens' => $budget], 'output_config' => ['effort' => $thinking]];

        self::assertSame($expected, EffortMapping::anthropic($thinking, 'claude-opus-4-5-20251101', $maxTokens));
    }

    public function testTheCapabilitiesTheAPIReportsReplaceTheTable(): void
    {
        $reported = ['effort' => ['low', 'medium'], 'thinking' => ['between_tools', 'adaptive']];

        self::assertSame(['thinking' => ['type' => 'between_tools']], EffortMapping::anthropic('none', 'claude-opus-5-5', 2048, $reported));
        self::assertSame(
            ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'medium']],
            EffortMapping::anthropic('high', 'claude-opus-5-5', 2048, $reported),
            'the model reports no high, so it takes medium',
        );
    }

    public static function ollamaNames(): iterable
    {
        yield 'none is off, whatever the model names' => ['none', ['low', 'medium', 'high'], false];
        yield 'off is none' => ['off', ['low', 'medium', 'high'], false];
        yield 'a model that names no level takes the switch' => ['high', [], true];
        yield 'a name the table does not know is not a level' => ['high', ['think-hard'], true];
        yield 'low, which the model names' => ['low', ['low', 'medium', 'high'], 'low'];
        yield 'on is medium, which the model names' => ['on', ['low', 'medium', 'high'], 'medium'];
        yield 'minimal goes to low, the lowest name' => ['minimal', ['low', 'medium', 'high'], 'low'];
        yield 'xhigh goes to high, the highest name below it' => ['xhigh', ['low', 'medium', 'high'], 'high'];
        yield 'max goes to high, the highest name below it' => ['max', ['low', 'medium', 'high'], 'high'];
        yield 'max goes to xhigh when the model names it' => ['max', ['low', 'xhigh'], 'xhigh'];
        yield 'minimal with medium and high goes to medium, the lowest name the model has' => ['minimal', ['medium', 'high'], 'medium'];
        yield 'medium with only high goes to high, the lowest name the model has' => ['medium', ['high'], 'high'];
    }

    #[DataProvider('ollamaNames')]
    public function testOllamaSendsTheNameTheModelTakesOrTheSwitch(string $thinking, array $names, bool|string $think): void
    {
        self::assertSame($think, EffortMapping::ollama($thinking, $names));
    }

    public function testTheLevelOfAValueResolvesTheAliasesAndRefusesTheRest(): void
    {
        self::assertSame('none', EffortMapping::level('off'));
        self::assertSame('medium', EffortMapping::level('on'));
        self::assertSame('xhigh', EffortMapping::level('xhigh'));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage("unknown effort level 'ultra'");
        EffortMapping::level('ultra');
    }

    public function testOnlyOpenAisOwnHostIsTheOpenAiEndpoint(): void
    {
        self::assertTrue(EffortMapping::isOpenAi($this->profile('https://api.openai.com/v1', 'none')));
        self::assertFalse(EffortMapping::isOpenAi($this->profile('https://api.openai.com.proxy.example/v1', 'none')));
        self::assertFalse(EffortMapping::isOpenAi($this->profile('http://127.0.0.1:8082', 'none')));
        self::assertFalse(EffortMapping::isOpenAi($this->profile('unix:///tmp/llama.sock', 'none')));
    }

    public function testTheTableNamesEveryLevelForEachWireFormatButNoneForAnthropic(): void
    {
        foreach (['openai-responses', 'openai', 'llama'] as $format) {
            self::assertSame(Profile::EFFORTS, array_keys(EffortMapping::WIRE[$format]), $format);
        }
        self::assertSame(
            array_values(array_diff(Profile::EFFORTS, ['none'])),
            array_keys(EffortMapping::WIRE['anthropic']),
            'anthropic has no value for none: thinking is turned off, or stays on at the lowest level, by anthropic()',
        );
    }

    public function testEveryOpenAiModelOfTheTableTakesLevelsInOrderAndAKnownChatRoute(): void
    {
        foreach (EffortMapping::OPENAI_MODELS as $model => $row) {
            self::assertSame(array_values(array_intersect(Profile::EFFORTS, $row['levels'])), $row['levels'], "{$model}: the levels are in order");
            self::assertContains($row['chat'], ['yes', 'no-tools', 'tools-only-with-none', 'no'], $model);
        }
    }

    public function testEveryAnthropicModelOfTheTableTakesLevelsInOrderAndKnownThinkingTypes(): void
    {
        foreach (EffortMapping::ANTHROPIC_MODELS as $model => $spec) {
            self::assertSame(array_values(array_intersect(Profile::EFFORTS, $spec['effort'])), $spec['effort'], "{$model}: the levels are in order");
            self::assertNotSame([], $spec['thinking'], $model);
            self::assertSame([], array_diff($spec['thinking'], ['adaptive', 'between_tools', 'disabled', 'enabled']), "{$model}: the types are known");
        }
    }

    public static function notes(): iterable
    {
        $openAi = self::OPENAI;
        yield 'a table row names the range and the levels sent as another one' => [$openAi, 'openai', 'gpt-6.1-sol', 'gpt-6.1-sol takes low to max: none and minimal are sent as low'];
        yield 'a row with none and a range that starts at low' => [$openAi, 'openai', 'gpt-6-luna', 'gpt-6-luna takes none and low to max: minimal is sent as low'];
        yield 'a row whose top is xhigh' => [$openAi, 'openai', 'gpt-5.5', 'gpt-5.5 takes none and low to xhigh: minimal is sent as low; max is sent as xhigh'];
        yield 'two groups of levels sent as other levels' => [$openAi, 'openai', 'gpt-5', 'gpt-5 takes minimal to high: none is sent as minimal; xhigh and max are sent as high'];
        yield 'Responses names the same table' => [$openAi, 'openai-responses', 'gpt-6.1-sol', 'gpt-6.1-sol takes low to max: none and minimal are sent as low'];
        yield 'Claude with adaptive thinking only cannot turn thinking off' => ['https://api.anthropic.com', 'anthropic', 'claude-opus-5-5', 'claude-opus-5-5 takes low to max: none and minimal are sent as low; thinking cannot be turned off'];
        yield 'Claude with disabled can turn thinking off' => ['https://api.anthropic.com', 'anthropic', 'claude-haiku-5-5', 'claude-haiku-5-5 takes none and low to max: minimal is sent as low; none turns thinking off'];
        yield 'Claude with a budget' => ['https://api.anthropic.com', 'anthropic', 'claude-opus-4-5-20251101', 'claude-opus-4-5-20251101 takes none and low to high: minimal is sent as low; xhigh and max are sent as high; none turns thinking off'];
        yield 'Claude that the table does not list' => ['https://api.anthropic.com', 'anthropic', 'some-future-model', 'the model is not in the table: each level is sent as it is, and none turns thinking off where the model allows it'];
        yield 'an OpenAI model that the table does not list' => [$openAi, 'openai', 'some-model', 'reasoning_effort: the level is sent as it is, and none is no reasoning'];
        yield 'llama.cpp takes the switch' => ['http://127.0.0.1:8082', 'openai', 'qwen', 'none turns thinking off; every other level turns it on (enable_thinking)'];
        yield 'Ollama names its levels' => ['http://127.0.0.1:11434', 'ollama', 'qwen', 'on ollama, a level the model does not name is sent as the closest name below it, or else the lowest one it names; a model that names no levels takes thinking on for every level but none'];
    }

    #[DataProvider('notes')]
    public function testTheNoteOfEachProviderSaysWhereTheLevelsGo(string $baseUrl, string $protocol, string $model, string $note): void
    {
        self::assertSame($note, EffortMapping::note($this->profile($baseUrl, 'none', $protocol, $model), $model));
    }

    public function testTheNoteOfAHostedServerSaysThatItGetsNoThinkingField(): void
    {
        self::assertSame(
            'reasoning_effort: the levels of llama-3 are not known here, so no field is sent and the level has no effect',
            EffortMapping::note($this->profile('https://api.groq.com/openai', 'none', 'openai', 'llama-3'), 'llama-3'),
            'Groq takes reasoning_effort, but the levels of a model that its table does not list are not known',
        );
        self::assertSame(
            'this server gets no thinking field, so the level has no effect',
            EffortMapping::note($this->profile('https://example.com/v1', 'none', 'openai', 'llama-3'), 'llama-3'),
        );
        self::assertSame(
            'reasoning.effort: the level is sent as it is, and none is no reasoning',
            EffortMapping::note($this->profile('https://openrouter.ai/api', 'none', 'openai-responses', 'x'), 'x'),
        );
    }

    public static function acceptedLevels(): iterable
    {
        yield 'GPT-6 Luna takes none and the levels from low' => ['gpt-6-luna', 'openai', ['none', 'low', 'medium', 'high', 'xhigh', 'max']];
        yield 'GPT-6 Astra takes the levels from low' => ['gpt-6-astra', 'openai', ['low', 'medium', 'high', 'xhigh', 'max']];
        yield 'a model that is not in the table is not known' => ['gpt-x', 'openai', null];
        yield 'Claude with disabled takes none and its efforts' => ['claude-sonnet-5', 'anthropic', ['none', 'low', 'medium', 'high', 'xhigh', 'max']];
        yield 'Claude with adaptive thinking only takes its efforts' => ['claude-opus-5-5', 'anthropic', ['low', 'medium', 'high', 'xhigh', 'max']];
        yield 'Claude that the table does not list is not known' => ['some-future-model', 'anthropic', null];
    }

    #[DataProvider('acceptedLevels')]
    public function testTheLevelsThatAModelTakesAsTheyAreAreKnownFromTheTables(string $model, string $protocol, ?array $expected): void
    {
        $baseUrl = $protocol === 'anthropic' ? 'https://api.anthropic.com' : self::OPENAI;

        self::assertSame($expected, EffortMapping::accepted($this->profile($baseUrl, 'none', $protocol, $model), $model));
    }

    public function testAServerThatTakesASwitchOrNamesItsLevelsDoesNotListLevels(): void
    {
        self::assertNull(EffortMapping::accepted($this->profile('http://127.0.0.1:8082', 'none', 'openai', 'gpt-6-luna'), 'gpt-6-luna'));
        self::assertNull(EffortMapping::accepted($this->profile('http://127.0.0.1:11434', 'none', 'ollama', 'gpt-oss'), 'gpt-oss'));
    }

    public static function formats(): iterable
    {
        yield 'OpenAI takes reasoning_effort' => ['https://api.openai.com/v1', 'auto', 'openai', 'openai'];
        yield 'OpenRouter takes reasoning.effort, whatever the case of its host' => ['https://OpenRouter.ai/api/v1', 'auto', 'openai', 'openrouter'];
        yield 'a subdomain of OpenRouter takes it too' => ['https://eu.openrouter.ai/api/v1', 'auto', 'openai', 'openrouter'];
        yield 'DeepSeek takes thinking with reasoning_effort' => ['https://api.deepseek.com', 'auto', 'openai', 'deepseek'];
        yield 'Groq takes reasoning_effort' => ['https://api.groq.com/openai', 'auto', 'openai', 'openai'];
        yield 'Gemini takes reasoning_effort' => ['https://generativelanguage.googleapis.com/v1beta/openai/', 'auto', 'openai', 'openai'];
        yield 'Mistral takes reasoning_effort' => ['https://api.mistral.ai/v1', 'auto', 'openai', 'openai'];
        yield 'xAI takes reasoning_effort' => ['https://api.x.ai/v1', 'auto', 'openai', 'openai'];
        yield 'llama.cpp takes the switch' => ['http://127.0.0.1:8082', 'auto', 'openai', 'chat_template'];
        yield 'a hosted server that is not listed gets no field' => ['https://example.com/v1', 'auto', 'openai', 'none'];
        yield 'an explicit format wins over the host' => ['https://openrouter.ai/api/v1', 'chat_template', 'openai', 'chat_template'];
        yield 'the other protocols have a mapping of their own' => ['https://openrouter.ai/api/v1', 'auto', 'anthropic', null];
    }

    #[DataProvider('formats')]
    public function testTheFormatComesFromTheHostUnlessTheConfigNamesIt(string $baseUrl, string $configured, string $protocol, ?string $format): void
    {
        self::assertSame($format, EffortMapping::format($this->profile($baseUrl, 'none', $protocol, 'm', $configured)));
    }

    public static function listedModels(): iterable
    {
        yield 'Groq: gpt-oss takes low to high, so none is sent as low' => ['https://api.groq.com/openai', 'openai/gpt-oss-120b', 'none', 'low'];
        yield 'Groq: Qwen 3.8 takes none, which is sent as it is' => ['https://api.groq.com/openai', 'qwen/qwen3.8-27b', 'none', 'none'];
        yield 'Groq: Muse Spark Open always reasons, so none is sent as low' => ['https://api.groq.com/openai', 'meta-models/muse-spark-open', 'none', 'low'];
        yield 'Gemini 2.5 Pro cannot turn reasoning off' => ['https://generativelanguage.googleapis.com/v1beta/openai/', 'gemini-2.5-pro', 'none', 'low'];
        yield 'Gemini 2.5 Flash-Lite with a date takes none' => ['https://generativelanguage.googleapis.com/v1beta/openai/', 'gemini-2.5-flash-lite-2026-01-01', 'none', 'none'];
        yield 'Mistral small takes none and high, so xhigh is sent as high' => ['https://api.mistral.ai/v1', 'mistral-small-latest', 'xhigh', 'high'];
        yield 'Mistral zai-glm-5-3 has no none, so none is sent as low' => ['https://api.mistral.ai/v1', 'zai-glm-5-3', 'none', 'low'];
    }

    #[DataProvider('listedModels')]
    public function testAModelOfAnotherHostIsSentTheLevelItsTableGives(string $baseUrl, string $model, string $thinking, string $effort): void
    {
        self::assertSame(['reasoning_effort' => $effort], EffortMapping::chatFields($this->profile($baseUrl, $thinking, 'openai', $model)));
    }

    public function testTheTablesOfTheOtherHostsTakeTheDateSuffixAndNoOtherChange(): void
    {
        self::assertSame(['low', 'medium', 'high'], EffortMapping::accepted($this->profile('https://api.groq.com/openai', 'none', 'openai', 'openai/gpt-oss-20b-2026-08-05'), 'openai/gpt-oss-20b-2026-08-05'));
        self::assertNull(EffortMapping::accepted($this->profile('https://api.groq.com/openai', 'none', 'openai', 'openai/gpt-oss-20b-x'), 'openai/gpt-oss-20b-x'), 'a suffix that is not a date is not dropped');
        self::assertNull(EffortMapping::accepted($this->profile('https://generativelanguage.googleapis.com/v1beta/openai/', 'none', 'openai', 'gemini-3.5-pro'), 'gemini-3.5-pro'), 'Gemini 3 has no table');
        self::assertNull(EffortMapping::accepted($this->profile('https://openrouter.ai/api/v1', 'none', 'openai', 'openai/gpt-oss-20b'), 'openai/gpt-oss-20b'), 'OpenRouter has no table of its own');
        self::assertNull(EffortMapping::accepted($this->profile('http://127.0.0.1:8082', 'none', 'openai', 'openai/gpt-oss-20b'), 'openai/gpt-oss-20b'), 'llama.cpp takes a switch');
    }

    public static function deepSeekLevels(): iterable
    {
        yield 'none turns thinking off' => ['none', ['thinking' => ['type' => 'disabled']]];
        yield 'minimal is sent as low, the lowest level it takes' => ['minimal', ['thinking' => ['type' => 'enabled'], 'reasoning_effort' => 'low']];
        yield 'medium is never sent above the level asked for, so it is low' => ['medium', ['thinking' => ['type' => 'enabled'], 'reasoning_effort' => 'low']];
        yield 'high is high' => ['high', ['thinking' => ['type' => 'enabled'], 'reasoning_effort' => 'high']];
        yield 'xhigh is high, the highest it takes below' => ['xhigh', ['thinking' => ['type' => 'enabled'], 'reasoning_effort' => 'high']];
        yield 'max is max' => ['max', ['thinking' => ['type' => 'enabled'], 'reasoning_effort' => 'max']];
    }

    #[DataProvider('deepSeekLevels')]
    public function testDeepSeekTakesThinkingAndItsOwnEffortValues(string $thinking, array $fields): void
    {
        self::assertSame($fields, EffortMapping::chatFields($this->profile('https://api.deepseek.com', $thinking, 'openai', 'deepseek-v4-pro')));
    }

    public function testAnUnlistedDeepSeekModelWithTheFormatSetSendsDeepSeeksOwnMapping(): void
    {
        self::assertSame(
            ['thinking' => ['type' => 'enabled'], 'reasoning_effort' => 'high'],
            EffortMapping::chatFields($this->profile('https://example.com/v1', 'medium', 'openai', 'x', 'deepseek')),
            'the format is set, so the level goes as the server maps it: medium is high',
        );
    }

    public function testOpenRouterTakesAnEffortObjectClampedToTheLevelsItsListingReports(): void
    {
        $reported = ['effort' => ['low', 'high'], 'mandatory' => false];

        self::assertSame(['reasoning' => ['effort' => 'low']], EffortMapping::chatFields($this->profile('https://openrouter.ai/api/v1', 'none', 'openai', 'x'), $reported), 'none is not listed: the lowest level');
        self::assertSame(['reasoning' => ['effort' => 'high']], EffortMapping::chatFields($this->profile('https://openrouter.ai/api/v1', 'max', 'openai', 'x'), $reported));
    }

    public function testAMandatoryReasoningNeverGetsNone(): void
    {
        $profile = $this->profile('https://openrouter.ai/api/v1', 'none', 'openai', 'x');

        self::assertSame(['reasoning' => ['effort' => 'low']], EffortMapping::chatFields($profile, ['effort' => ['none', 'low', 'high'], 'mandatory' => true]), 'the model rejects none');
        self::assertSame(['reasoning' => ['effort' => 'none']], EffortMapping::chatFields($profile, ['effort' => ['none', 'low'], 'mandatory' => false]));
    }

    public function testWithoutAListingOrATableAnAutoFormatSendsNothingAndAnExplicitOneSendsTheLevel(): void
    {
        self::assertSame([], EffortMapping::chatFields($this->profile('https://openrouter.ai/api/v1', 'high', 'openai', 'unknown/model')));
        self::assertSame(['reasoning' => ['effort' => 'high']], EffortMapping::chatFields($this->profile('https://example.com/v1', 'high', 'openai', 'unknown/model', 'openrouter')), 'an explicit format sends the level as it is');
    }

    public function testTheLevelsTheConfigDeclaresComeBeforeTheListingAndTheTables(): void
    {
        $declared = $this->profile('https://api.groq.com/openai', 'max', 'openai', 'openai/gpt-oss-120b', 'auto', ['low', 'high']);
        $tabled = $this->profile('https://api.groq.com/openai', 'max', 'openai', 'openai/gpt-oss-120b');

        self::assertSame(['reasoning_effort' => 'high'], EffortMapping::chatFields($declared, ['effort' => ['low', 'medium', 'max'], 'mandatory' => false]), 'the declared levels win');
        self::assertSame(['max'], EffortMapping::accepted($tabled, 'openai/gpt-oss-120b', ['effort' => ['max'], 'mandatory' => false]), 'the listing wins over the table');
        self::assertSame(['low', 'medium', 'high'], EffortMapping::accepted($tabled, 'openai/gpt-oss-120b'));
    }

    public function testOnlyTheHostsWithAListingAreAskedForTheirLevels(): void
    {
        EffortMapping::setTestHost('api.x.ai', '127.0.0.1');

        self::assertTrue(EffortMapping::readsListing($this->profile('https://openrouter.ai/api/v1', 'none')));
        self::assertTrue(EffortMapping::readsListing($this->profile('https://api.deepseek.com', 'none')));
        self::assertTrue(EffortMapping::readsListing($this->profile('http://127.0.0.1:8082', 'none')), 'xAI, on a loopback server for the test');
        self::assertFalse(EffortMapping::readsListing($this->profile('https://api.openai.com/v1', 'none')));
        self::assertFalse(EffortMapping::readsListing($this->profile('https://api.groq.com/openai', 'none')));
    }

    public function testTheSamplingValuesThatReasoningRefusesGoWithReasoningAndStayWithoutIt(): void
    {
        $body = ['model' => 'm', 'temperature' => 0.6, 'presence_penalty' => 0.0, 'frequency_penalty' => 0.1, 'stop' => ['x']];
        $kept = ['model' => 'm', 'temperature' => 0.6];

        self::assertSame($kept, EffortMapping::withoutReasoningSampling($body, ['reasoning_effort' => 'low']));
        self::assertSame($kept, EffortMapping::withoutReasoningSampling($body, ['reasoning' => ['effort' => 'high']]));
        self::assertSame($kept, EffortMapping::withoutReasoningSampling($body, ['thinking' => ['type' => 'enabled'], 'reasoning_effort' => 'high']));
        self::assertSame($body, EffortMapping::withoutReasoningSampling($body, ['reasoning_effort' => 'none']), 'none is no reasoning');
        self::assertSame($body, EffortMapping::withoutReasoningSampling($body, ['reasoning' => ['effort' => 'none']]));
        self::assertSame($body, EffortMapping::withoutReasoningSampling($body, ['thinking' => ['type' => 'disabled']]));
        self::assertSame($body, EffortMapping::withoutReasoningSampling($body, ['chat_template_kwargs' => ['enable_thinking' => true]]), 'the llama.cpp switch keeps them');
        self::assertSame($body, EffortMapping::withoutReasoningSampling($body, []));
    }

    public function testTheNoteNamesTheFieldAndWhereTheLevelsComeFrom(): void
    {
        self::assertSame(
            'reasoning_effort (the table): openai/gpt-oss-120b takes low to high: none and minimal are sent as low; xhigh and max are sent as high',
            EffortMapping::note($this->profile('https://api.groq.com/openai', 'none', 'openai', 'openai/gpt-oss-120b'), 'openai/gpt-oss-120b'),
        );
        self::assertSame(
            'reasoning.effort (the listing): x takes low to high: none, minimal and medium are sent as low; xhigh and max are sent as high',
            EffortMapping::note($this->profile('https://openrouter.ai/api/v1', 'none', 'openai', 'x'), 'x', ['effort' => ['low', 'high'], 'mandatory' => false]),
        );
        self::assertSame(
            'reasoning.effort (the listing): x takes low to high: none, minimal and medium are sent as low; xhigh and max are sent as high; reasoning cannot be turned off',
            EffortMapping::note($this->profile('https://openrouter.ai/api/v1', 'none', 'openai', 'x'), 'x', ['effort' => ['low', 'high'], 'mandatory' => true]),
        );
        self::assertSame(
            'reasoning.effort (effort_levels): x takes low to high: none, minimal and medium are sent as low; xhigh and max are sent as high',
            EffortMapping::note($this->profile('https://openrouter.ai/api/v1', 'none', 'openai', 'x', 'auto', ['low', 'high']), 'x'),
        );
        self::assertSame(
            'thinking and reasoning_effort (the table): deepseek-v4-pro takes none and low to max: minimal and medium are sent as low; xhigh is sent as high; none turns thinking off',
            EffortMapping::note($this->profile('https://api.deepseek.com', 'none', 'openai', 'deepseek-v4-pro'), 'deepseek-v4-pro'),
        );
        self::assertSame(
            'reasoning.effort: the levels of x are not known here, so no field is sent and the level has no effect',
            EffortMapping::note($this->profile('https://openrouter.ai/api/v1', 'none', 'openai', 'x'), 'x'),
        );
        self::assertSame(
            'reasoning.effort: the level is sent as it is, and none is no reasoning',
            EffortMapping::note($this->profile('https://example.com/v1', 'none', 'openai', 'x', 'openrouter'), 'x'),
        );
    }

    private function profile(string $baseUrl, string $thinking, string $protocol = 'openai', string $model = 'm', string $format = 'auto', ?array $levels = null): Profile
    {
        return new Profile(
            name: 'test',
            baseUrl: $baseUrl,
            model: $model,
            toolMode: 'auto',
            contextLimit: 16384,
            maxOutputTokens: 2048,
            sampling: [],
            extraBody: [],
            apiKeyFile: null,
            apiKeyEnv: null,
            protocol: $protocol,
            thinking: $thinking,
            effortFormat: $format,
            effortLevels: $levels,
        );
    }
}
