<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Deploy;

use Phycode\Deploy\DeployTarget;
use Phycode\Deploy\DeployUnsupported;
use Phycode\Deploy\Deployments;
use Phycode\Exec\ProcessRunner;
use Phycode\Ssh\SshHosts;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;
use Phycode\Tests\Support\FakeCli;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class DeploymentsTest extends TestCase
{
    private const TOKEN = 'tok_SECRET_0123456789';

    private string $root;
    private Vault $vault;
    private ?FakeCli $cli = null;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('deploy-project'));
        $this->vault = new Vault(Kit::folder('deploy-vault'));
        putenv('VERCEL_TOKEN');
        putenv('MODAL_TOKEN_ID');
    }

    protected function tearDown(): void
    {
        $this->cli?->end();
        putenv('VERCEL_TOKEN');
        putenv('MODAL_TOKEN_ID');
    }

    private function deployments(array $targets, ?\Closure $probe = null, int $attempts = 2): Deployments
    {
        $built = [];
        foreach ($targets as $name => $raw) {
            $built[$name] = DeployTarget::fromArray($name, $raw);
        }

        return new Deployments($built, $this->vault, new ProcessRunner(), new SshHosts([], new ProcessRunner()), $this->root, $probe, $attempts, 0);
    }

    private function execute(Deployments $deployments, string $target, string $action, ?string $version = null, ?Cancellation $cancel = null): \Phycode\Deploy\DeployResult
    {
        return $deployments->run($target, $action, $version, 100, $cancel ?? new Cancellation(), 8192);
    }

    public function testTheValuesOfAllSecretsAreKnownSoThatAToolResultCanBeCleaned(): void
    {
        $this->vault->storeDeploySecret('web', 'VERCEL_TOKEN', self::TOKEN);
        $deployments = $this->deployments(['web' => ['kind' => 'vercel', 'secrets' => ['VERCEL_TOKEN', 'NOT_SET_ANYWHERE']]]);

        self::assertSame([self::TOKEN], $deployments->secretValues());
        self::assertSame([], $this->deployments([])->secretValues());
    }

    public function testApplyRunsTheCommandWithTheSecretInTheEnvironmentAndFindsTheUrl(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo "https://web-abc123.vercel.app"; echo "Inspect: https://vercel.com/x/y" >&2'], ['VERCEL_TOKEN', 'VERCEL_ORG_ID']);
        $this->vault->storeDeploySecret('web', 'VERCEL_TOKEN', self::TOKEN);
        $deployments = $this->deployments(['web' => ['kind' => 'vercel', 'secrets' => ['VERCEL_TOKEN'], 'vars' => ['VERCEL_ORG_ID' => 'team_1']]]);

        $result = $this->execute($deployments, 'web', 'apply');

        self::assertTrue($result->ok, $result->report(true));
        self::assertSame('https://web-abc123.vercel.app', $result->url);
        self::assertSame([['deploy', '--prod', '--yes', '--non-interactive']], $this->cli->calls('vercel'));
        self::assertSame([self::TOKEN], $this->cli->values('VERCEL_TOKEN'), 'the secret reaches the command through its environment');
        self::assertSame(['team_1'], $this->cli->values('VERCEL_ORG_ID'));
        self::assertSame([$this->root], $this->cli->directories());
        self::assertStringNotContainsString(self::TOKEN, $result->report(true));
    }

    public function testASecretIsTakenFromTheEnvironmentWhenTheVaultHasNone_AndTheVaultWinsWhenBothHaveOne(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo https://x.vercel.app'], ['VERCEL_TOKEN']);
        $deployments = $this->deployments(['web' => ['kind' => 'vercel', 'secrets' => ['VERCEL_TOKEN']]]);

        putenv('VERCEL_TOKEN=from-env');
        self::assertTrue($this->execute($deployments, 'web', 'status')->ok);
        $this->vault->storeDeploySecret('web', 'VERCEL_TOKEN', 'from-vault');
        self::assertTrue($this->execute($deployments, 'web', 'status')->ok);

        self::assertSame(['from-env', 'from-vault'], $this->cli->values('VERCEL_TOKEN'));
    }

    public function testAMissingSecretStopsBeforeAnythingRunsAndSaysHowToStoreIt(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo never'], ['VERCEL_TOKEN']);
        $deployments = $this->deployments(['web' => ['kind' => 'vercel', 'secrets' => ['VERCEL_TOKEN', 'OTHER_ONE']]]);

        $result = $this->execute($deployments, 'web', 'apply');

        self::assertFalse($result->ok);
        self::assertSame(['VERCEL_TOKEN', 'OTHER_ONE'], $deployments->missingSecrets('web'));
        self::assertStringContainsString('missing: VERCEL_TOKEN, OTHER_ONE', (string) $result->error);
        self::assertStringContainsString('phycode deploy secret web NAME', (string) $result->error);
        self::assertSame([], $this->cli->calls('vercel'));
        self::assertSame([], $deployments->history(), 'a deploy that did not start is not in the history');
    }

    public function testTheSecretIsTakenOutOfWhatTheCommandPrints(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo "token is $VERCEL_TOKEN"; echo "also $VERCEL_TOKEN" >&2; exit 1']);
        $this->vault->storeDeploySecret('web', 'VERCEL_TOKEN', self::TOKEN);
        $deployments = $this->deployments(['web' => ['kind' => 'vercel', 'secrets' => ['VERCEL_TOKEN']]]);

        $result = $this->execute($deployments, 'web', 'apply');

        self::assertFalse($result->ok);
        $report = $result->report(false);
        self::assertStringContainsString('token is [redacted]', $report);
        self::assertStringContainsString('also [redacted]', $report);
        self::assertStringNotContainsString(self::TOKEN, $report . json_encode($result->steps) . (string) file_get_contents($this->root . '/.phycode/deploys.jsonl'));
    }

    public function testAUrlThatPrintsASecretComesOutRedactedInTheResultAndTheHistory(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo "https://preview.example/$VERCEL_TOKEN"']);
        $this->vault->storeDeploySecret('web', 'VERCEL_TOKEN', self::TOKEN);
        $deployments = $this->deployments(['web' => ['kind' => 'vercel', 'secrets' => ['VERCEL_TOKEN']]]);

        $result = $this->execute($deployments, 'web', 'apply');

        self::assertTrue($result->ok, $result->report(true));
        self::assertSame('https://preview.example/[redacted]', $result->url);
        self::assertSame('https://preview.example/[redacted]', $deployments->history('web', 1)[0]['url']);
        self::assertStringNotContainsString(self::TOKEN, $result->report(true) . (string) file_get_contents($this->root . '/.phycode/deploys.jsonl'));
    }

    public function testAStepThatFailsStopsTheOthersAndAnOptionalOneDoesNot(): void
    {
        $this->cli = FakeCli::install(['one' => 'echo first', 'two' => 'echo broke >&2; exit 3', 'three' => 'echo third']);
        $deployments = $this->deployments(['box' => ['kind' => 'custom', 'commands' => ['apply' => [['one'], ['two'], ['three']]]]]);

        $result = $this->execute($deployments, 'box', 'apply');

        self::assertFalse($result->ok);
        self::assertSame(['ok', 'failed', 'skipped'], array_column($result->steps, 'status'));
        self::assertSame([], $this->cli->calls('three'));
        self::assertStringContainsString("step 'apply 2' failed (exit code 3)", (string) $result->error);
        self::assertStringContainsString('broke', $result->report(false), 'the output of the step that failed is shown');
        self::assertStringStartsWith('deploy box: apply FAILED', $result->report(false));
    }

    public function testAStepOnTheHardLineStopsTheWholeDeployBeforeAnyStepRuns(): void
    {
        $this->cli = FakeCli::install(['one' => 'echo first']);
        $deployments = $this->deployments(['box' => ['kind' => 'custom', 'commands' => ['apply' => [['one'], ['sh', '-c', 'rm -rf /home/neighbour']]]]]);

        $result = $this->execute($deployments, 'box', 'apply');

        self::assertFalse($result->ok);
        self::assertStringContainsString("refused: rm -r of the root, a home folder, a system folder or a mount point is never run, in any mode and with any approval. Step 'apply 2' was not run", (string) $result->error);
        self::assertSame([], $this->cli->calls('one'));
    }

    public function testAnOptionalStepThatFailsDoesNotStopTheDeploy(): void
    {
        $this->cli = FakeCli::install(['docker' => 'case "$1" in tag) echo "no such image" >&2; exit 1;; rm) exit 1;; esac; echo "done $1"'], []);
        Kit::write($this->root . '/Dockerfile', "FROM scratch\n");
        $deployments = $this->deployments(['web' => ['kind' => 'docker', 'image' => 'acme/web']]);

        $result = $this->execute($deployments, 'web', 'apply');

        self::assertTrue($result->ok, $result->report(true));
        self::assertSame(['optional-failed', 'ok', 'optional-failed', 'ok'], array_column($result->steps, 'status'));
        self::assertSame(['tag', 'build', 'rm', 'run'], array_column($this->cli->calls('docker'), 0));
    }

    public function testAProgramThatIsNotInstalledIsReportedAndNothingRuns(): void
    {
        $this->cli = FakeCli::install(['other' => 'true']);
        $deployments = $this->deployments(['edge' => ['kind' => 'cloudflare', 'command' => ['phycode-no-such-cli']]]);

        $result = $this->execute($deployments, 'edge', 'apply');

        self::assertFalse($result->ok);
        self::assertStringContainsString('phycode-no-such-cli was not found on PATH, so nothing was run', (string) $result->error);
    }

    public function testTheHealthCheckDecidesWhetherAnAppliedDeployIsGood(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo https://x.vercel.app']);
        $asked = [];
        $good = $this->deployments(['web' => ['kind' => 'vercel', 'health_url' => 'https://x.example/health']], function (string $url) use (&$asked): array {
            $asked[] = $url;

            return [count($asked) < 2 ? 503 : 200, null];
        }, 3);

        $result = $this->execute($good, 'web', 'apply');

        self::assertTrue($result->ok);
        self::assertSame('ok: HTTP 200 from https://x.example/health', $result->health);
        self::assertCount(2, $asked, 'it asks again after a 503');

        $bad = $this->deployments(['web' => ['kind' => 'vercel', 'health_url' => 'https://x.example/health']], static fn (string $url): array => [null, 'connection refused'], 2);
        $result = $this->execute($bad, 'web', 'apply');
        self::assertFalse($result->ok);
        self::assertSame('the deploy ran but the health check failed', $result->error);
        self::assertSame('connection refused from https://x.example/health after 2 tries', $result->health);

        $status = $this->execute($bad, 'web', 'status');
        self::assertTrue($status->ok, 'only apply is checked');
        self::assertNull($status->health);
    }

    public function testAStepEndsAtTheTimeLimitOfTheTarget(): void
    {
        $this->cli = FakeCli::install(['slow' => 'sleep 37']);
        $deployments = $this->deployments(['box' => ['kind' => 'custom', 'timeout' => 1, 'commands' => ['apply' => [['slow']]]]]);

        $started = microtime(true);
        $result = $this->execute($deployments, 'box', 'apply');

        self::assertFalse($result->ok);
        self::assertStringContainsString('(time limit)', (string) $result->error);
        self::assertLessThan(15, microtime(true) - $started);
        self::assertFalse(Kit::sleeping('37'));
    }

    public function testALogTailEndsByItsLimitAndThatIsNotAFailure(): void
    {
        $this->cli = FakeCli::install(['wrangler' => 'echo "GET / 200"; echo "GET /x 404"; sleep 38']);
        $deployments = $this->deployments(['edge' => ['kind' => 'cloudflare', 'timeout' => 1, 'name' => 'api']]);

        $result = $this->execute($deployments, 'edge', 'logs');

        self::assertTrue($result->ok, $result->report(true));
        self::assertStringContainsString('GET /x 404', $result->report(true));
        self::assertSame([['tail', 'api', '--format', 'pretty']], $this->cli->calls('wrangler'));
        self::assertSame([], $deployments->history(), 'reading logs is not a deploy');
    }

    public function testCancellationSkipsTheStepsThatHaveNotStarted(): void
    {
        $this->cli = FakeCli::install(['one' => 'echo a', 'two' => 'echo b']);
        $deployments = $this->deployments(['box' => ['kind' => 'custom', 'commands' => ['apply' => [['one'], ['two']]]]]);
        $cancel = new Cancellation();
        $cancel->cancel('stopped by the person');

        $result = $this->execute($deployments, 'box', 'apply', null, $cancel);

        self::assertFalse($result->ok);
        self::assertSame(['skipped', 'skipped'], array_column($result->steps, 'status'));
        self::assertSame([], $this->cli->calls('one'));
        self::assertStringContainsString('cancelled', (string) $result->error);
    }

    public function testTheVersionOfARollbackIsPassedAndChecked(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo rolled back'], []);
        $deployments = $this->deployments(['web' => ['kind' => 'vercel']]);

        self::assertTrue($this->execute($deployments, 'web', 'rollback', 'dpl_abc123')->ok);
        self::assertTrue($this->execute($deployments, 'web', 'rollback', 'https://web-abc.vercel.app')->ok);
        self::assertSame(['rollback', 'dpl_abc123', '--non-interactive'], $this->cli->calls('vercel')[0]);

        foreach (['--token=x', '-x', 'a b', 'a;b', '$(id)', "a\nb", ''] as $version) {
            try {
                $deployments->plan('web', 'rollback', $version);
                self::fail("accepted version '{$version}'");
            } catch (PhycodeException $error) {
                self::assertStringContainsString('version', $error->getMessage());
            }
        }
    }

    public function testActionsAndTargetsAreChecked(): void
    {
        $deployments = $this->deployments(['web' => ['kind' => 'vercel']]);

        foreach ([['nope', 'apply', "no deploy target named 'nope'"], ['web', 'destroy', "unknown action 'destroy'"]] as [$target, $action, $message]) {
            try {
                $deployments->plan($target, $action);
                self::fail("accepted {$target} {$action}");
            } catch (PhycodeException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        self::assertSame(['web'], $deployments->names());
        self::assertTrue($deployments->has('web'));
        self::assertFalse($deployments->has('nope'));
    }

    public function testThePlanShowsTheStepsOfApplyAndRunsNothing(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo never']);
        $deployments = $this->deployments(['web' => ['kind' => 'vercel', 'secrets' => ['VERCEL_TOKEN']]]);

        $plan = $deployments->plan('web', 'plan');

        self::assertSame('plan', $plan->action);
        self::assertSame(['vercel deploy --prod --yes --non-interactive'], $plan->commands());
        self::assertSame([], $this->cli->calls('vercel'));
    }

    public function testAFolderThatLeavesTheProjectThroughALinkIsRefused(): void
    {
        $outside = Kit::folder('deploy-outside');
        symlink($outside, $this->root . '/escape');
        $deployments = $this->deployments(['web' => ['kind' => 'custom', 'dir' => 'escape', 'commands' => ['status' => [['true']]]]]);

        $this->expectException(DeployUnsupported::class);
        $this->expectExceptionMessage('does not exist inside the project');
        $deployments->plan('web', 'status');
    }

    public function testAFolderInsideTheProjectIsTheWorkingDirectory(): void
    {
        $this->cli = FakeCli::install(['build' => 'echo ok']);
        mkdir($this->root . '/apps/site', 0777, true);
        $deployments = $this->deployments(['site' => ['kind' => 'custom', 'dir' => 'apps/site', 'commands' => ['apply' => [['build']]]]]);

        self::assertTrue($this->execute($deployments, 'site', 'apply')->ok);
        self::assertSame([$this->root . '/apps/site'], $this->cli->directories());
    }

    public function testEveryDeployIsRememberedWithoutASecretAndTheNewestComesFirst(): void
    {
        $this->cli = FakeCli::install(['vercel' => 'echo https://x.vercel.app']);
        $this->vault->storeDeploySecret('web', 'VERCEL_TOKEN', self::TOKEN);
        $deployments = $this->deployments(['web' => ['kind' => 'vercel', 'secrets' => ['VERCEL_TOKEN']], 'api' => ['kind' => 'custom', 'commands' => ['apply' => [['false']]]]]);

        $this->execute($deployments, 'web', 'dry_run');
        $this->execute($deployments, 'web', 'apply');
        $this->execute($deployments, 'api', 'apply');
        $this->execute($deployments, 'web', 'status');

        $all = $deployments->history();
        self::assertSame(['api', 'web', 'web'], array_column($all, 'target'));
        self::assertSame(['apply', 'apply', 'dry_run'], array_column($all, 'action'));
        self::assertSame([false, true, true], array_column($all, 'ok'));
        self::assertSame('https://x.vercel.app', $deployments->history('web', 1)[0]['url']);
        self::assertSame(['vercel deploy --prod --yes --non-interactive'], $deployments->history('web', 1)[0]['commands']);
        $file = $this->root . '/.phycode/deploys.jsonl';
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertStringNotContainsString(self::TOKEN, (string) file_get_contents($file));
        self::assertSame("*\n", file_get_contents($this->root . '/.phycode/.gitignore'));
    }

    public function testTheTagOfAnImageIsTheShortCommit(): void
    {
        exec('cd ' . escapeshellarg($this->root) . ' && git init -q && git -c user.email=a@b -c user.name=t commit -q --allow-empty -m x 2>&1');
        $sha = trim((string) shell_exec('cd ' . escapeshellarg($this->root) . ' && git rev-parse --short HEAD'));
        Kit::write($this->root . '/Dockerfile', "FROM scratch\n");
        $deployments = $this->deployments(['web' => ['kind' => 'docker', 'image' => 'acme/web']]);

        self::assertContains("docker build -t acme/web:latest -t acme/web:{$sha} .", $deployments->plan('web', 'apply')->commands());
    }

    public function testTheSummaryNamesTheTargetTheEnvironmentAndTheCommand(): void
    {
        $deployments = $this->deployments(['web' => ['kind' => 'vercel'], 'stage' => ['kind' => 'vercel', 'environment' => 'preview', 'prebuilt' => true]]);

        self::assertSame('deploy web: apply [production] vercel deploy --prod --yes --non-interactive', $deployments->summary('web', 'apply'));
        self::assertSame('deploy stage: apply [preview] vercel deploy --prebuilt --yes --non-interactive (3 steps)', $deployments->summary('stage', 'apply'));
        self::assertSame('deploy web: rollback [production] vercel rollback dpl_9 --non-interactive', $deployments->summary('web', 'rollback', 'dpl_9'));
        self::assertSame('deploy nope: apply', $deployments->summary('nope', 'apply'));
    }

    public function testVaultKeepsTheSecretsOfATargetApartFromTheKeysOfProfiles(): void
    {
        $this->vault->store('cloud', 'sk-profile-key-123456');
        $this->vault->storeDeploySecret('web', 'VERCEL_TOKEN', 'a');
        $this->vault->storeDeploySecret('web', 'OTHER_VAR', 'b');
        $this->vault->storeDeploySecret('api', 'VERCEL_TOKEN', 'c');

        self::assertSame(['cloud'], $this->vault->profiles());
        self::assertSame(['OTHER_VAR', 'VERCEL_TOKEN'], $this->vault->deploySecretNames('web'));
        self::assertSame('c', $this->vault->deploySecret('api', 'VERCEL_TOKEN'));
        self::assertNull($this->vault->deploySecret('api', 'OTHER_VAR'));
        self::assertTrue($this->vault->removeDeploySecret('web', 'OTHER_VAR'));
        self::assertFalse($this->vault->removeDeploySecret('web', 'OTHER_VAR'));
        self::assertSame(['VERCEL_TOKEN'], $this->vault->deploySecretNames('web'));
        self::assertSame('sk-profile-key-123456', $this->vault->get('cloud'));
        $this->expectException(PhycodeException::class);
        $this->vault->storeDeploySecret('web', 'lowercase', 'x');
    }
}
