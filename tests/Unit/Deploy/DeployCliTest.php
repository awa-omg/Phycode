<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Deploy;

use Phycode\Deploy\DeployCli;
use Phycode\Support\Vault;
use Phycode\Tests\Support\FakeCli;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class DeployCliTest extends TestCase
{
    private string $config;
    private string $root;
    private ?FakeCli $cli = null;

    protected function setUp(): void
    {
        $this->config = Kit::folder('deploy-cli') . '/phycode/config.json';
        $this->root = realpath(Kit::folder('deploy-cli-project'));
        putenv('VERCEL_TOKEN');
    }

    protected function tearDown(): void
    {
        $this->cli?->end();
        putenv('VERCEL_TOKEN');
    }

    private function cli(array $args, string $input = '', bool $interactive = false, ?\Closure $secret = null): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $in = fopen('php://memory', 'w+');
        fwrite($in, $input);
        rewind($in);

        $code = DeployCli::run($args, $this->config, $this->root, $out, $err, $in, $interactive, $secret);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testWithoutTargetsListSaysHowToAddOne(): void
    {
        [$code, $out] = $this->cli([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('No deploy targets. Add one: phycode deploy add NAME KIND', $out);
    }

    public function testAddWritesTheTargetAndTellsWhatToDoNext(): void
    {
        [$code, $out] = $this->cli(['add', 'web', 'vercel', '--env', 'preview', '--secret', 'VERCEL_TOKEN', '--var', 'VERCEL_ORG_ID=team_1', '--health', 'https://web.example/up', '--timeout', '300', '--confirm', 'rules', '--set', 'prebuilt=true', '--set', 'scope=my-team']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Added deploy target web (vercel, preview).', $out);
        self::assertStringContainsString('phycode deploy secret web VERCEL_TOKEN', $out);
        self::assertStringContainsString('follow the permission mode and the rules', $out);
        self::assertStringContainsString('phycode deploy plan web', $out);
        $data = json_decode((string) file_get_contents($this->config), true)['deploy']['targets']['web'];
        self::assertSame([
            'kind' => 'vercel', 'environment' => 'preview', 'secrets' => ['VERCEL_TOKEN'], 'vars' => ['VERCEL_ORG_ID' => 'team_1'],
            'health_url' => 'https://web.example/up', 'timeout' => 300, 'confirm' => 'rules', 'command' => ['vercel'], 'scope' => 'my-team', 'prebuilt' => true,
        ], $data);
        self::assertSame(0600, fileperms($this->config) & 0777);
    }

    public function testAProductionTargetSaysThatItAlwaysAsks(): void
    {
        [$code, $out] = $this->cli(['add', 'web', 'vercel', '--confirm', 'rules']);

        self::assertSame(0, $code);
        self::assertStringContainsString('(a production target always does)', $out);
    }

    public function testSettingsOfTheKindAreGivenWithSetAsJsonOrWords(): void
    {
        self::assertSame(0, $this->cli(['add', 'box', 'docker', '--env', 'staging', '--set', 'image=acme/web', '--set', 'ports=["8080:80"]', '--set', 'compose=false'])[0]);

        $data = json_decode((string) file_get_contents($this->config), true)['deploy']['targets']['box'];
        self::assertSame('acme/web', $data['image']);
        self::assertSame(['8080:80'], $data['ports']);
        self::assertFalse($data['compose']);
    }

    public function testAddRefusesWhatIsNotValidAndChangesNothing(): void
    {
        self::assertSame(0, $this->cli(['add', 'web', 'vercel'])[0]);
        $before = (string) file_get_contents($this->config);

        foreach ([
            [['add', 'web', 'vercel'], 'already exists'],
            [['add', 'list', 'vercel'], 'name'],
            [['add', 'x', 'heroku'], 'kind'],
            [['add', 'x', 'vercel', '--dir', '../up'], 'dir'],
            [['add', 'x', 'vercel', '--secret', 'lowercase'], 'secrets'],
            [['add', 'x', 'vercel', '--timeout', 'soon'], 'timeout'],
            [['add', 'x', 'vercel', '--set', 'image=x'], 'image'],
            [['add', 'x', 'vercel', '--var', 'NOVALUE'], 'NAME=VALUE'],
            [['add', 'x', 'vercel', '--bogus', 'y'], 'unknown option --bogus'],
            [['add', 'x', 'vercel', '--env'], 'needs a value'],
            [['add', 'x'], 'a name and a kind'],
            [['add', 'x', 'ssh', '--set', 'host=nowhere', '--set', 'path=/srv'], "host 'nowhere' is not a host of the ssh section"],
        ] as [$args, $message]) {
            [$code, , $err] = $this->cli($args);

            self::assertSame(1, $code, implode(' ', $args));
            self::assertStringContainsString($message, $err, implode(' ', $args));
        }
        self::assertSame($before, (string) file_get_contents($this->config));
    }

    public function testRemoveTakesTheTargetAndItsStoredSecretsOut(): void
    {
        $this->cli(['add', 'web', 'vercel', '--secret', 'VERCEL_TOKEN']);
        $this->cli(['add', 'api', 'vercel']);
        $this->cli(['secret', 'web', 'VERCEL_TOKEN'], '', false, static fn (string $prompt): string => 'tok_12345678');
        $vault = new Vault(dirname($this->config));
        self::assertSame(['VERCEL_TOKEN'], $vault->deploySecretNames('web'));

        [$code, $out] = $this->cli(['remove', 'web']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Removed deploy target web and 1 stored secret.', $out);
        self::assertSame([], $vault->deploySecretNames('web'));
        self::assertSame(['api'], array_keys(json_decode((string) file_get_contents($this->config), true)['deploy']['targets']));
        self::assertSame(1, $this->cli(['remove', 'web'])[0]);
    }

    public function testASecretIsStoredEncryptedAndNeverPrinted(): void
    {
        $this->cli(['add', 'web', 'vercel', '--secret', 'VERCEL_TOKEN', '--secret', 'OTHER_ONE']);
        $prompts = [];

        [$code, $out] = $this->cli(['secret', 'web', 'VERCEL_TOKEN'], '', false, function (string $prompt) use (&$prompts): string {
            $prompts[] = $prompt;

            return 'tok_SECRET_value_1';
        });

        self::assertSame(0, $code);
        self::assertSame(['Value of VERCEL_TOKEN for web (typing is hidden): '], $prompts);
        self::assertStringContainsString('Stored VERCEL_TOKEN for web, encrypted in the vault.', $out);
        self::assertStringNotContainsString('tok_SECRET_value_1', $out);
        self::assertSame('tok_SECRET_value_1', (new Vault(dirname($this->config)))->deploySecret('web', 'VERCEL_TOKEN'));
        self::assertStringNotContainsString('tok_SECRET_value_1', (string) file_get_contents($this->config));
        self::assertStringNotContainsString('tok_SECRET_value_1', (string) file_get_contents(dirname($this->config) . '/keys.json'));

        [, , $err] = $this->cli(['secret', 'web', 'NOT_LISTED'], '', false, static fn (): string => 'x');
        self::assertStringContainsString('NOT_LISTED is not in the secrets of web: VERCEL_TOKEN, OTHER_ONE', $err);
        [, , $err] = $this->cli(['secret', 'nope', 'VERCEL_TOKEN'], '', false, static fn (): string => 'x');
        self::assertStringContainsString("no deploy target named 'nope'", $err);
    }

    public function testSecretsShowWhereEachValueComesFrom(): void
    {
        $this->cli(['add', 'web', 'vercel', '--secret', 'VERCEL_TOKEN', '--secret', 'FROM_ENV_ONE', '--secret', 'NOWHERE_AT_ALL']);
        $this->cli(['secret', 'web', 'VERCEL_TOKEN'], '', false, static fn (): string => 'abc');
        putenv('FROM_ENV_ONE=x');

        [$code, $out] = $this->cli(['secrets', 'web']);
        putenv('FROM_ENV_ONE');

        self::assertSame(0, $code);
        self::assertStringContainsString('VERCEL_TOKEN: stored in the vault', $out);
        self::assertStringContainsString('FROM_ENV_ONE: from the environment of phycode', $out);
        self::assertStringContainsString('NOWHERE_AT_ALL: missing (phycode deploy secret web NOWHERE_AT_ALL)', $out);

        [, $list] = $this->cli(['list']);
        self::assertMatchesRegularExpression('/^web\s+vercel\s+production\s+always\s+missing: FROM_ENV_ONE, NOWHERE_AT_ALL$/m', $list);
    }

    public function testPlanShowsTheCommandsWithoutRunningThem(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo never']);
        $this->cli(['add', 'web', 'vercel', '--secret', 'VERCEL_TOKEN', '--set', 'prebuilt=true']);

        [$code, $out] = $this->cli(['plan', 'web']);

        self::assertSame(0, $code);
        self::assertStringContainsString("web (vercel, production): apply\n1. vercel pull --yes --environment=production --non-interactive\n2. vercel build --prod --non-interactive\n3. vercel deploy --prebuilt --prod --yes --non-interactive", $out);
        self::assertStringContainsString('missing: VERCEL_TOKEN', $out);
        self::assertSame([], $this->cli->calls('vercel'));
        [, , $err] = $this->cli(['plan', 'web', 'explode']);
        self::assertStringContainsString("unknown action 'explode'", $err);
    }

    public function testAnActionShowsTheCommandsAsksAndRunsOnAYes(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo https://web-1.vercel.app'], ['VERCEL_TOKEN']);
        $this->cli(['add', 'web', 'vercel', '--secret', 'VERCEL_TOKEN']);
        $this->cli(['secret', 'web', 'VERCEL_TOKEN'], '', false, static fn (): string => 'tok_abcdef');

        [$code, $out] = $this->cli(['web', 'apply'], "n\n", true);
        self::assertSame(1, $code);
        self::assertStringContainsString('web (vercel, production): apply will run', $out);
        self::assertStringContainsString('Run it? [y/N]', $out);
        self::assertStringContainsString('Nothing was run.', $out);
        self::assertSame([], $this->cli->calls('vercel'));

        [$code, $out] = $this->cli(['web', 'apply'], "y\n", true);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('deploy web: apply ok', $out);
        self::assertStringContainsString('url: https://web-1.vercel.app', $out);
        self::assertSame(['tok_abcdef'], $this->cli->values('VERCEL_TOKEN'));
        self::assertStringNotContainsString('tok_abcdef', $out);
    }

    public function testAFailedApplyAsksWhetherToRollBackAndRollsBackOnAYes(): void
    {
        $this->cli = FakeCli::install(['one' => 'echo first', 'two' => 'exit 3', 'back' => 'echo rolled']);
        self::assertSame(0, $this->cli(['add', 'box', 'custom', '--env', 'staging', '--set', 'commands={"apply":[["one"],["two"]],"rollback":[["back"]]}'])[0]);

        [$code, $out] = $this->cli(['box', 'apply', '--yes'], "n\n", true);
        self::assertSame(1, $code);
        self::assertStringContainsString('Roll back box now? [y/N]', $out);
        self::assertSame([], $this->cli->calls('back'));

        [$code, $out] = $this->cli(['box', 'apply', '--yes'], "y\n", true);
        self::assertSame(1, $code, 'the apply still failed');
        self::assertStringContainsString('deploy box: rollback ok', $out);
        self::assertCount(1, $this->cli->calls('back'));

        [, $out] = $this->cli(['box', 'apply', '--yes'], '', false);
        self::assertStringNotContainsString('Roll back box now?', $out, 'without a terminal nobody is asked');
    }

    public function testAnActionWithoutATerminalNeedsYesAndReadingNeedsNoQuestion(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo listed'], ['VERCEL_TOKEN']);
        $this->cli(['add', 'web', 'vercel']);

        [$code, , $err] = $this->cli(['web', 'apply']);
        self::assertSame(1, $code);
        self::assertStringContainsString('run again with --yes', $err);
        self::assertSame([], $this->cli->calls('vercel'));

        self::assertSame(0, $this->cli(['web', 'apply', '--yes'])[0]);
        [$code, $out] = $this->cli(['web', 'status']);
        self::assertSame(0, $code);
        self::assertStringContainsString('listed', $out);
        self::assertStringNotContainsString('Run it?', $out);
    }

    public function testHistoryListsTheDeploysOfTheProject(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo https://web-1.vercel.app']);
        $this->cli(['add', 'web', 'vercel']);
        self::assertStringContainsString('No deploys in this project yet.', $this->cli(['history'])[1]);

        $this->cli(['web', 'apply', '--yes']);
        $this->cli(['web', 'rollback', '--yes', '--version', 'dpl_1']);

        [$code, $out] = $this->cli(['history', 'web']);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/web\s+rollback\s+ok/', $out);
        self::assertMatchesRegularExpression('/web\s+apply\s+ok.*https:\/\/web-1\.vercel\.app/', $out);
    }

    public function testRunningAnActionOfAnUnknownTargetOrWithABadOptionIsAnError(): void
    {
        $this->cli(['add', 'web', 'vercel']);

        foreach ([
            [['nope', 'apply', '--yes'], "no deploy target named 'nope'"],
            [['web'], 'give an action after web'],
            [['web', 'apply', '--force'], 'unknown option --force'],
            [['web', 'logs', '--lines', 'many'], '--lines takes a number'],
            [['web', 'rollback', '--version'], '--version needs a value'],
            [['web', 'rollback', '--version', '--token=x', '--yes'], 'version'],
        ] as [$args, $message]) {
            [$code, , $err] = $this->cli($args);

            self::assertSame(1, $code, implode(' ', $args));
            self::assertStringContainsString($message, $err, implode(' ', $args));
        }
    }

    public function testThePhycodeCommandPassesItsOwnOptionsToDeploy(): void
    {
        $home = Kit::folder('deploy-bin');
        $env = ['PATH' => (string) getenv('PATH'), 'HOME' => $home, 'XDG_CONFIG_HOME' => $home . '/cfg'];
        $run = static function (array $args) use ($env): array {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/bin/phycode', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);

            return [proc_close($process), (string) $out, (string) $err];
        };

        [$code, $out] = $run(['deploy', 'add', 'web', 'vercel', '--env', 'preview', '--secret', 'VERCEL_TOKEN']);
        self::assertSame(0, $code, $out);
        [$code, $out] = $run(['deploy', 'list']);
        self::assertSame(0, $code);
        self::assertStringContainsString('web', $out);
        self::assertStringContainsString('preview', $out);
        [$code, $out] = $run(['deploy', 'plan', 'web']);
        self::assertSame(0, $code);
        self::assertStringContainsString('vercel deploy --yes --non-interactive', $out);
        [$code, $out, $err] = $run(['deploy', 'web', 'apply', '--cwd', $this->root]);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('run again with --yes', $err);
    }
}
