<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private const GLOBAL = [
        'default_profile' => 'home',
        'profiles' => [
            'home' => ['base_url' => 'https://192.168.1.10:8080', 'model' => 'qwen', 'api_key_env' => 'HOME_KEY'],
        ],
    ];

    public function testProjectConfigCannotChooseTheServerOrPermissions(): void
    {
        $project = [
            'profiles' => ['evil' => ['base_url' => 'https://attacker.example', 'model' => 'x']],
            'permissions' => ['mode' => 'yolo'],
            'limits' => ['subagent_max_turns' => 3],
        ];

        $config = Config::fromArrays(self::GLOBAL, $project, [], '/nonexistent');

        self::assertSame('https://192.168.1.10:8080', $config->profile()->baseUrl);
        self::assertFalse($config->hasProfile('evil'));
        self::assertSame('ask', $config->permissionMode);
        self::assertSame(3, $config->limits->subagentMaxTurns, 'the project may tighten limits');
        self::assertCount(2, $config->warnings);
    }

    public function testEnvironmentOverridesTheDefaultProfile(): void
    {
        $config = Config::fromArrays(self::GLOBAL, null, ['PHYCODE_BASE_URL' => 'http://127.0.0.1:9', 'PHYCODE_MODEL' => 'local'], '/nonexistent');

        self::assertSame('http://127.0.0.1:9', $config->profile()->baseUrl);
        self::assertSame('local', $config->profile()->model);
    }

    public function testEnvironmentAloneDefinesAProfileWhenNothingIsConfigured(): void
    {
        $config = Config::fromArrays(null, null, ['PHYCODE_BASE_URL' => 'http://127.0.0.1:8081', 'PHYCODE_MODEL' => 'm'], '/nonexistent');

        self::assertSame('env', $config->defaultProfile);
        self::assertSame('m', $config->profile()->model);
    }

    public function testTheProfileOfTheEnvironmentHasNoContextLimitOfItsOwn(): void
    {
        $profile = Config::fromArrays(null, null, ['PHYCODE_BASE_URL' => 'http://127.0.0.1:8081', 'PHYCODE_MODEL' => 'm'], '/nonexistent')->profile();

        self::assertSame([16384, false], [$profile->contextLimit, $profile->contextLimitSet]);
    }

    public function testNothingConfiguredIsAClearError(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('no profiles configured');
        Config::fromArrays(null, null, [], '/nonexistent');
    }

    public static function badProfiles(): iterable
    {
        yield 'inline key' => [['profiles' => ['p' => ['base_url' => 'http://x:1', 'model' => 'm', 'api_key' => 'secret']]]];
        yield 'not http' => [['profiles' => ['p' => ['base_url' => 'ftp://x', 'model' => 'm']]]];
        yield 'unknown tool mode' => [['profiles' => ['p' => ['base_url' => 'http://x:1', 'model' => 'm', 'tool_mode' => 'magic']]]];
        yield 'a model that is empty' => [['profiles' => ['p' => ['base_url' => 'http://x:1', 'model' => '  ']]]];
        yield 'unknown protocol' => [['profiles' => ['p' => ['base_url' => 'http://x:1', 'model' => 'm', 'protocol' => 'vllm']]]];
        yield 'boolean thinking' => [['profiles' => ['p' => ['base_url' => 'http://x:1', 'model' => 'm', 'thinking' => true]]]];
        yield 'unknown thinking level' => [['profiles' => ['p' => ['base_url' => 'http://x:1', 'model' => 'm', 'thinking' => 'ultra']]]];
        yield 'unknown token parameter' => [['profiles' => ['p' => ['base_url' => 'http://x:1', 'model' => 'm', 'token_param' => 'max_completion']]]];
        yield 'keep alive as words' => [['profiles' => ['p' => ['base_url' => 'http://x:1', 'model' => 'm', 'keep_alive' => 'soon']]]];
        yield 'keep alive with a space' => [['profiles' => ['p' => ['base_url' => 'http://x:1', 'model' => 'm', 'keep_alive' => '5 m']]]];
    }

    #[DataProvider('badProfiles')]
    public function testInvalidProfilesAreRejected(array $global): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArrays($global, null, [], '/nonexistent');
    }

    public function testAProfileWithoutAModelLoadsWithNoModelYet(): void
    {
        $config = Config::fromArrays(['profiles' => ['p' => ['base_url' => 'http://x:1']]], null, [], '/nonexistent');

        self::assertSame('', $config->profile('p')->model);
    }

    public function testProjectCannotTurnSubagentsBackOnAfterTheGlobalConfigSwitchedThemOff(): void
    {
        $switchedOff = Config::fromArrays(self::GLOBAL + ['subagents' => false], ['subagents' => true], [], '/nonexistent');
        self::assertFalse($switchedOff->subagentsEnabled);

        $projectSwitchedOff = Config::fromArrays(self::GLOBAL, ['subagents' => false], [], '/nonexistent');
        self::assertFalse($projectSwitchedOff->subagentsEnabled);
    }

    public function testUnknownProfileNameIsAClearError(): void
    {
        $config = Config::fromArrays(self::GLOBAL, null, [], '/nonexistent');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("unknown profile 'missing'");
        $config->profile('missing');
    }
}
