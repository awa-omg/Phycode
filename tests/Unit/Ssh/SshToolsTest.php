<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ssh;

use Phycode\Agent\AgentRunner;
use Phycode\Agent\Conversation;
use Phycode\Agent\RunSetup;
use Phycode\Exec\BackgroundJobs;
use Phycode\Security\Permissions;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SshServer;
use Phycode\Tools\JobTool;
use Phycode\Tools\SshCopyTool;
use Phycode\Tools\SshTool;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class SshToolsTest extends TestCase
{
    private string $root;
    private ToolContext $context;

    protected function setUp(): void
    {
        if (SshRunnerTest::server() === null) {
            self::markTestSkipped('no sshd that this user can run');
        }
        $this->root = realpath(Kit::folder('ssh-tools'));
        $this->context = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());
    }

    private function server(): SshServer
    {
        return SshRunnerTest::server() ?? throw new \LogicException('no server');
    }

    public function testTheSchemaOffersOnlyTheHostsOfTheConfigAndNamesThem(): void
    {
        $tool = new SshTool($this->server()->hosts(['cwd' => '/srv/app'], 'prod'), new BackgroundJobs(Kit::folder('ssh-tool-jobs') . '/o'));

        $parameters = $tool->parameters();
        self::assertSame(['prod'], $parameters['properties']['host']['enum']);
        self::assertSame(['host', 'command'], $parameters['required']);
        self::assertArrayHasKey('background', $parameters['properties']);
        self::assertStringContainsString('Hosts: prod (starts in /srv/app)', $tool->description());
        self::assertSame('ssh prod: ls -la', $tool->summarize(['host' => 'prod', 'command' => 'ls -la']));
    }

    public function testTheHardLineReadsTheFactsOfTheHostAndItsFolder(): void
    {
        $hosts = $this->server()->hosts(['cwd' => '/usr/lib']);
        $tool = new SshTool($hosts);
        $permissions = new Permissions(new RecordingUi());

        $facts = $tool->hardLineFacts(['host' => 'test']);

        self::assertNotNull($facts);
        self::assertSame(posix_getpwuid(posix_geteuid())['dir'], $facts['home']);
        self::assertSame('/usr/lib', $facts['cwd'], 'the folder of the host');
        self::assertSame($facts['home'] . '/app', $tool->hardLineFacts(['host' => 'test', 'cwd' => '~/app'])['cwd']);
        self::assertNotNull($permissions->check($tool, $this->context, 'ssh test: rm -rf ..', ['host' => 'test', 'command' => 'rm -rf ..']), '.. of /usr/lib on the host is /usr');
        self::assertNull($permissions->check($tool, $this->context, 'ssh test: rm -rf build', ['host' => 'test', 'command' => 'rm -rf build', 'cwd' => '~/app']));
    }

    public function testACommandRunsOnTheHostAndTheReportShowsItsOutput(): void
    {
        $tool = new SshTool($this->server()->hosts());

        $result = $tool->execute(['host' => 'test', 'command' => 'echo hello; echo oops >&2; exit 2'], $this->context);

        self::assertFalse($result->ok);
        self::assertStringContainsString('exit code: 2', $result->content);
        self::assertStringContainsString("hello\n", $result->content);
        self::assertStringContainsString("[stderr]\noops", $result->content);
        self::assertSame(2, $result->meta['exit']);
    }

    public function testTheDirectoryOfTheCallWinsOverTheOneOfTheHost(): void
    {
        $default = realpath(Kit::folder('ssh-default'));
        $other = realpath(Kit::folder('ssh-other'));
        $tool = new SshTool($this->server()->hosts(['cwd' => $default]));

        self::assertStringContainsString($default, $tool->execute(['host' => 'test', 'command' => 'pwd'], $this->context)->content);
        self::assertStringContainsString($other, $tool->execute(['host' => 'test', 'command' => 'pwd', 'cwd' => $other], $this->context)->content);
    }

    public function testASshFailureIsExplainedAndNamesTheFix(): void
    {
        $tool = new SshTool($this->server()->hosts(['known_hosts' => $this->server()->emptyKnownHosts()]));

        $result = $tool->execute(['host' => 'test', 'command' => 'true'], $this->context);

        self::assertFalse($result->ok);
        self::assertStringContainsString('ssh itself failed (exit 255)', $result->content);
        self::assertStringContainsString('phycode ssh trust test', $result->content);
    }

    public function testAJobOnTheHostIsStartedAndReadThroughTheJobTool(): void
    {
        $jobs = new BackgroundJobs(Kit::folder('ssh-tool-jobs2') . '/o');
        $registry = new ToolRegistry();
        $hosts = $this->server()->hosts();
        $registry->register(new SshTool($hosts, $jobs));
        $registry->register(new JobTool($jobs));
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('task');
        $client = new ScriptedClient([
            ScriptedClient::call('ssh', ['host' => 'test', 'command' => 'echo up; sleep 45', 'background' => true]),
            ScriptedClient::call('job', ['wait' => 10]),
            ScriptedClient::call('job', ['action' => 'stop']),
            ScriptedClient::text('done'),
        ]);

        $ui = new RecordingUi();
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), $ui);
        $outcome = (new AgentRunner())->run($conversation, new RunSetup($client, 'native', $registry, $context, 8, false));

        self::assertSame('done', $outcome->text);
        self::assertSame(['ssh', 'job', 'job'], $ui->startedTools());
        $toolReplies = array_values(array_filter($client->requests[3]['messages'], static fn (array $m): bool => ($m['role'] ?? '') === 'tool'));
        self::assertStringContainsString('ssh test: echo up; sleep 45', $toolReplies[1]['content']);
        self::assertStringContainsString('up', $toolReplies[1]['content']);
        $deadline = microtime(true) + 5;
        while (Kit::sleeping('45') && microtime(true) < $deadline) {
            usleep(100000);
        }
        self::assertFalse(Kit::sleeping('45'), 'stopping the job ended the command on the host');
        $jobs->stopAll();
    }

    public function testBadCallsAreRefusedBeforeAnythingRuns(): void
    {
        $tool = new SshTool($this->server()->hosts());

        foreach ([
            [['host' => 'other', 'command' => 'ls'], "no ssh host named 'other'"],
            [['host' => 'test', 'command' => '  '], 'empty'],
            [['host' => 'test', 'command' => str_repeat('a', 70000)], 'longer than'],
            [['host' => 'test', 'command' => 'ls', 'cwd' => 'relative'], 'cwd'],
            [['command' => 'ls'], "missing required argument 'host'"],
        ] as [$args, $message]) {
            try {
                $tool->validate($args, $this->context);
                self::fail('accepted ' . json_encode($args));
            } catch (PhycodeException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        $tool->validate(['host' => 'test', 'command' => 'ls', 'cwd' => '~/app'], $this->context);
    }

    public function testAFileOfTheProjectGoesToTheHost(): void
    {
        $tool = new SshCopyTool($this->server()->hosts());
        file_put_contents($this->root . '/deploy.txt', "hello host\n");
        $remote = realpath(Kit::folder('ssh-remote-put')) . '/deploy copy.txt';

        $result = $tool->execute(['host' => 'test', 'direction' => 'put', 'local' => 'deploy.txt', 'remote' => $remote], $this->context);

        self::assertTrue($result->ok, $result->content);
        self::assertStringContainsString('sent deploy.txt (11 bytes)', $result->content);
        self::assertSame("hello host\n", file_get_contents($remote));
    }

    public function testAFileOfTheHostComesIntoTheProjectAndCountsAsAChange(): void
    {
        $tool = new SshCopyTool($this->server()->hosts());
        $remote = realpath(Kit::folder('ssh-remote-get')) . '/log.txt';
        file_put_contents($remote, "remote line\n");

        $result = $tool->execute(['host' => 'test', 'direction' => 'get', 'local' => 'logs/from-host.txt', 'remote' => $remote], $this->context);

        self::assertTrue($result->ok, $result->content);
        self::assertSame("remote line\n", file_get_contents($this->root . '/logs/from-host.txt'));
        self::assertContains('logs/from-host.txt', $this->context->changes->since(0));
        self::assertSame([], glob($this->root . '/.phycode/tmp/ssh-*') ?: [], 'the download leaves no temporary file');
    }

    public function testDownloadingIntoAnExistingFileKeepsItsModeAndRefusesProtectedPaths(): void
    {
        $tool = new SshCopyTool($this->server()->hosts());
        $remote = realpath(Kit::folder('ssh-remote-get2')) . '/new.txt';
        file_put_contents($remote, "new\n");
        file_put_contents($this->root . '/keep.sh', "old\n");
        chmod($this->root . '/keep.sh', 0750);

        self::assertTrue($tool->execute(['host' => 'test', 'direction' => 'get', 'local' => 'keep.sh', 'remote' => $remote], $this->context)->ok);
        self::assertSame("new\n", file_get_contents($this->root . '/keep.sh'));
        self::assertSame(0750, fileperms($this->root . '/keep.sh') & 0777);

        $this->expectException(\Phycode\Security\PathException::class);
        $tool->validate(['host' => 'test', 'direction' => 'get', 'local' => '.git/hooks/pre-commit', 'remote' => $remote], $this->context);
    }

    public function testFilesOutsideTheProjectAndBigFilesAreRefused(): void
    {
        $tool = new SshCopyTool($this->server()->hosts());
        $remote = realpath(Kit::folder('ssh-remote-big')) . '/big.bin';
        file_put_contents($remote, str_repeat('x', 8388609));
        file_put_contents($this->root . '/big.bin', str_repeat('x', 8388609));

        self::assertStringContainsString('more than the 8 MB', $tool->execute(['host' => 'test', 'direction' => 'get', 'local' => 'got.bin', 'remote' => $remote], $this->context)->content);
        self::assertFileDoesNotExist($this->root . '/got.bin');
        self::assertStringContainsString('larger than 8 MB', $tool->execute(['host' => 'test', 'direction' => 'put', 'local' => 'big.bin', 'remote' => $remote . '2'], $this->context)->content);
        self::assertStringContainsString('cannot read', $tool->execute(['host' => 'test', 'direction' => 'get', 'local' => 'x.txt', 'remote' => '/nonexistent/file'], $this->context)->content);

        $this->expectException(\Phycode\Security\PathException::class);
        $tool->validate(['host' => 'test', 'direction' => 'put', 'local' => '/etc/passwd', 'remote' => '/tmp/x'], $this->context);
    }
}
