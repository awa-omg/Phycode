<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Deploy;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Deploy\DeployTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeployTargetTest extends TestCase
{
    public function testATargetTakesTheStrictDefaults(): void
    {
        $target = DeployTarget::fromArray('web', ['kind' => 'vercel']);

        self::assertSame('production', $target->environment);
        self::assertSame('.', $target->dir);
        self::assertSame('always', $target->confirm);
        self::assertSame(900, $target->timeout);
        self::assertTrue($target->forcesApproval());
        self::assertSame(['vercel'], $target->options['command']);
    }

    public function testAProductionTargetAlwaysAsksEvenWithConfirmRules(): void
    {
        self::assertTrue(DeployTarget::fromArray('web', ['kind' => 'vercel', 'confirm' => 'rules'])->forcesApproval());
        self::assertFalse(DeployTarget::fromArray('web', ['kind' => 'vercel', 'environment' => 'preview', 'confirm' => 'rules'])->forcesApproval());
        self::assertTrue(DeployTarget::fromArray('web', ['kind' => 'vercel', 'environment' => 'preview'])->forcesApproval());
    }

    public function testToArrayKeepsOnlyWhatWasGiven(): void
    {
        $raw = ['kind' => 'cloudflare', 'environment' => 'staging', 'confirm' => 'rules', 'secrets' => ['CLOUDFLARE_API_TOKEN'], 'vars' => ['CLOUDFLARE_ACCOUNT_ID' => 'abc'], 'wrangler_env' => 'staging', 'health_url' => 'https://x.example'];
        $target = DeployTarget::fromArray('edge', $raw);

        $array = $target->toArray();
        self::assertSame('cloudflare', $array['kind']);
        self::assertSame('staging', $array['wrangler_env']);
        self::assertArrayNotHasKey('dir', $array);
        self::assertArrayNotHasKey('timeout', $array);
        self::assertArrayNotHasKey('project_name', $array);
        self::assertEquals($target, DeployTarget::fromArray('edge', $array), 'what is written reads back as the same target');
    }

    public static function invalid(): iterable
    {
        yield 'no kind' => [[], 'kind'];
        yield 'an unknown kind' => [['kind' => 'heroku'], 'kind'];
        yield 'an unknown setting' => [['kind' => 'vercel', 'project' => 'x'], 'not a known setting of a vercel target'];
        yield 'a setting of another kind' => [['kind' => 'vercel', 'image' => 'x'], 'image'];
        yield 'a docker setting on podman' => [['kind' => 'podman', 'host' => 'prod'], 'host'];
        yield 'a timeout of zero' => [['kind' => 'vercel', 'timeout' => 0], 'timeout'];
        yield 'a timeout too long' => [['kind' => 'vercel', 'timeout' => 99999], 'timeout'];
        yield 'a string timeout' => [['kind' => 'vercel', 'timeout' => '60'], 'timeout'];
        yield 'a folder that leaves the project' => [['kind' => 'vercel', 'dir' => '../other'], 'dir'];
        yield 'an absolute folder' => [['kind' => 'vercel', 'dir' => '/etc'], 'dir'];
        yield 'a folder that is an option' => [['kind' => 'vercel', 'dir' => '--help'], 'dir'];
        yield 'a secret that is not a variable name' => [['kind' => 'vercel', 'secrets' => ['token']], 'secrets'];
        yield 'secrets that are not a list' => [['kind' => 'vercel', 'secrets' => 'VERCEL_TOKEN'], 'secrets'];
        yield 'a variable that is not a name' => [['kind' => 'vercel', 'vars' => ['lower' => 'x']], 'vars'];
        yield 'a variable with a newline' => [['kind' => 'vercel', 'vars' => ['A_B' => "x\ny"]], 'vars'];
        yield 'vars as a list' => [['kind' => 'vercel', 'vars' => ['x']], 'vars'];
        yield 'a health url with a user' => [['kind' => 'vercel', 'health_url' => 'https://user:pw@x.example'], 'health_url'];
        yield 'a health url that is not http' => [['kind' => 'vercel', 'health_url' => 'file:///etc/passwd'], 'health_url'];
        yield 'a confirm that is not known' => [['kind' => 'vercel', 'confirm' => 'never'], 'confirm'];
        yield 'an environment with a space' => [['kind' => 'vercel', 'environment' => 'my env'], 'environment'];
        yield 'a vercel scope that is an option' => [['kind' => 'vercel', 'scope' => '--token=x'], 'scope'];
        yield 'a vercel command that is empty' => [['kind' => 'vercel', 'command' => []], 'command'];
        yield 'a vercel command that is a string' => [['kind' => 'vercel', 'command' => 'vercel'], 'command'];
        yield 'a vercel prebuilt that is a string' => [['kind' => 'vercel', 'prebuilt' => 'yes'], 'prebuilt'];
        yield 'a pages target without a project' => [['kind' => 'cloudflare', 'mode' => 'pages'], 'project_name'];
        yield 'a wrangler mode that is not known' => [['kind' => 'cloudflare', 'mode' => 'containers'], 'mode'];
        yield 'a wrangler environment that is an option' => [['kind' => 'cloudflare', 'wrangler_env' => '-x'], 'wrangler_env'];
        yield 'a modal target without an app' => [['kind' => 'modal'], 'app'];
        yield 'a modal app that is an option' => [['kind' => 'modal', 'app' => '--help'], 'app'];
        yield 'a modal strategy that is not known' => [['kind' => 'modal', 'app' => 'a.py', 'strategy' => 'big-bang'], 'strategy'];
        yield 'a docker port that is not a port' => [['kind' => 'docker', 'ports' => ['80;rm']], 'ports'];
        yield 'a docker image with capitals' => [['kind' => 'docker', 'image' => 'My/Image'], 'image'];
        yield 'a compose file that leaves the project' => [['kind' => 'docker', 'compose_file' => '../compose.yaml'], 'compose_file'];
        yield 'an ssh target without a host' => [['kind' => 'ssh', 'path' => '/srv'], 'host'];
        yield 'an ssh target without a path' => [['kind' => 'ssh', 'host' => 'prod'], 'path'];
        yield 'an ssh path that is relative' => [['kind' => 'ssh', 'host' => 'prod', 'path' => 'srv'], 'path'];
        yield 'an ssh sync that is not known' => [['kind' => 'ssh', 'host' => 'prod', 'path' => '/srv', 'sync' => 'rsync'], 'sync'];
        yield 'an ssh exclude that leaves the folder' => [['kind' => 'ssh', 'host' => 'prod', 'path' => '/srv', 'exclude' => ['../x']], 'exclude'];
        yield 'an ssh command for an action that does not exist' => [['kind' => 'ssh', 'host' => 'prod', 'path' => '/srv', 'commands' => ['deploy' => ['ls']]], 'not an action'];
        yield 'an ssh command that is empty' => [['kind' => 'ssh', 'host' => 'prod', 'path' => '/srv', 'commands' => ['apply' => ['']]], 'commands.apply'];
        yield 'a custom target without commands' => [['kind' => 'custom'], 'commands'];
        yield 'a custom command for a wrong action' => [['kind' => 'custom', 'commands' => ['ship' => [['echo']]]], 'not an action'];
        yield 'a custom command that is a string' => [['kind' => 'custom', 'commands' => ['apply' => ['echo hi']]], 'commands.apply[0]'];
    }

    #[DataProvider('invalid')]
    public function testABadTargetIsAnErrorThatNamesTheSetting(array $raw, string $word): void
    {
        try {
            DeployTarget::fromArray('web', $raw);
            self::fail('accepted: ' . json_encode($raw));
        } catch (ConfigException $error) {
            self::assertStringContainsString($word, $error->getMessage());
        }
    }

    public function testTheNameIsChecked(): void
    {
        foreach (['Web', '-web', 'we b', str_repeat('a', 33), ''] as $name) {
            try {
                DeployTarget::fromArray($name, ['kind' => 'vercel']);
                self::fail("accepted '{$name}'");
            } catch (ConfigException $error) {
                self::assertStringContainsString('name', $error->getMessage());
            }
        }
    }

    public function testTheConfigReadsTheTargetsOfTheGlobalFileOnlyAndChecksTheSshHost(): void
    {
        $base = ['profiles' => ['p' => ['base_url' => 'http://127.0.0.1:1', 'model' => 'm']]];
        $global = $base + [
            'ssh' => ['hosts' => ['prod' => ['destination' => 'deploy@h']]],
            'deploy' => ['targets' => [
                'web' => ['kind' => 'vercel'],
                'vps' => ['kind' => 'ssh', 'host' => 'prod', 'path' => '/srv/app'],
                'box' => ['kind' => 'docker', 'host' => 'prod'],
            ]],
        ];

        $config = Config::fromArrays($global, ['deploy' => ['targets' => ['evil' => ['kind' => 'vercel']]]], [], '/nonexistent');

        self::assertSame(['web', 'vps', 'box'], array_keys($config->deployTargets()));
        self::assertContains("project config key 'deploy' ignored: only the global config can set it", $config->warnings);

        $global['deploy']['targets']['vps']['host'] = 'unknown';
        try {
            Config::fromArrays($global, null, [], '/nonexistent');
            self::fail('a target with an unknown ssh host was accepted');
        } catch (ConfigException $error) {
            self::assertStringContainsString("deploy.targets.vps.host 'unknown' is not a host of the ssh section", $error->getMessage());
        }
        self::assertSame([], Config::fromArrays($base, null, [], '/nonexistent')->deployTargets());
    }

    public function testBadDeploySectionsNameTheSetting(): void
    {
        $base = ['profiles' => ['p' => ['base_url' => 'http://127.0.0.1:1', 'model' => 'm']]];
        foreach ([
            [['deploy' => ['servers' => []]], 'deploy.servers'],
            [['deploy' => ['targets' => ['web' => 'vercel']]], 'deploy.targets.web'],
            [['deploy' => ['targets' => ['web' => ['kind' => 'nope']]]], 'deploy.targets.web.kind'],
        ] as [$section, $label]) {
            try {
                Config::fromArrays($base + $section, null, [], '/nonexistent');
                self::fail('accepted ' . json_encode($section));
            } catch (ConfigException $error) {
                self::assertStringContainsString($label, $error->getMessage());
            }
        }
    }
}
