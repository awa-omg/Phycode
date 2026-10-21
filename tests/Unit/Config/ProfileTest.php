<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Config\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    private function profile(array $fields = []): Profile
    {
        return Config::fromArrays(
            ['profiles' => ['test' => ['base_url' => 'http://127.0.0.1:8082', 'model' => 'qwen35-2b'] + $fields]],
            null,
            [],
            '/nonexistent',
        )->profile('test');
    }

    public static function servers(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1:9931', true];
        yield 'localhost' => ['http://localhost:8080', true];
        yield 'ipv6 loopback' => ['http://[::1]:8080', true];
        yield 'a private address' => ['http://192.168.1.20:8080', true];
        yield 'the 10 network' => ['http://10.0.0.5', true];
        yield 'the 172 network' => ['http://172.20.0.2:8000', true];
        yield 'a tailscale address' => ['http://100.101.2.3:8080', true];
        yield 'a link-local address' => ['http://169.254.1.1', true];
        yield 'a service name with no dot' => ['http://llama:8080', true];
        yield 'a .local name' => ['http://studio.local:1234', true];
        yield 'a .lan name' => ['http://box.lan', true];
        yield 'a unix socket' => ['unix:///tmp/llama.sock', true];
        yield 'a public address' => ['http://8.8.8.8:80', false];
        yield 'a 100 address outside the tailscale range' => ['http://100.200.1.1', false];
        yield 'a hosted API' => ['https://api.openai.com', false];
        yield 'another hosted API' => ['https://openrouter.ai/api', false];
        yield 'a public name' => ['http://example.com:8080', false];
    }

    #[DataProvider('servers')]
    public function testAServerIsLocalWhenItIsOnThisMachineOrItsNetwork(string $url, bool $local): void
    {
        self::assertSame($local, Profile::localUrl($url));
    }

    public function testAHostedApiGetsNoSamplingOfItsOwnBecauseItRefusesWhatLlamaCppTakes(): void
    {
        $config = Config::fromArrays(['profiles' => [
            'hosted' => ['base_url' => 'https://api.openai.com', 'model' => 'gpt'],
            'local' => ['base_url' => 'http://127.0.0.1:9931', 'model' => 'qwen'],
            'tuned' => ['base_url' => 'https://api.openai.com', 'model' => 'gpt', 'sampling' => ['temperature' => 0.2]],
        ]], null, [], '/nonexistent');

        self::assertSame([], $config->profile('hosted')->sampling);
        self::assertSame(['temperature' => 0.6, 'top_p' => 0.95, 'top_k' => 20, 'min_p' => 0.0, 'presence_penalty' => 0.0], $config->profile('local')->sampling);
        self::assertSame(['temperature' => 0.2], $config->profile('tuned')->sampling, 'a sampling that the config sets is kept');
    }

    public function testOpenAiGetsTheNameForTheLimitOfTheReplyThatItsModelsTake(): void
    {
        $config = Config::fromArrays(['profiles' => [
            'openai' => ['base_url' => 'https://api.openai.com', 'model' => 'gpt'],
            'forced' => ['base_url' => 'https://api.openai.com', 'model' => 'gpt', 'token_param' => 'max_tokens'],
            'other' => ['base_url' => 'https://openrouter.ai/api', 'model' => 'm'],
        ]], null, [], '/nonexistent');

        self::assertSame(
            ['max_completion_tokens', 'max_tokens', 'max_tokens'],
            [$config->profile('openai')->tokenParam, $config->profile('forced')->tokenParam, $config->profile('other')->tokenParam],
        );
    }

    public function testTheNewSettingsHaveTheirDefaults(): void
    {
        $profile = $this->profile();

        self::assertSame('openai', $profile->protocol);
        self::assertSame('none', $profile->thinking);
        self::assertSame('max_tokens', $profile->tokenParam);
        self::assertSame('5m', $profile->keepAlive);
        self::assertSame([], $profile->extraBody, 'thinking now sets the chat template switch, so extra_body starts empty');
    }

    public function testTheNewSettingsAreReadFromTheConfig(): void
    {
        $profile = $this->profile([
            'protocol' => 'ollama',
            'thinking' => 'medium',
            'token_param' => 'max_completion_tokens',
            'keep_alive' => '30s',
        ]);

        self::assertSame('ollama', $profile->protocol);
        self::assertSame('medium', $profile->thinking);
        self::assertSame('max_completion_tokens', $profile->tokenParam);
        self::assertSame('30s', $profile->keepAlive);
    }

    public static function keepAliveValues(): iterable
    {
        yield 'zero unloads at once' => [0, '0'];
        yield 'minus one keeps the model' => [-1, '-1'];
        yield 'a number as text' => ['300', '300'];
        yield 'an hour' => ['1h', '1h'];
        yield 'milliseconds' => ['250ms', '250ms'];
    }

    #[DataProvider('keepAliveValues')]
    public function testKeepAliveAcceptsDurationsAndWholeSeconds(mixed $value, string $expected): void
    {
        self::assertSame($expected, $this->profile(['keep_alive' => $value])->keepAlive);
    }

    public static function baseUrlsOfAPath(): iterable
    {
        yield 'a host alone' => ['http://127.0.0.1:8082', 'http://127.0.0.1:8082/v1/models'];
        yield 'a hand-written /v1' => ['http://127.0.0.1:8082/v1', 'http://127.0.0.1:8082/v1/models'];
        yield 'a hand-written /v1/' => ['http://127.0.0.1:8082/v1/', 'http://127.0.0.1:8082/v1/models'];
        yield 'a host named v1' => ['http://v1', 'http://v1/v1/models'];
    }

    #[DataProvider('baseUrlsOfAPath')]
    public function testTheApiPathIsAddedOnceWhateverTheBaseUrlEndsWith(string $base, string $url): void
    {
        self::assertSame($url, $this->profile()->override($base, null)->url('/v1/models'));
    }

    public function testWithModelReturnsANewProfileAndKeepsTheRest(): void
    {
        $profile = $this->profile(['thinking' => 'high', 'token_param' => 'max_completion_tokens', 'protocol' => 'ollama']);

        $other = $profile->withModel('other-model');

        self::assertSame('other-model', $other->model);
        self::assertSame('qwen35-2b', $profile->model, 'the original is unchanged');
        self::assertSame('high', $other->thinking);
        self::assertSame('max_completion_tokens', $other->tokenParam);
        self::assertSame('ollama', $other->protocol);
        self::assertSame($profile->baseUrl, $other->baseUrl);
    }

    public function testWithThinkingReturnsANewProfileAndOnlyTheLevelsAreAccepted(): void
    {
        $profile = $this->profile();

        $on = $profile->withThinking('on');
        self::assertSame('medium', $on->thinking, 'on is the level it stood for');
        self::assertSame('none', $profile->thinking);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('profile test: thinking must be none, minimal, low, medium, high, xhigh or max (off and on are also accepted)');
        $profile->withThinking('ultra');
    }

    public function testTheLevelsAndTheAliasesAreTheOnesTheSpecSays(): void
    {
        self::assertSame(['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], Profile::EFFORTS);
        self::assertSame(['off' => 'none', 'on' => 'medium'], Profile::ALIASES);
        self::assertSame('none, minimal, low, medium, high, xhigh or max', Profile::levelsText());
    }

    public static function levelsAndAliases(): iterable
    {
        foreach (Profile::EFFORTS as $level) {
            yield "the level {$level}" => [$level, $level];
        }
        yield 'off is none' => ['off', 'none'];
        yield 'on is medium' => ['on', 'medium'];
    }

    #[DataProvider('levelsAndAliases')]
    public function testEveryLevelAndEveryAliasIsAcceptedForTheConfigAndForWithThinking(string $value, string $canonical): void
    {
        self::assertSame($canonical, $this->profile(['thinking' => $value])->thinking);
        self::assertSame($canonical, $this->profile()->withThinking($value)->thinking);
    }

    public static function valuesThatAreNotLevels(): iterable
    {
        yield 'a level that does not exist' => ['ultra'];
        yield 'upper case' => ['HIGH'];
        yield 'a number as text' => ['1'];
    }

    #[DataProvider('valuesThatAreNotLevels')]
    public function testAValueThatIsNotALevelIsRefusedWithTheList(string $value): void
    {
        try {
            $this->profile(['thinking' => $value]);
            self::fail("thinking '{$value}' was accepted");
        } catch (ConfigException $error) {
            self::assertSame("profile 'test': thinking must be none, minimal, low, medium, high, xhigh or max (off and on are also accepted)", $error->getMessage());
        }
    }

    public function testAProfileWithAnOldThinkingValueStillLoads(): void
    {
        self::assertSame('none', $this->profile(['thinking' => 'off'])->thinking, 'an older config that says off');
        self::assertSame('medium', $this->profile(['thinking' => 'on'])->thinking, 'an older config that says on');
        self::assertSame('low', $this->profile(['thinking' => 'low'])->thinking, 'the levels that older configs use still load');
    }

    public function testTheContextLimitIsSetOnlyWhenTheConfigWritesIt(): void
    {
        $unset = $this->profile();
        self::assertSame([16384, false], [$unset->contextLimit, $unset->contextLimitSet]);

        $set = $this->profile(['context_limit' => 4096]);
        self::assertSame([4096, true], [$set->contextLimit, $set->contextLimitSet]);

        $null = $this->profile(['context_limit' => null]);
        self::assertSame([16384, false], [$null->contextLimit, $null->contextLimitSet], 'null is the same as no key');
    }

    public function testTheContextLimitIsKeptWhenTheModelOrTheServerChanges(): void
    {
        $profile = $this->profile(['context_limit' => 4096]);

        self::assertTrue($profile->withModel('other-model')->contextLimitSet);
        $moved = $profile->override('http://127.0.0.1:9931', null);
        self::assertSame([4096, true], [$moved->contextLimit, $moved->contextLimitSet]);
    }

    public function testOverrideKeepsTheNewSettings(): void
    {
        $profile = $this->profile(['thinking' => 'low', 'token_param' => 'max_completion_tokens', 'keep_alive' => '0']);

        $moved = $profile->override('http://127.0.0.1:9931', null);

        self::assertSame('http://127.0.0.1:9931', $moved->baseUrl);
        self::assertSame('low', $moved->thinking);
        self::assertSame('max_completion_tokens', $moved->tokenParam);
        self::assertSame('0', $moved->keepAlive);
    }
}
