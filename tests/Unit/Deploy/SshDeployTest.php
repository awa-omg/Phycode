<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Deploy;

use Phycode\Deploy\DeployTarget;
use Phycode\Deploy\Deployments;
use Phycode\Exec\ProcessRunner;
use Phycode\Support\Cancellation;
use Phycode\Support\Vault;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Ssh\SshRunnerTest;
use PHPUnit\Framework\TestCase;

final class SshDeployTest extends TestCase
{
    private string $root;
    private string $remote;
    private ?string $homeFolder = null;

    protected function setUp(): void
    {
        if (SshRunnerTest::server() === null) {
            self::markTestSkipped('no sshd that this user can run');
        }
        $this->root = realpath(Kit::folder('ssh-deploy-project'));
        $this->remote = Kit::folder('ssh-deploy-remote') . '/app';
        Kit::write($this->root . '/app.txt', "hello\n");
        Kit::write($this->root . '/sub/deep.txt', "deep\n");
        Kit::write($this->root . '/.env', "SECRET=1\n");
        Kit::write($this->root . '/.git/config', "[core]\n");
        Kit::write($this->root . '/node_modules/x/index.js', "x\n");
        Kit::write($this->root . '/logs/a.log', "log\n");
    }

    protected function tearDown(): void
    {
        if ($this->homeFolder !== null) {
            exec('rm -rf ' . escapeshellarg($this->homeFolder));
        }
    }

    private function deployments(array $options): Deployments
    {
        $server = SshRunnerTest::server();
        $target = DeployTarget::fromArray('vps', $options + ['kind' => 'ssh', 'environment' => 'staging', 'host' => 'prod', 'path' => $this->remote]);

        return new Deployments(['vps' => $target], new Vault(Kit::folder('ssh-deploy-vault')), new ProcessRunner(), $server->hosts([], 'prod'), $this->root, null, 1, 0);
    }

    private function act(Deployments $deployments, string $action, ?string $version = null): \Phycode\Deploy\DeployResult
    {
        return $deployments->run('vps', $action, $version, 20, new Cancellation(), 8192);
    }

    public function testTheProjectIsPackedSentUnpackedAndTheCommandsRunThere(): void
    {
        $deployments = $this->deployments(['exclude' => ['*.log'], 'commands' => ['apply' => ['cat app.txt > deployed.marker', 'echo ran > ran.txt']]]);
        $before = glob(sys_get_temp_dir() . '/phycode-deploy-*.tgz') ?: [];

        $result = $this->act($deployments, 'apply');

        self::assertTrue($result->ok, $result->report(true));
        self::assertSame(['ok', 'ok', 'ok', 'ok', 'ok'], array_column($result->steps, 'status'));
        self::assertSame("hello\n", file_get_contents($this->remote . '/app.txt'));
        self::assertSame("deep\n", file_get_contents($this->remote . '/sub/deep.txt'));
        self::assertSame("hello\n", file_get_contents($this->remote . '/deployed.marker'), 'the commands run in the folder of the target');
        self::assertSame("ran\n", file_get_contents($this->remote . '/ran.txt'));
        foreach (['.env', '.git', 'node_modules', 'logs/a.log'] as $excluded) {
            self::assertFileDoesNotExist($this->remote . '/' . $excluded, "{$excluded} stays on this machine");
        }
        self::assertSame([], array_diff(glob('/tmp/phycode-deploy-*.tgz') ?: [], $before), 'the archive on the host is removed');
        self::assertSame($before, glob(sys_get_temp_dir() . '/phycode-deploy-*.tgz') ?: [], 'and so is the one on this machine');
        self::assertSame(['vps'], array_unique(array_column($deployments->history(), 'target')));
    }

    public function testStatusAndLogsRunTheCommandsOfTheTargetOnTheHost(): void
    {
        $deployments = $this->deployments(['commands' => ['apply' => ['true'], 'status' => ['ls'], 'logs' => ['echo "last {lines} lines"']]]);
        self::assertTrue($this->act($deployments, 'apply')->ok);

        $status = $this->act($deployments, 'status');
        self::assertTrue($status->ok, $status->report(true));
        self::assertStringContainsString('app.txt', $status->report(true));
        self::assertStringContainsString('sub', $status->report(true));

        $logs = $deployments->run('vps', 'logs', null, 7, new Cancellation(), 8192);
        self::assertStringContainsString('last 7 lines', $logs->report(true));
        self::assertCount(1, $deployments->history(), 'only the apply is remembered');
    }

    public function testACommandOfTheHostThatFailsFailsTheDeployAndTheArchiveIsStillRemoved(): void
    {
        $deployments = $this->deployments(['commands' => ['apply' => ['echo going >&2', 'exit 5', 'echo never > never.txt']]]);

        $result = $this->act($deployments, 'apply');

        self::assertFalse($result->ok);
        self::assertSame(['ok', 'ok', 'ok', 'ok', 'failed', 'skipped'], array_column($result->steps, 'status'));
        self::assertStringContainsString('(exit code 5)', (string) $result->error);
        self::assertFileDoesNotExist($this->remote . '/never.txt');
        self::assertSame([], glob(sys_get_temp_dir() . '/phycode-deploy-*.tgz') ?: []);
        self::assertFalse($deployments->history()[0]['ok']);
    }

    public function testThePathMayStartWithTheHomeFolderOfTheHostUser(): void
    {
        $name = 'phycode-test-' . bin2hex(random_bytes(4));
        $this->homeFolder = (string) getenv('HOME') . '/' . $name;
        $deployments = $this->deployments(['path' => "~/{$name}/app", 'sync' => 'tar', 'commands' => ['apply' => ['pwd > where.txt']]]);

        $result = $this->act($deployments, 'apply');

        self::assertTrue($result->ok, $result->report(true));
        self::assertSame("{$this->homeFolder}/app\n", file_get_contents("{$this->homeFolder}/app/where.txt"));
        self::assertFileExists("{$this->homeFolder}/app/app.txt");
    }

    public function testASyncOfNoneOnlyRunsTheCommandsAndTheRollbackGetsItsVersion(): void
    {
        mkdir($this->remote, 0777, true);
        $deployments = $this->deployments(['sync' => 'none', 'commands' => ['apply' => ['echo applied > a.txt'], 'rollback' => ['echo "back to {version}" > rolled.txt']]]);

        self::assertTrue($this->act($deployments, 'apply')->ok);
        self::assertFileDoesNotExist($this->remote . '/app.txt');
        self::assertSame("applied\n", file_get_contents($this->remote . '/a.txt'));

        self::assertTrue($this->act($deployments, 'rollback', 'v12')->ok);
        self::assertSame("back to v12\n", file_get_contents($this->remote . '/rolled.txt'));
    }
}
