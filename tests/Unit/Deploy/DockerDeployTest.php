<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Deploy;

use Phycode\Deploy\DeployResult;
use Phycode\Deploy\DeployTarget;
use Phycode\Deploy\Deployments;
use Phycode\Exec\ProcessRunner;
use Phycode\Ssh\SshHosts;
use Phycode\Support\Cancellation;
use Phycode\Support\Vault;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class DockerDeployTest extends TestCase
{
    private const BASE = 'debian:trixie-slim';

    private string $root;
    private string $name;

    protected function setUp(): void
    {
        $docker = ProcessRunner::which('docker');
        if ($docker === null) {
            self::markTestSkipped('docker is not installed');
        }
        $probe = (new ProcessRunner())->run([$docker, 'image', 'inspect', self::BASE], sys_get_temp_dir(), 20, 4096);
        if (!$probe->succeeded()) {
            self::markTestSkipped('no docker daemon, or ' . self::BASE . ' is not pulled');
        }
        $this->root = realpath(Kit::folder('docker-deploy'));
        $this->name = 'pctest' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!isset($this->name)) {
            return;
        }
        $runner = new ProcessRunner();
        $docker = (string) ProcessRunner::which('docker');
        $runner->run([$docker, 'rm', '-f', $this->name], sys_get_temp_dir(), 30, 4096);
        $runner->run([$docker, 'compose', '-p', $this->name, 'down', '-v', '--remove-orphans'], $this->root, 60, 4096);
        $images = $runner->run([$docker, 'images', '--format', '{{.Repository}}:{{.Tag}}', "pctest-{$this->name}/web"], sys_get_temp_dir(), 20, 65536);
        foreach (array_filter(explode("\n", $images->stdout)) as $image) {
            $runner->run([$docker, 'rmi', '-f', $image], sys_get_temp_dir(), 30, 4096);
        }
    }

    private function deployments(array $raw): Deployments
    {
        return new Deployments([$this->name => DeployTarget::fromArray($this->name, ['kind' => 'docker'] + $raw)], new Vault(Kit::folder('docker-vault')), new ProcessRunner(), new SshHosts([], new ProcessRunner()), $this->root, null, 1, 0);
    }

    private function act(Deployments $deployments, string $action): DeployResult
    {
        return $deployments->run($this->name, $action, null, 50, new Cancellation(), 8192);
    }

    private function docker(string ...$arguments): string
    {
        return trim((new ProcessRunner())->run([(string) ProcessRunner::which('docker'), ...$arguments], sys_get_temp_dir(), 30, 65536)->stdout);
    }

    public function testAPlainImageIsBuiltRunAndRolledBack(): void
    {
        $image = "pctest-{$this->name}/web";
        Kit::write($this->root . '/Dockerfile', 'FROM ' . self::BASE . "\nCMD [\"sleep\", \"600\"]\n");
        $deployments = $this->deployments(['environment' => 'preview', 'image' => $image, 'container' => $this->name]);

        $dry = $this->act($deployments, 'dry_run');
        self::assertTrue($dry->ok, $dry->report(true));
        self::assertSame('', $this->docker('ps', '-q', '--filter', "name=^{$this->name}\$"), 'a dry run starts nothing');

        $first = $this->act($deployments, 'apply');
        self::assertTrue($first->ok, $first->report(true));
        self::assertSame(['optional-failed', 'ok', 'ok', 'ok'], array_column($first->steps, 'status'), 'there is no :latest to keep the first time (rm -f of a missing container is not an error)');
        $firstImage = $this->docker('inspect', '-f', '{{.Image}}', $this->name);
        self::assertNotSame('', $firstImage);

        $status = $this->act($deployments, 'status');
        self::assertTrue($status->ok);
        self::assertStringContainsString($this->name, $status->report(true));
        self::assertStringContainsString('Up', $status->report(true));
        self::assertTrue($this->act($deployments, 'logs')->ok);

        Kit::write($this->root . '/Dockerfile', 'FROM ' . self::BASE . "\nCMD [\"sleep\", \"601\"]\n");
        $second = $this->act($deployments, 'apply');
        self::assertTrue($second->ok, $second->report(true));
        self::assertSame(['ok', 'ok', 'ok', 'ok'], array_column($second->steps, 'status'), 'now :latest is kept as :previous and the old container is replaced');
        $secondImage = $this->docker('inspect', '-f', '{{.Image}}', $this->name);
        self::assertNotSame($firstImage, $secondImage);
        self::assertSame($firstImage, $this->docker('image', 'inspect', '-f', '{{.Id}}', "{$image}:previous"));

        $back = $this->act($deployments, 'rollback');
        self::assertTrue($back->ok, $back->report(true));
        self::assertSame($firstImage, $this->docker('inspect', '-f', '{{.Image}}', $this->name), 'the container runs the first image again');

        $history = $deployments->history($this->name);
        self::assertSame(['rollback', 'apply', 'apply', 'dry_run'], array_column($history, 'action'));
    }

    public function testAComposeProjectIsBuiltAndStarted(): void
    {
        Kit::write($this->root . '/compose.yaml', "services:\n  app:\n    image: " . self::BASE . "\n    command: [\"sleep\", \"600\"]\n");
        $deployments = $this->deployments(['environment' => 'preview']);

        $applied = $this->act($deployments, 'apply');
        self::assertTrue($applied->ok, $applied->report(true));
        self::assertStringContainsString($this->name . '-app-1', $this->docker('ps', '--format', '{{.Names}}', '--filter', 'label=com.docker.compose.project=' . $this->name));

        $status = $this->act($deployments, 'status');
        self::assertTrue($status->ok, $status->report(true));
        self::assertStringContainsString('app', $status->report(true));
        self::assertTrue($this->act($deployments, 'logs')->ok);

        $rollback = $this->act($deployments, 'rollback');
        self::assertFalse($rollback->ok);
        self::assertStringContainsString('revert the code', (string) $rollback->error);
    }

    public function testABuildThatFailsStopsBeforeTheContainerIsTouched(): void
    {
        $image = "pctest-{$this->name}/web";
        Kit::write($this->root . '/Dockerfile', 'FROM ' . self::BASE . "\nCMD [\"sleep\", \"600\"]\n");
        $deployments = $this->deployments(['environment' => 'preview', 'image' => $image, 'container' => $this->name]);
        self::assertTrue($this->act($deployments, 'apply')->ok);
        $running = $this->docker('inspect', '-f', '{{.Id}}', $this->name);

        Kit::write($this->root . '/Dockerfile', 'FROM ' . self::BASE . "\nRUN exit 7\n");
        $broken = $this->act($deployments, 'apply');

        self::assertFalse($broken->ok);
        self::assertSame(['ok', 'failed', 'skipped', 'skipped'], array_column($broken->steps, 'status'));
        self::assertSame($running, $this->docker('inspect', '-f', '{{.Id}}', $this->name), 'the container that ran is still the one that runs');
    }
}
