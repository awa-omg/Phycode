<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Exec;

use Phycode\Exec\BwrapRunner;
use Phycode\Exec\ProcessRunner;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class BwrapRunnerTest extends TestCase
{
    private string $root;
    private string|false $home;
    private ?string $fakeHome = null;
    private $web = null;
    private int $port = 0;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('bwrap'));
        $this->home = getenv('HOME');
    }

    protected function tearDown(): void
    {
        putenv($this->home === false ? 'HOME' : "HOME={$this->home}");
        if ($this->fakeHome !== null) {
            self::removeTree($this->fakeHome);
        }
        if (is_resource($this->web)) {
            proc_terminate($this->web);
            proc_close($this->web);
        }
    }

    private static function removeTree(string $path): void
    {
        foreach (glob($path . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (in_array(basename($entry), ['.', '..'], true)) {
                continue;
            }
            is_dir($entry) && !is_link($entry) ? self::removeTree($entry) : @unlink($entry);
        }
        @rmdir($path);
    }

    private function stand(): string
    {
        return '/bin/true';
    }

    private function runner(string $network = 'none', array $allow = [], bool $private = false): BwrapRunner
    {
        return new BwrapRunner(new ProcessRunner(), $network, $allow, $this->root, $this->stand(), $private);
    }

    private function real(string $network = 'none', array $allow = [], bool $private = false): BwrapRunner
    {
        $binary = getenv('PHYCODE_TEST_BWRAP') ?: ProcessRunner::which('bwrap');
        if ($binary === null || $binary === false || !is_executable($binary)) {
            self::markTestSkipped('bubblewrap is not installed');
        }
        $probe = (new ProcessRunner())->run([$binary, '--unshare-user-try', '--ro-bind', '/', '/', '--', 'true'], $this->root, 10, 4096);
        if (!$probe->succeeded()) {
            self::markTestSkipped('this machine does not allow user namespaces: ' . trim($probe->stderr));
        }

        return new BwrapRunner(new ProcessRunner(), $network, $allow, $this->root, $binary, $private);
    }

    private function inside(BwrapRunner $runner, array $argv, int $timeout = 20, array $env = []): \Phycode\Exec\ProcessResult
    {
        return $runner->run($argv, $this->root, $timeout, 65536, null, $env);
    }

    private function startWeb(): void
    {
        $docroot = Kit::folder('bwrap-web');
        file_put_contents($docroot . '/hello.txt', "hello from the host\n");
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);
        $this->web = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", '-t', $docroot], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($waited = 0; $waited < 100; $waited++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $message, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
        self::fail('the web server does not listen');
    }

    private function secretHome(): string
    {
        $home = '/var/tmp/phpunit-home-' . bin2hex(random_bytes(4));
        if (!@mkdir($home . '/.ssh', 0700, true)) {
            self::markTestSkipped('cannot make a folder in /var/tmp');
        }
        $this->fakeHome = $home;
        file_put_contents($home . '/.ssh/id_ed25519', 'PRIVATE-KEY-TEXT');
        file_put_contents($home . '/.netrc', 'password TOP-SECRET');
        file_put_contents($home . '/notes.txt', 'ordinary file');
        putenv("HOME={$home}");

        return $home;
    }

    public function testWithoutBubblewrapTheReasonAndTheFixAreSaid(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('needs bubblewrap: install the package bubblewrap');
        new BwrapRunner(new ProcessRunner(), 'none', [], $this->root, '/no/such/bwrap');
    }

    public function testTheNetworkIsNoneOrBridge(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage("sandbox network must be 'none' or 'bridge', not 'host'");
        $this->runner('host');
    }

    public function testTheWholeNetworkAndALimitedListContradictEachOther(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('contradict each other');
        $this->runner('bridge', ['github.com']);
    }

    public function testAnInvalidEntryOfTheListIsRefusedWhenTheRunnerIsMade(): void
    {
        $this->expectException(PhycodeException::class);
        $this->runner('none', ['https://github.com/path']);
    }

    public function testTheRootMustBeAFolderThatIsNotTheRootOfTheMachine(): void
    {
        foreach (['/', $this->root . '/missing'] as $bad) {
            try {
                new BwrapRunner(new ProcessRunner(), 'none', [], $bad, $this->stand());
                self::fail("{$bad} must be refused");
            } catch (PhycodeException $error) {
                self::assertStringContainsString('does not exist or is /', $error->getMessage());
            }
        }
    }

    public function testTheCommandLineIsolatesTheHostAndLeavesTheProjectAsTheOnlyWritablePlace(): void
    {
        $argv = $this->runner()->command(['echo', 'hi'], $this->root);

        self::assertSame($this->stand(), $argv[0]);
        foreach (['--die-with-parent', '--new-session', '--unshare-pid', '--unshare-ipc', '--unshare-net', '--clearenv'] as $flag) {
            self::assertContains($flag, $argv);
        }
        self::assertSame(['--ro-bind', '/', '/'], array_slice($argv, (int) array_search('--ro-bind', $argv, true), 3));
        self::assertContains($this->root, $argv);
        self::assertSame(['--cap-drop', 'ALL'], array_slice($argv, (int) array_search('--cap-drop', $argv, true), 2));
        self::assertSame(['--', 'echo', 'hi'], array_slice($argv, -3));
        $bind = array_search('--bind', $argv, true);
        self::assertSame([$this->root, $this->root], array_slice($argv, $bind + 1, 2));
    }

    public function testTheHostNetworkIsKeptOnlyWhenBridgeIsAsked(): void
    {
        self::assertContains('--unshare-net', $this->runner('none')->command(['true'], $this->root));
        self::assertNotContains('--unshare-net', $this->runner('bridge')->command(['true'], $this->root));
    }

    public function testTheEnvironmentStartsEmptyAndOnlyTheNamedVariablesAreSet(): void
    {
        putenv('PHYCODE_TEST_LEAK=1');
        try {
            $argv = $this->runner()->command(['true'], $this->root, ['MY_VAR' => 'value']);
        } finally {
            putenv('PHYCODE_TEST_LEAK');
        }

        self::assertNotContains('PHYCODE_TEST_LEAK', $argv);
        $set = [];
        foreach (array_keys($argv, '--setenv', true) as $index) {
            $set[$argv[$index + 1]] = $argv[$index + 2];
        }
        self::assertSame('value', $set['MY_VAR']);
        self::assertSame('/tmp', $set['HOME']);
        self::assertArrayNotHasKey('HTTP_PROXY', $set);
    }

    public function testAnEnvironmentNameThatIsNotAVariableIsRefused(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('invalid environment variable');
        $this->runner()->command(['true'], $this->root, ['A=B' => 'x']);
    }

    public function testAFolderOutsideTheProjectIsRefusedEvenThroughALink(): void
    {
        $outside = Kit::folder('bwrap-outside');
        symlink($outside, $this->root . '/link');

        foreach ([$outside, $this->root . '/link'] as $cwd) {
            try {
                $this->runner()->command(['true'], $cwd);
                self::fail("{$cwd} must be refused");
            } catch (PhycodeException $error) {
                self::assertStringContainsString('outside the project', $error->getMessage());
            }
        }
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('empty command');
        $this->runner()->command([], $this->root);
    }

    public function testAProtectedFolderThatIsALinkStopsTheRun(): void
    {
        symlink(Kit::folder('bwrap-elsewhere'), $this->root . '/.git');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('.git is a symbolic link');
        $this->runner()->run(['true'], $this->root, 5, 4096);
    }

    public function testProtectedFoldersThatDoNotExistAreMadeForTheRunAndRemovedAfterwards(): void
    {
        $runner = $this->real();

        $result = $this->inside($runner, ['sh', '-c', 'ls -a; touch .git/x 2>&1; echo "exit $?"']);

        self::assertStringContainsString('.git', $result->stdout);
        self::assertStringContainsString('.phycode', $result->stdout);
        self::assertStringContainsString('.claude', $result->stdout);
        self::assertStringNotContainsString('exit 0', $result->stdout, 'the placeholder is read-only inside');
        foreach (['.git', '.phycode', '.claude'] as $folder) {
            self::assertDirectoryDoesNotExist($this->root . '/' . $folder);
        }
    }

    public function testAnExistingGitFolderIsReadOnlyInsideAndTheRestOfTheProjectIsWritable(): void
    {
        mkdir($this->root . '/.git');
        file_put_contents($this->root . '/.git/config', "[core]\n");
        $runner = $this->real();

        $result = $this->inside($runner, ['sh', '-c', 'echo evil >> .git/config 2>/dev/null; echo "git $?"; echo made > made.txt; echo "project $?"; rm .git/config 2>/dev/null; echo "rm $?"']);

        self::assertStringContainsString('project 0', $result->stdout);
        self::assertStringNotContainsString('git 0', $result->stdout);
        self::assertStringNotContainsString('rm 0', $result->stdout);
        self::assertSame("[core]\n", file_get_contents($this->root . '/.git/config'));
        self::assertSame("made\n", file_get_contents($this->root . '/made.txt'));
        self::assertDirectoryExists($this->root . '/.git', 'a folder that was there stays');
    }

    public function testNothingOutsideTheProjectCanBeWritten(): void
    {
        $outside = '/var/tmp/phpunit-outside-' . bin2hex(random_bytes(4));
        $runner = $this->real();

        $result = $this->inside($runner, ['sh', '-c', "echo x > {$outside} 2>/dev/null; echo \"var $?\"; echo x > /usr/phycode-test 2>/dev/null; echo \"usr $?\"; echo x > /tmp/own.txt; echo \"tmp $?\""]);

        self::assertFileDoesNotExist($outside);
        self::assertFileDoesNotExist('/usr/phycode-test');
        self::assertStringNotContainsString('var 0', $result->stdout);
        self::assertStringNotContainsString('usr 0', $result->stdout);
        self::assertStringContainsString('tmp 0', $result->stdout, '/tmp is the command\'s own');
        self::assertFileDoesNotExist('/tmp/own.txt', 'and it is gone with the command');
    }

    public function testTheCredentialsOfTheHomeFolderAreHiddenAndOtherFilesAreNot(): void
    {
        $home = $this->secretHome();
        $runner = $this->real();

        $result = $this->inside($runner, ['sh', '-c', "ls -A {$home}/.ssh | wc -l; wc -c < {$home}/.netrc; cat {$home}/notes.txt"]);

        self::assertSame("0\n0\nordinary file", $result->stdout);
        self::assertSame('PRIVATE-KEY-TEXT', file_get_contents($home . '/.ssh/id_ed25519'), 'the real files are not touched');
    }

    public function testNoVariableOfTheHostReachesTheCommand(): void
    {
        putenv('PHYCODE_TEST_LEAK=leaked');
        $runner = $this->real();
        try {
            $result = $this->inside($runner, ['/usr/bin/env'], 20, ['MY_VAR' => 'given']);
        } finally {
            putenv('PHYCODE_TEST_LEAK');
        }

        self::assertStringContainsString('MY_VAR=given', $result->stdout);
        self::assertStringNotContainsString('leaked', $result->stdout);
        self::assertStringNotContainsString('PROXY', $result->stdout);
    }

    public function testTheCommandSeesItsOwnProcessesOnly(): void
    {
        $runner = $this->real();

        $result = $this->inside($runner, ['sh', '-c', 'ls /proc | grep -c "^[0-9]"']);

        self::assertLessThan(8, (int) trim($result->stdout));
    }

    public function testWithNetworkNoneTheHostLoopbackIsNotThereAndWithBridgeItIs(): void
    {
        $this->startWeb();
        $curl = ['curl', '-s', '--noproxy', '*', '--max-time', '5', "http://127.0.0.1:{$this->port}/hello.txt"];
        if (ProcessRunner::which('curl') === null) {
            self::markTestSkipped('curl is not installed');
        }

        $none = $this->inside($this->real('none'), $curl);
        $bridge = $this->inside($this->real('bridge'), $curl);

        self::assertNotSame(0, $none->exitCode);
        self::assertStringNotContainsString('hello from the host', $none->stdout);
        self::assertSame(0, $bridge->exitCode);
        self::assertStringContainsString('hello from the host', $bridge->stdout);
    }

    public function testACommandThatRunsTooLongIsStoppedWithEverythingItStarted(): void
    {
        $runner = $this->real();

        $result = $this->inside($runner, ['sh', '-c', 'sleep 47 & sleep 47'], 1);

        self::assertSame('timeout', $result->interrupted);
        $deadline = microtime(true) + 6;
        while (Kit::sleeping('47') && microtime(true) < $deadline) {
            usleep(100000);
        }
        self::assertFalse(Kit::sleeping('47'), 'no sleep is left behind');
    }

    public function testACommandInTheBackgroundRunsInTheSandboxAndEndsWhenStopped(): void
    {
        $runner = $this->real();
        $log = Kit::folder('bwrap-log') . '/job.log';

        $job = $runner->start(['sh', '-c', 'echo from-job > job.txt; echo "$$" ; sleep 48'], $this->root, $log, 60);
        for ($waited = 0; $waited < 100 && !is_file($this->root . '/job.txt'); $waited++) {
            usleep(50000);
        }

        self::assertSame("from-job\n", file_get_contents($this->root . '/job.txt'));
        self::assertTrue($job->running());
        self::assertDirectoryExists($this->root . '/.git', 'the placeholder is there while the job runs');
        $job->stop();
        self::assertFalse($job->running());
        self::assertDirectoryDoesNotExist($this->root . '/.git', 'and gone when it ends');
        $deadline = microtime(true) + 6;
        while (Kit::sleeping('48') && microtime(true) < $deadline) {
            usleep(100000);
        }
        self::assertFalse(Kit::sleeping('48'));
    }

    public function testWithAListOfDomainsOnlyTheProxyReachesTheHostAndOnlyForTheListedNames(): void
    {
        if (ProcessRunner::which('curl') === null || ProcessRunner::which('socat') === null) {
            self::markTestSkipped('curl and socat are needed');
        }
        $this->startWeb();
        $runner = $this->real('none', ["localhost:{$this->port}"], true);
        $proxyEnv = ['NO_PROXY' => '', 'no_proxy' => ''];
        $get = static fn (string $url): array => ['curl', '-s', '--max-time', '8', '-w', '|%{http_code}', $url];

        $allowed = $this->inside($runner, $get("http://localhost:{$this->port}/hello.txt"), 30, $proxyEnv);
        $other = $this->inside($runner, $get('http://localhost:1/hello.txt'), 30, $proxyEnv);
        $direct = $this->inside($runner, ['curl', '-s', '--noproxy', '*', '--max-time', '5', "http://127.0.0.1:{$this->port}/hello.txt"], 30);
        $variables = $this->inside($runner, ['/usr/bin/env'], 30);

        self::assertStringContainsString("hello from the host\n|200", $allowed->stdout);
        self::assertStringContainsString('|403', $other->stdout);
        self::assertStringContainsString('localhost:1 is not in sandbox.allow_domains', $other->stdout);
        self::assertNotSame(0, $direct->exitCode, 'without the proxy there is no network at all');
        self::assertStringContainsString('HTTPS_PROXY=http://127.0.0.1:3128', $variables->stdout);
        self::assertStringContainsString("allowed localhost:{$this->port}", $runner->proxyLog());
        self::assertStringContainsString('denied localhost:1', $runner->proxyLog());
    }

    public function testAFilteredSandboxPassesTheArgumentsOfTheCommandWithoutAShell(): void
    {
        if (ProcessRunner::which('socat') === null) {
            self::markTestSkipped('socat is needed');
        }
        $runner = $this->real('none', ['example.com']);

        $result = $this->inside($runner, ['printf', '%s|', 'a b', '$HOME', "it's"], 30);

        self::assertSame('a b|$HOME|it\'s|', $result->stdout);
    }
}
