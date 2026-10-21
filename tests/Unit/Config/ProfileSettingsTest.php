<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Config\Profile;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileSettingsTest extends TestCase
{
    private function config(array $profiles): Config
    {
        return Config::fromArrays(['profiles' => $profiles], null, [], '/nonexistent');
    }

    private function profile(array $fields = []): Profile
    {
        return $this->config(['test' => ['base_url' => 'http://127.0.0.1:8082', 'model' => 'qwen35-2b'] + $fields])->profile('test');
    }

    public function testTheNewProfileSettingsHaveTheirDefaults(): void
    {
        $profile = $this->profile();

        self::assertSame('openai', $profile->protocol());
        self::assertFalse($profile->responsesStore());
        self::assertSame('2023-06-01', $profile->anthropicVersion());
        self::assertSame([], $profile->anthropicBeta());
        self::assertSame([], $profile->apiKeyFiles());
        self::assertSame([], $profile->fallback());
        self::assertNull($profile->llamaBlock());
    }

    public static function protocols(): iterable
    {
        yield 'openai' => ['openai'];
        yield 'ollama' => ['ollama'];
        yield 'openai-responses' => ['openai-responses'];
        yield 'anthropic' => ['anthropic'];
    }

    #[DataProvider('protocols')]
    public function testEveryProtocolIsAccepted(string $protocol): void
    {
        self::assertSame($protocol, $this->profile(['protocol' => $protocol])->protocol());
    }

    public function testResponsesStoreIsReadForOpenAiResponses(): void
    {
        self::assertTrue($this->profile(['protocol' => 'openai-responses', 'responses_store' => true])->responsesStore());
        self::assertFalse($this->profile(['protocol' => 'openai-responses', 'responses_store' => false])->responsesStore());
    }

    public function testTheEffortSettingsAreReadWithTheLevelsInTheirOrder(): void
    {
        $profile = $this->profile(['effort_format' => 'openrouter', 'effort_levels' => ['high', 'none', 'low']]);

        self::assertSame(['openrouter', ['none', 'low', 'high']], [$profile->effortFormat, $profile->effortLevels]);
        self::assertSame(['auto', null], [$this->profile()->effortFormat, $this->profile()->effortLevels], 'the defaults: auto, and no levels named');
    }

    public function testTheEffortSettingsSurviveACopy(): void
    {
        $profile = $this->profile(['effort_format' => 'deepseek', 'effort_levels' => ['none', 'max']]);

        $copy = $profile->override('http://127.0.0.1:9931', null)->withModel('other')->withThinking('high');

        self::assertSame(['deepseek', ['none', 'max']], [$copy->effortFormat, $copy->effortLevels]);
    }

    public function testTheAnthropicSettingsAreReadForTheAnthropicProtocol(): void
    {
        $profile = $this->profile(['protocol' => 'anthropic', 'anthropic_version' => '2024-10-22', 'anthropic_beta' => ['prompt-caching-2024-07-31', 'output-128k-2025-02-19']]);

        self::assertSame(['2024-10-22', ['prompt-caching-2024-07-31', 'output-128k-2025-02-19']], [$profile->anthropicVersion(), $profile->anthropicBeta()]);
    }

    public static function invalidProfileSettings(): iterable
    {
        yield 'unknown protocol' => [['protocol' => 'vllm'], 'protocol must be openai, ollama, openai-responses or anthropic'];
        yield 'responses_store as a word' => [['protocol' => 'openai-responses', 'responses_store' => 'yes'], 'responses_store must be true or false'];
        yield 'responses_store with another protocol' => [['protocol' => 'anthropic', 'responses_store' => true], 'responses_store is only used with protocol openai-responses'];
        yield 'anthropic_version with spaces' => [['protocol' => 'anthropic', 'anthropic_version' => '2023 06 01'], 'anthropic_version must look like 2023-06-01'];
        yield 'anthropic_version with another protocol' => [['anthropic_version' => '2023-06-01'], 'anthropic_version is only used with protocol anthropic'];
        yield 'anthropic_beta as a string' => [['protocol' => 'anthropic', 'anthropic_beta' => 'prompt-caching'], 'anthropic_beta must be a list of beta feature names'];
        yield 'anthropic_beta item with a comma' => [['protocol' => 'anthropic', 'anthropic_beta' => ['a,b']], 'anthropic_beta must be a list of beta feature names'];
        yield 'anthropic_beta item with a trailing newline' => [['protocol' => 'anthropic', 'anthropic_beta' => ["prompt-caching\n"]], 'anthropic_beta must be a list of beta feature names'];
        yield 'anthropic_beta with another protocol' => [['anthropic_beta' => ['prompt-caching-2024-07-31']], 'anthropic_beta is only used with protocol anthropic'];
        yield 'effort_format that is not a format' => [['effort_format' => 'vllm'], "effort_format must be auto, openai, openrouter, deepseek, chat_template or none"];
        yield 'effort_format with another protocol' => [['protocol' => 'anthropic', 'effort_format' => 'openai'], 'effort_format is only used with protocol openai'];
        yield 'effort_levels as a string' => [['effort_levels' => 'low'], 'effort_levels must be a non-empty list of levels: none, minimal, low, medium, high, xhigh or max'];
        yield 'effort_levels empty' => [['effort_levels' => []], 'effort_levels must be a non-empty list of levels'];
        yield 'effort_levels with an alias' => [['effort_levels' => ['off']], 'effort_levels must be a non-empty list of levels'];
        yield 'effort_levels with a level that does not exist' => [['effort_levels' => ['low', 'ultra']], 'effort_levels must be a non-empty list of levels'];
        yield 'effort_levels with a level twice' => [['effort_levels' => ['low', 'high', 'low']], 'effort_levels lists a level more than once'];
        yield 'effort_levels with another protocol' => [['protocol' => 'ollama', 'effort_levels' => ['low']], 'effort_levels is only used with protocol openai'];
        yield 'api_key_files as a string' => [['api_key_files' => '/keys/a'], 'api_key_files must be a list of file paths'];
        yield 'api_key_files with an empty path' => [['api_key_files' => ['']], 'api_key_files must be a list of file paths'];
        yield 'api_key_file and api_key_files together' => [['api_key_file' => '/keys/a', 'api_key_files' => ['/keys/b']], 'set api_key_file or api_key_files, not both'];
        yield 'fallback as a string' => [['fallback' => 'other'], 'fallback must be a list of profile names'];
        yield 'fallback to itself' => [['fallback' => ['test']], 'fallback may not name the profile itself'];
        yield 'fallback to an unknown profile' => [['fallback' => ['missing']], "fallback 'missing' is not a known profile"];
        yield 'fallback names a profile twice' => [['fallback' => ['other', 'other']], 'fallback lists a profile more than once'];
        yield 'llama as a string' => [['llama' => 'llama-server'], 'llama must be an object'];
        yield 'llama without a binary' => [['llama' => ['model' => '/models/q.gguf']], 'llama.bin must be a non-empty string'];
        yield 'llama without a model' => [['llama' => ['bin' => '/opt/llama-server']], 'llama.model must be a non-empty string'];
        yield 'unknown llama key' => [['llama' => ['bin' => '/opt/llama-server', 'model' => 'm', 'port' => 8080]], 'llama.port is not a known setting'];
        yield 'llama args as a string' => [['llama' => ['bin' => '/opt/llama-server', 'model' => 'm', 'args' => '-c 4096']], 'llama.args must be a list of strings'];
        yield 'llama env with a number' => [['llama' => ['bin' => '/opt/llama-server', 'model' => 'm', 'env' => ['GGML_THREADS' => 4]]], 'llama.env must map variable names to strings'];
        yield 'llama env with a bad name' => [['llama' => ['bin' => '/opt/llama-server', 'model' => 'm', 'env' => ['1BAD' => 'x']]], 'llama.env must map variable names to strings'];
    }

    #[DataProvider('invalidProfileSettings')]
    public function testAnInvalidProfileSettingIsRejectedWithItsReason(array $fields, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        $this->profile($fields);
    }

    public function testTheLlamaBlockIsReadWithItsDefaultsFilledIn(): void
    {
        $home = (string) getenv('HOME');

        self::assertSame(
            ['bin' => $home . '/llama/llama-server', 'model' => '/models/q.gguf', 'args' => [], 'env' => []],
            $this->profile(['llama' => ['bin' => '~/llama/llama-server', 'model' => '/models/q.gguf']])->llamaBlock(),
        );
        self::assertSame(
            ['bin' => '/opt/llama-server', 'model' => '/models/q.gguf', 'args' => ['-c', '16384'], 'env' => ['GGML_CUDA_NO_PINNED' => '1']],
            $this->profile(['llama' => ['bin' => '/opt/llama-server', 'model' => '/models/q.gguf', 'args' => ['-c', '16384'], 'env' => ['GGML_CUDA_NO_PINNED' => '1']]])->llamaBlock(),
        );
    }

    public function testTheFirstReadableKeyFileIsUsed(): void
    {
        $dir = Kit::folder('key-files');
        Kit::write($dir . '/second.key', "sk-second\n");
        $profile = $this->profile(['api_key_files' => [$dir . '/missing.key', $dir . '/second.key']]);

        self::assertSame([$dir . '/missing.key', $dir . '/second.key'], $profile->apiKeyFiles());
        self::assertSame('sk-second', $profile->apiKey());
    }

    public function testTheSingleApiKeyFileStillWorksAndIsTheOnlyEntry(): void
    {
        $dir = Kit::folder('key-file');
        Kit::write($dir . '/key', "sk-one\n");
        $profile = $this->profile(['api_key_file' => $dir . '/key']);

        self::assertSame([$dir . '/key'], $profile->apiKeyFiles());
        self::assertSame('sk-one', $profile->apiKey());
    }

    public function testNoReadableKeyFileIsAnError(): void
    {
        $profile = $this->profile(['api_key_files' => ['/nonexistent/a.key', '/nonexistent/b.key']]);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('cannot read api_key_file /nonexistent/a.key or /nonexistent/b.key');
        $profile->apiKey();
    }

    public function testAFallbackCycleLoadsAndTheChainStopsAtTheProfileItself(): void
    {
        $config = $this->config([
            'a' => ['base_url' => 'http://127.0.0.1:1', 'model' => 'm', 'fallback' => ['b']],
            'b' => ['base_url' => 'http://127.0.0.1:2', 'model' => 'm', 'fallback' => ['a']],
        ]);

        self::assertSame(['b'], $config->fallbackChain('a'));
        self::assertSame(['a'], $config->fallbackChain('b'));
    }

    public function testTheFallbackChainVisitsEachProfileOnceBreadthFirst(): void
    {
        $server = ['base_url' => 'http://127.0.0.1:1', 'model' => 'm'];
        $config = $this->config([
            'a' => $server + ['fallback' => ['b', 'c']],
            'b' => $server + ['fallback' => ['d', 'c']],
            'c' => $server + ['fallback' => ['a']],
            'd' => $server + ['fallback' => ['b']],
        ]);

        self::assertSame(['b', 'c', 'd'], $config->fallbackChain('a'));
        self::assertSame(['d', 'c', 'a'], $config->fallbackChain('b'));
    }

    public function testAProfileWithoutFallbacksHasAnEmptyChainAndAnUnknownNameIsAnError(): void
    {
        $config = $this->config(['a' => ['base_url' => 'http://127.0.0.1:1', 'model' => 'm']]);

        self::assertSame([], $config->fallbackChain('a'));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("unknown profile 'missing'");
        $config->fallbackChain('missing');
    }

    public function testOverrideAndWithModelKeepTheNewSettings(): void
    {
        $profile = $this->config([
            'test' => [
                'base_url' => 'http://127.0.0.1:8082',
                'model' => 'qwen35-2b',
                'protocol' => 'anthropic',
                'anthropic_version' => '2024-10-22',
                'anthropic_beta' => ['prompt-caching-2024-07-31'],
                'api_key_files' => ['/keys/a', '/keys/b'],
                'fallback' => ['other'],
                'llama' => ['bin' => '/opt/llama-server', 'model' => '/models/q.gguf'],
            ],
            'other' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'm'],
        ])->profile('test');

        $moved = $profile->override('http://127.0.0.1:9931', null)->withModel('other-model');

        self::assertSame('http://127.0.0.1:9931', $moved->baseUrl);
        self::assertSame(
            ['anthropic', '2024-10-22', ['prompt-caching-2024-07-31'], ['/keys/a', '/keys/b'], ['other']],
            [$moved->protocol(), $moved->anthropicVersion(), $moved->anthropicBeta(), $moved->apiKeyFiles(), $moved->fallback()],
        );
        self::assertSame(['bin' => '/opt/llama-server', 'model' => '/models/q.gguf', 'args' => [], 'env' => []], $moved->llamaBlock());
    }
}
