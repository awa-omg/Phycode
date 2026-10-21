<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Deploy;

use Phycode\Deploy\DeployContext;
use Phycode\Deploy\DeployTarget;
use Phycode\Deploy\DeployUnsupported;
use Phycode\Deploy\Drivers\Drivers;
use Phycode\Exec\ProcessRunner;
use Phycode\Ssh\SshHost;
use Phycode\Ssh\SshHosts;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class DriverPlansTest extends TestCase
{
    private function context(array $files = [], ?string $version = null, array $missing = [], int $lines = 100): DeployContext
    {
        $folder = realpath(Kit::folder('plans'));
        foreach ($files as $file) {
            Kit::write("{$folder}/{$file}", "x\n");
        }
        $ssh = new SshHosts(['prod' => SshHost::fromArray('prod', ['destination' => 'deploy@203.0.113.9', 'port' => 2200])], new ProcessRunner());

        return new DeployContext($folder, $folder, 'tok123', 'abc1234', static fn (string $program): ?string => in_array($program, $missing, true) ? null : "/usr/bin/{$program}", $ssh, $version, $lines);
    }

    private function commands(string $kind, array $raw, string $action, ?DeployContext $context = null): array
    {
        $target = DeployTarget::fromArray('web', ['kind' => $kind] + $raw);

        return Drivers::for($kind)->plan($target, $action, $context ?? $this->context(['Dockerfile']))->commands();
    }

    public function testVercelPlansTheCommandsOfItsCli(): void
    {
        $production = ['environment' => 'production'];

        self::assertSame(['vercel deploy --prod --yes --non-interactive'], $this->commands('vercel', $production, 'apply'));
        self::assertSame(['vercel deploy --yes --non-interactive'], $this->commands('vercel', ['environment' => 'preview'], 'apply'));
        self::assertSame(['vercel deploy --target=staging --yes --non-interactive'], $this->commands('vercel', ['environment' => 'staging'], 'apply'));
        self::assertSame(['vercel deploy --prod --yes --non-interactive --scope my-team'], $this->commands('vercel', ['scope' => 'my-team'], 'apply'));
        self::assertSame([
            'vercel pull --yes --environment=production --non-interactive',
            'vercel build --prod --non-interactive',
            'vercel deploy --prebuilt --prod --yes --non-interactive',
        ], $this->commands('vercel', ['prebuilt' => true], 'apply'));
        self::assertSame(['vercel pull --yes --environment=production --non-interactive', 'vercel build --prod --non-interactive'], $this->commands('vercel', [], 'dry_run'));
        self::assertSame(['vercel list --non-interactive'], $this->commands('vercel', [], 'status'));
        self::assertSame(['vercel logs --since 30m --limit 100 --environment production --non-interactive'], $this->commands('vercel', [], 'logs'));
        self::assertSame(['vercel logs --since 30m --limit 20 --environment preview --non-interactive'], $this->commands('vercel', ['environment' => 'preview'], 'logs', $this->context(lines: 20)));
        self::assertSame(['vercel rollback --non-interactive'], $this->commands('vercel', [], 'rollback'));
        self::assertSame(['vercel rollback dpl_abc --non-interactive'], $this->commands('vercel', [], 'rollback', $this->context(version: 'dpl_abc')));
        self::assertSame(['npx vercel@41 deploy --prod --yes --non-interactive'], $this->commands('vercel', ['command' => ['npx', 'vercel@41']], 'apply'));
    }

    public function testVercelRollbackActsOnProductionOnly(): void
    {
        $this->expectException(DeployUnsupported::class);
        $this->expectExceptionMessage('production only');
        $this->commands('vercel', ['environment' => 'preview'], 'rollback');
    }

    public function testCloudflareWorkersAndPages(): void
    {
        self::assertSame(['wrangler deploy'], $this->commands('cloudflare', [], 'apply'));
        self::assertSame(['wrangler deploy --env staging --name api'], $this->commands('cloudflare', ['wrangler_env' => 'staging', 'name' => 'api'], 'apply'));
        self::assertSame(['wrangler deploy --dry-run'], $this->commands('cloudflare', [], 'dry_run'));
        self::assertSame(['wrangler deployments status'], $this->commands('cloudflare', [], 'status'));
        self::assertSame(['wrangler tail api --format pretty'], $this->commands('cloudflare', ['name' => 'api'], 'logs'));
        self::assertSame(['wrangler rollback --message \'rollback by phycode\''], $this->commands('cloudflare', [], 'rollback'));
        self::assertSame(['wrangler rollback v1 --message \'rollback by phycode\' --name api'], $this->commands('cloudflare', ['name' => 'api'], 'rollback', $this->context(version: 'v1')));

        $pages = ['mode' => 'pages', 'project_name' => 'site', 'output_dir' => 'dist', 'branch' => 'main'];
        self::assertSame(['wrangler pages deploy dist --project-name site --branch main'], $this->commands('cloudflare', $pages, 'apply'));
        self::assertSame(['wrangler pages deployment list --project-name site --environment production --json'], $this->commands('cloudflare', $pages, 'status'));
        self::assertSame(['wrangler pages deployment tail --project-name site --environment preview --format pretty'], $this->commands('cloudflare', $pages + ['environment' => 'preview'], 'logs'));
    }

    public function testCloudflareWorkerStatusAndLogsReadTheEnvironmentFromTheVariableWranglerDocuments(): void
    {
        $target = DeployTarget::fromArray('edge', ['kind' => 'cloudflare', 'wrangler_env' => 'staging']);
        $plan = Drivers::for('cloudflare')->plan($target, 'status', $this->context());

        self::assertSame(['CLOUDFLARE_ENV' => 'staging'], $plan->steps[0]->env);
        $logs = Drivers::for('cloudflare')->plan($target, 'logs', $this->context());
        self::assertTrue($logs->steps[0]->bounded, 'a log tail ends by its time limit');
        self::assertSame(20, $logs->steps[0]->timeout);
    }

    public function testCloudflareThingsItCannotDo(): void
    {
        foreach ([['pages', 'dry_run'], ['pages', 'rollback']] as [$mode, $action]) {
            try {
                $this->commands('cloudflare', ['mode' => 'pages', 'project_name' => 's', 'output_dir' => 'd'], $action);
                self::fail("pages did {$action}");
            } catch (DeployUnsupported $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testModalPlansTheCommandsOfItsCli(): void
    {
        $modal = ['app' => 'app.py', 'name' => 'my-app', 'modal_env' => 'main', 'tag' => 'v7', 'strategy' => 'rolling'];

        self::assertSame(['modal deploy app.py'], $this->commands('modal', ['app' => 'app.py'], 'apply'));
        self::assertSame(['modal deploy --name my-app -e main --tag v7 --strategy rolling -m pkg.mod'], $this->commands('modal', ['module' => true, 'app' => 'pkg.mod'] + $modal, 'apply'));
        self::assertSame(['modal app list -e main --json'], $this->commands('modal', $modal, 'status'));
        self::assertSame(['modal app logs my-app --tail 100 -e main'], $this->commands('modal', $modal, 'logs'));
        self::assertSame(['modal app rollback my-app --strategy rolling -e main'], $this->commands('modal', $modal, 'rollback'));
        self::assertSame(['modal app rollback my-app v3 --strategy rolling -e main'], $this->commands('modal', $modal, 'rollback', $this->context(version: 'v3')));
    }

    public function testModalLogsAndRollbackNeedTheNameOfTheApp(): void
    {
        foreach (['logs', 'rollback'] as $action) {
            try {
                $this->commands('modal', ['app' => 'app.py'], $action);
                self::fail("modal {$action} without a name");
            } catch (DeployUnsupported $error) {
                self::assertStringContainsString('set name', $error->getMessage());
            }
        }
        $this->expectException(DeployUnsupported::class);
        $this->commands('modal', ['app' => 'app.py'], 'dry_run');
    }

    public function testDockerPlainBuildsTagsAndStartsOneContainer(): void
    {
        $docker = ['image' => 'acme/web', 'container' => 'web-1', 'ports' => ['8080:80', '127.0.0.1:9000:9000/tcp']];

        self::assertSame([
            'docker tag acme/web:latest acme/web:previous',
            'docker build -t acme/web:latest -t acme/web:abc1234 .',
            'docker rm -f web-1',
            'docker run -d --name web-1 --restart unless-stopped -p 8080:80 -p 127.0.0.1:9000:9000/tcp acme/web:latest',
        ], $this->commands('docker', $docker, 'apply'));
        self::assertSame(['docker build -t acme/web:abc1234 .'], $this->commands('docker', $docker, 'dry_run'));
        self::assertSame([
            'docker tag acme/web:previous acme/web:latest',
            'docker rm -f web-1',
            'docker run -d --name web-1 --restart unless-stopped -p 8080:80 -p 127.0.0.1:9000:9000/tcp acme/web:latest',
        ], $this->commands('docker', $docker, 'rollback'));
        self::assertSame(['docker logs --tail 100 web-1'], $this->commands('docker', $docker, 'logs'));
        self::assertStringStartsWith('docker ps -a --filter', $this->commands('docker', $docker, 'status')[0]);
        self::assertStringContainsString('build -t phycode-web:latest', $this->commands('docker', [], 'apply')[1], 'the image is named after the target');
        self::assertStringContainsString('--name web ', $this->commands('docker', [], 'apply')[3], 'and so is the container');
        self::assertStringStartsWith('podman build', $this->commands('podman', [], 'apply')[1]);
    }

    public function testDockerMarksTheTagAndTheRemovalAsStepsThatMayFail(): void
    {
        $target = DeployTarget::fromArray('web', ['kind' => 'docker']);
        $steps = Drivers::for('docker')->plan($target, 'apply', $this->context(['Dockerfile']))->steps;

        self::assertTrue($steps[0]->optional, 'there is no :latest to keep the first time');
        self::assertFalse($steps[1]->optional);
        self::assertTrue($steps[2]->optional, 'there is no container to remove the first time');
        self::assertFalse($steps[3]->optional);
    }

    public function testDockerUsesComposeWhenTheFolderHasAComposeFile(): void
    {
        $context = $this->context(['compose.yaml']);

        self::assertSame(['docker compose -f compose.yaml -p web up -d --build --wait --remove-orphans'], $this->commands('docker', [], 'apply', $context));
        self::assertSame(['docker compose -f compose.yaml -p web build'], $this->commands('docker', [], 'dry_run', $context));
        self::assertSame(['docker compose -f compose.yaml -p web ps'], $this->commands('docker', [], 'status', $context));
        self::assertSame(['docker compose -f compose.yaml -p web logs --tail 100 --no-color'], $this->commands('docker', [], 'logs', $context));
        self::assertSame(['docker compose -p web up -d --build --wait --remove-orphans'], $this->commands('docker', ['compose' => true], 'apply', $this->context(['Dockerfile'])));
        self::assertSame(['docker compose -f deploy/c.yml -p web ps'], $this->commands('docker', ['compose_file' => 'deploy/c.yml'], 'status', $context));
        self::assertStringContainsString('build -t', $this->commands('docker', ['compose' => false], 'apply', $this->context(['compose.yaml', 'Dockerfile']))[1], 'compose: false forces the plain mode');
        try {
            $this->commands('docker', [], 'rollback', $context);
            self::fail('compose has no rollback');
        } catch (DeployUnsupported $error) {
            self::assertStringContainsString('revert the code', $error->getMessage());
        }
    }

    public function testDockerNeedsADockerfileOrAComposeFileToApply(): void
    {
        $this->expectException(DeployUnsupported::class);
        $this->expectExceptionMessage('no Dockerfile and no compose file');
        $this->commands('docker', [], 'apply', $this->context());
    }

    public function testDockerOnAnSshHostSetsDockerHostForEveryStep(): void
    {
        $target = DeployTarget::fromArray('web', ['kind' => 'docker', 'host' => 'prod']);
        $plan = Drivers::for('docker')->plan($target, 'apply', $this->context(['Dockerfile']));

        foreach ($plan->steps as $step) {
            self::assertSame(['DOCKER_HOST' => 'ssh://deploy@203.0.113.9:2200'], $step->env);
        }
        self::assertStringContainsString('the one of prod, reached over ssh', implode(' ', $plan->notes));

        $this->expectException(DeployUnsupported::class);
        $this->expectExceptionMessage("host 'other' is not in the ssh section");
        Drivers::for('docker')->plan(DeployTarget::fromArray('web', ['kind' => 'docker', 'host' => 'other']), 'apply', $this->context(['Dockerfile']));
    }

    public function testSshPacksSendsUnpacksAndRunsTheCommandsOfTheTarget(): void
    {
        $raw = ['host' => 'prod', 'path' => '/srv/app', 'exclude' => ['*.log'], 'commands' => ['apply' => ['npm ci', 'systemctl restart app'], 'status' => ['systemctl status app'], 'logs' => ['journalctl -u app -n {lines}'], 'rollback' => ['./rollback.sh {version}']]];
        $target = DeployTarget::fromArray('vps', ['kind' => 'ssh'] + $raw);
        $context = $this->context();
        $plan = Drivers::for('ssh')->plan($target, 'apply', $context);

        $tmp = sys_get_temp_dir() . '/phycode-deploy-tok123.tgz';
        self::assertSame("tar -czf {$tmp} --exclude=.git --exclude=.phycode --exclude=node_modules --exclude=.env '--exclude=.env.*' --exclude=*.log -C {$context->directory} .", str_replace("'--exclude=*.log'", '--exclude=*.log', $plan->commands()[0]));
        self::assertSame("[prod] copy {$tmp} /tmp/phycode-deploy-tok123.tgz", $plan->commands()[1]);
        self::assertStringStartsWith('[prod] sh -c ', $plan->commands()[2]);
        self::assertStringContainsString("sh /srv/app /tmp/phycode-deploy-tok123.tgz", $plan->commands()[2]);
        self::assertSame(['[prod] bash -c \'npm ci\'', '[prod] bash -c \'systemctl restart app\''], array_slice($plan->commands(), 3));
        self::assertSame([$tmp], $plan->cleanup);
        self::assertSame('/srv/app', $plan->steps[3]->directory);

        self::assertSame(["[prod] bash -c 'systemctl status app'"], Drivers::for('ssh')->plan($target, 'status', $context)->commands());
        self::assertSame(["[prod] bash -c 'journalctl -u app -n 50'"], Drivers::for('ssh')->plan($target, 'logs', $this->context(lines: 50))->commands());
        self::assertSame(["[prod] bash -c './rollback.sh v2'"], Drivers::for('ssh')->plan($target, 'rollback', $this->context(version: 'v2'))->commands());
    }

    public function testSshWithoutSyncOnlyRunsCommandsAndComposeGivesTheUsualOnes(): void
    {
        $target = DeployTarget::fromArray('vps', ['kind' => 'ssh', 'host' => 'prod', 'path' => '~/app', 'sync' => 'none', 'compose' => true]);
        $driver = Drivers::for('ssh');

        self::assertSame(["[prod] bash -c 'docker compose up -d --build --wait --remove-orphans'"], $driver->plan($target, 'apply', $this->context())->commands());
        self::assertSame(["[prod] bash -c 'docker compose ps'"], $driver->plan($target, 'status', $this->context())->commands());
        self::assertSame(["[prod] bash -c 'docker compose logs --tail 100 --no-color'"], $driver->plan($target, 'logs', $this->context())->commands());
        try {
            $driver->plan($target, 'rollback', $this->context());
            self::fail('no rollback command');
        } catch (DeployUnsupported $error) {
            self::assertStringContainsString('commands.rollback', $error->getMessage());
        }
    }

    public function testSshRefusesAnUnknownHostAndACommandThatNeedsAVersion(): void
    {
        $target = DeployTarget::fromArray('vps', ['kind' => 'ssh', 'host' => 'missing', 'path' => '/srv', 'commands' => ['apply' => ['true']]]);
        try {
            Drivers::for('ssh')->plan($target, 'apply', $this->context());
            self::fail('unknown host');
        } catch (DeployUnsupported $error) {
            self::assertStringContainsString("host 'missing' is not in the ssh section", $error->getMessage());
        }

        $target = DeployTarget::fromArray('vps', ['kind' => 'ssh', 'host' => 'prod', 'path' => '/srv', 'commands' => ['rollback' => ['./back {version}']]]);
        $this->expectException(DeployUnsupported::class);
        $this->expectExceptionMessage('needs a version');
        Drivers::for('ssh')->plan($target, 'rollback', $this->context());
    }

    public function testCustomRunsTheCommandsOfThePersonWithTheValuesOfTheCall(): void
    {
        $custom = ['commands' => ['apply' => [['fly', 'deploy', '--remote-only'], ['fly', 'status']], 'logs' => [['fly', 'logs', '-n', '{lines}']], 'rollback' => [['fly', 'releases', 'rollback', '{version}']]]];

        self::assertSame(['fly deploy --remote-only', 'fly status'], $this->commands('custom', $custom, 'apply'));
        self::assertSame(['fly logs -n 30'], $this->commands('custom', $custom, 'logs', $this->context(lines: 30)));
        self::assertSame(['fly releases rollback 12'], $this->commands('custom', $custom, 'rollback', $this->context(version: '12')));
        foreach (['status', 'dry_run'] as $action) {
            try {
                $this->commands('custom', $custom, $action);
                self::fail("custom {$action} without commands");
            } catch (DeployUnsupported $error) {
                self::assertStringContainsString("no commands.{$action}", $error->getMessage());
            }
        }
        $this->expectException(DeployUnsupported::class);
        $this->expectExceptionMessage('needs a version');
        $this->commands('custom', $custom, 'rollback');
    }

    public function testAMissingProgramIsNotedInThePlan(): void
    {
        $target = DeployTarget::fromArray('web', ['kind' => 'vercel']);
        $plan = Drivers::for('vercel')->plan($target, 'apply', $this->context(missing: ['vercel']));

        self::assertSame(['vercel was not found on PATH; install it, or set command in the target'], $plan->notes);
        self::assertSame([], Drivers::for('vercel')->plan($target, 'apply', $this->context())->notes);
    }
}
