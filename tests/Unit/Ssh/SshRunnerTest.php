<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ssh;

use Phycode\Exec\BackgroundJobs;
use Phycode\Exec\ProcessRunner;
use Phycode\Ssh\SshHost;
use Phycode\Ssh\SshRunner;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\SshServer;
use PHPUnit\Framework\TestCase;

final class SshRunnerTest extends TestCase
{
    private static ?SshServer $server = null;
    private static bool $tried = false;

    private string $local;

    public static function server(): ?SshServer
    {
        if (!self::$tried) {
            self::$tried = true;
            self::$server = SshServer::start();
        }

        return self::$server;
    }

    protected function setUp(): void
    {
        if (self::server() === null) {
            self::markTestSkipped('no sshd that this user can run');
        }
        $this->local = realpath(Kit::folder('ssh-local'));
    }

    private function runner(array $settings = []): SshRunner
    {
        return self::server()->hosts($settings)->runner('test');
    }

    public function testACommandReturnsItsOutputItsErrorsAndItsExitCode(): void
    {
        $result = $this->runner()->run(['bash', '-c', 'echo out; echo err >&2; exit 3'], $this->local, 30, 4096);

        self::assertSame(3, $result->exitCode);
        self::assertSame("out\n", $result->stdout);
        self::assertSame("err\n", $result->stderr);
        self::assertNull($result->interrupted);
    }

    public function testQuotesNewlinesAndUnicodeReachTheHostUnchanged(): void
    {
        $command = "printf '%s|' \"a b\" 'c'\"'\"'d' \$HOME\necho \"héllo ñ \\\$x \`not run\`\"\necho 'it''s'";
        $local = new ProcessRunner();
        $expected = $local->run(['bash', '-c', $command], $this->local, 30, 4096);

        $remote = $this->runner()->run(['bash', '-c', $command], $this->local, 30, 4096);

        self::assertSame($expected->stdout, $remote->stdout);
        self::assertStringContainsString('héllo ñ', $remote->stdout);
    }

    public function testTheCommandStartsInTheDirectoryOfTheHost(): void
    {
        $folder = realpath(Kit::folder('ssh-remote'));
        $runner = $this->runner(['cwd' => $folder]);

        self::assertSame("{$folder}\n", $runner->run(['bash', '-c', 'pwd'], $this->local, 30, 4096)->stdout);
        $other = realpath(Kit::folder('ssh-other'));
        self::assertSame("{$other}\n", $runner->inDirectory($other)->run(['bash', '-c', 'pwd'], $this->local, 30, 4096)->stdout);
        self::assertSame((string) getenv('HOME') . "\n", $this->runner(['cwd' => '~'])->run(['bash', '-c', 'pwd'], $this->local, 30, 4096)->stdout);
    }

    public function testADirectoryThatDoesNotExistIsReportedAndNothingRuns(): void
    {
        $marker = $this->local . '/ran';
        $result = $this->runner(['cwd' => '/nonexistent-phycode-folder'])->run(['bash', '-c', "touch {$marker}"], $this->local, 30, 4096);

        self::assertSame(125, $result->exitCode);
        self::assertStringContainsString('cannot go to /nonexistent-phycode-folder on the host', $result->stderr);
        self::assertFileDoesNotExist($marker);
    }

    public function testAProgramRunsWithItsArgumentsAsWords(): void
    {
        $result = $this->runner()->run(['printf', '%s|%s', 'a b', "it's"], $this->local, 30, 4096);

        self::assertSame("a b|it's", $result->stdout);
    }

    public function testTheHostEndsACommandThatRunsPastItsTimeLimit(): void
    {
        $started = microtime(true);
        $result = $this->runner()->run(['bash', '-c', 'sleep 41'], $this->local, 1, 4096);

        self::assertSame(124, $result->exitCode);
        self::assertLessThan(10, microtime(true) - $started);
        self::assertFalse(Kit::sleeping('41'), 'the sleep on the host is gone');
    }

    public function testACancelledCommandReturnsAtOnce(): void
    {
        $cancel = new Cancellation();
        $started = microtime(true);
        $cancel->cancel('test');

        $result = $this->runner()->run(['bash', '-c', 'sleep 42'], $this->local, 3, 4096, $cancel);

        self::assertSame('cancelled', $result->interrupted);
        self::assertLessThan(10, microtime(true) - $started);
    }

    public function testCommandsShareOneConnectionAndCloseEndsIt(): void
    {
        $hosts = self::server()->hosts();
        $runner = $hosts->runner('test');
        $folder = $this->controlFolder($hosts);

        $runner->run(['true'], $this->local, 30, 4096);
        $sockets = glob($folder . '/*') ?: [];
        self::assertCount(1, $sockets, 'the first command leaves a master connection');
        self::assertSame(0700, fileperms($folder) & 0777);

        $started = microtime(true);
        $runner->run(['true'], $this->local, 30, 4096);
        $runner->run(['true'], $this->local, 30, 4096);
        self::assertLessThan(3.0, microtime(true) - $started, 'later commands reuse the connection');
        self::assertCount(1, glob($folder . '/*') ?: []);

        $runner->close();
        $deadline = microtime(true) + 3;
        while (glob($folder . '/*') && microtime(true) < $deadline) {
            usleep(50000);
        }
        self::assertSame([], glob($folder . '/*') ?: [], 'close() ends the master connection');
    }

    public function testAHostKeyThatIsNotKnownIsRefusedWhenTheCheckIsStrict(): void
    {
        $runner = $this->runner(['known_hosts' => self::server()->emptyKnownHosts()]);

        $result = $runner->run(['true'], $this->local, 30, 4096);

        self::assertSame(255, $result->exitCode);
        self::assertStringContainsString('strict checking', $result->stderr);
    }

    public function testAKeyThatChangedIsRefusedEvenWithAcceptNew(): void
    {
        $file = Kit::folder('ssh-changed') . '/known_hosts';
        $other = Kit::folder('ssh-otherkey') . '/k';
        exec('ssh-keygen -q -t ed25519 -N "" -f ' . escapeshellarg($other));
        $key = explode(' ', trim((string) file_get_contents($other . '.pub')));
        file_put_contents($file, '[127.0.0.1]:' . self::server()->port . " {$key[0]} {$key[1]}\n");

        $result = $this->runner(['known_hosts' => $file, 'host_key' => 'accept-new'])->run(['true'], $this->local, 30, 4096);

        self::assertSame(255, $result->exitCode);
        self::assertStringContainsString('REMOTE HOST IDENTIFICATION HAS CHANGED', $result->stderr);
    }

    public function testAcceptNewAddsAnUnknownHostAndTheNextCallTrustsIt(): void
    {
        $file = Kit::folder('ssh-new') . '/known_hosts';
        touch($file);
        $runner = $this->runner(['known_hosts' => $file, 'host_key' => 'accept-new']);

        self::assertSame(0, $runner->run(['true'], $this->local, 30, 4096)->exitCode);
        self::assertStringContainsString('127.0.0.1', (string) file_get_contents($file));
    }

    public function testAJobOnTheHostEndsWhenItIsStopped(): void
    {
        $jobs = new BackgroundJobs(Kit::folder('ssh-jobs') . '/out');
        $runner = $this->runner();

        $job = $jobs->start($runner, ['bash', '-c', 'echo begun; sleep 43; echo never'], $this->local, 'ssh test: sleep');

        $deadline = microtime(true) + 10;
        $text = '';
        while (!str_contains($text, 'begun') && microtime(true) < $deadline) {
            $text .= $job->read(1000)[0];
            usleep(100000);
        }
        self::assertStringContainsString('begun', $text);
        self::assertTrue(Kit::sleeping('43'), 'the command runs on the host');

        $job->stop();

        $deadline = microtime(true) + 5;
        while (Kit::sleeping('43') && microtime(true) < $deadline) {
            usleep(100000);
        }
        self::assertFalse(Kit::sleeping('43'), 'stopping the job ends the command on the host');
        $jobs->stopAll();
    }

    public function testAFileGoesToTheHostAndComesBack(): void
    {
        $runner = $this->runner();
        $source = $this->local . '/source.txt';
        file_put_contents($source, "line one\nline two ñ\n");
        $remote = Kit::folder('ssh-copy') . '/target name.txt';

        $up = $runner->copy(true, $source, $remote, 30, 4096);
        self::assertSame(0, $up->exitCode, $up->stderr);
        self::assertSame("line one\nline two ñ\n", file_get_contents($remote));

        $back = $this->local . '/back.txt';
        $down = $runner->copy(false, $back, $remote, 30, 4096);
        self::assertSame(0, $down->exitCode, $down->stderr);
        self::assertSame("line one\nline two ñ\n", file_get_contents($back));
    }

    public function testAnOutputLargerThanTheLimitKeepsItsHeadAndTail(): void
    {
        $result = $this->runner()->run(['bash', '-c', 'seq 1 20000'], $this->local, 30, 2000);

        self::assertStringStartsWith("1\n2\n", $result->stdout);
        self::assertStringContainsString('bytes omitted', $result->stdout);
        self::assertStringEndsWith("20000\n", $result->stdout);
    }

    public function testTheOptionsKeepAnUnattendedClientSafe(): void
    {
        $runner = $this->runner(['identity_file' => '/tmp/key', 'cwd' => '/srv']);
        $options = $runner->options(false);

        foreach (['BatchMode=yes', 'StrictHostKeyChecking=yes', 'ForwardAgent=no', 'ForwardX11=no', 'ClearAllForwardings=yes', 'PermitLocalCommand=no', 'IdentitiesOnly=yes'] as $option) {
            self::assertContains($option, $options);
        }
        self::assertContains('-T', $options);
        self::assertContains('-tt', $runner->options(true));
        self::assertNotContains('-A', $options);
    }

    private function controlFolder(\Phycode\Ssh\SshHosts $hosts): string
    {
        $options = $hosts->runner('test')->options(false);
        foreach ($options as $option) {
            if (str_starts_with($option, 'ControlPath=')) {
                return dirname(substr($option, strlen('ControlPath=')));
            }
        }
        self::fail('no ControlPath');
    }
}
