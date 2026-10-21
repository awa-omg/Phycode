<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ssh;

use Phycode\Exec\ProcessRunner;
use Phycode\Ssh\SshHost;
use Phycode\Ssh\SshRunner;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class RemoteScriptTest extends TestCase
{
    private function onHost(array $argv, ?string $directory = null, int $timeout = 30, bool $terminal = false): \Phycode\Exec\ProcessResult
    {
        return (new ProcessRunner())->run(['sh', '-c', SshRunner::remoteCommand($argv, $directory, $timeout, $terminal)], sys_get_temp_dir(), 40, 65536);
    }

    public function testTheCommandIsOneLineOfPlainCharactersWhateverTheScriptHolds(): void
    {
        $command = SshRunner::remoteCommand(['bash', '-c', "echo 'a'\n\"b\" \$(c) `d` ; & | > < \\ \0"[0] . "x'\"\n"], '/srv/it\'s', 30);

        self::assertMatchesRegularExpression('~^echo [A-Za-z0-9+/=]+\|base64 -d\|sh$~', $command);
    }

    public function testArgumentsAreQuotedForSh(): void
    {
        $words = ["it's", 'a b', '$HOME', '`id`', '"q"', "line\nbreak", 'ñ', '; true', '*'];
        $result = $this->onHost(['printf', '%s|', ...$words]);

        self::assertSame(implode('|', $words) . '|', $result->stdout);
    }

    public function testAScriptIsRunByBashAndTheExitCodeIsKept(): void
    {
        $result = $this->onHost(['bash', '-c', 'echo out; echo err >&2; exit 9']);

        self::assertSame(9, $result->exitCode);
        self::assertSame("out\n", $result->stdout);
        self::assertSame("err\n", $result->stderr);
    }

    public function testTheEnvironmentIsPlainForAnAgent(): void
    {
        $result = $this->onHost(['bash', '-c', 'echo "$TERM $NO_COLOR $PAGER $GIT_PAGER"']);

        self::assertSame("dumb 1 cat cat\n", $result->stdout);
    }

    public function testTheDirectoryIsEnteredOrTheCommandDoesNotRun(): void
    {
        $folder = realpath(Kit::folder('script-cwd'));
        $marker = $folder . '/marker';

        self::assertSame("{$folder}\n", $this->onHost(['pwd'], $folder)->stdout);
        $missing = $this->onHost(['touch', $marker], $folder . '/missing');
        self::assertSame(125, $missing->exitCode);
        self::assertFileDoesNotExist($marker);
        self::assertSame((string) getenv('HOME') . "\n", $this->onHost(['pwd'], '~')->stdout);

        mkdir($folder . "/it's here");
        self::assertSame("{$folder}/it's here\n", $this->onHost(['pwd'], $folder . "/it's here")->stdout);
    }

    public function testACommandThatRunsPastTheLimitIsEndedByTheHost(): void
    {
        if (ProcessRunner::which('timeout') === null) {
            self::markTestSkipped('the host has no timeout program, so the script has nothing to end the command with');
        }
        $started = microtime(true);
        $result = $this->onHost(['sleep', '44'], null, 1);

        self::assertSame(124, $result->exitCode);
        self::assertLessThan(8, microtime(true) - $started);
    }

    public function testWithATerminalTheTimeoutKeepsTheGroupOfTheTerminal(): void
    {
        self::assertStringContainsString('timeout --foreground', SshRunner::script(['sleep', '1'], null, 5, true));
        self::assertStringNotContainsString('--foreground', SshRunner::script(['sleep', '1'], null, 5, false));
    }

    public function testAnEmptyCommandAndANulByteAreRefused(): void
    {
        foreach ([[], [''], ['echo', "a\0b"]] as $argv) {
            try {
                SshRunner::script($argv, null, 5);
                self::fail('accepted ' . json_encode($argv));
            } catch (PhycodeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAScriptTooLongForTheCommandLineIsRefused(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('longer than 64 KB');
        SshRunner::remoteCommand(['bash', '-c', str_repeat('echo hello; ', 8000)], null, 5);
    }

    public function testTheDestinationAlwaysComesAfterTheEndOfTheOptions(): void
    {
        $host = SshHost::fromArray('prod', ['destination' => 'deploy@h', 'port' => 2200]);
        $runner = new SshRunner($host, new ProcessRunner(), '/tmp/x');

        $options = $runner->options(false);
        self::assertSame('ssh', $options[0]);
        self::assertContains('-p', $options);
        self::assertContains('2200', $options);
        self::assertNotContains('deploy@h', $options, 'the destination is added by the caller after "--"');
        self::assertContains('ControlPath=/tmp/x/%C', $options);

        $copy = $runner->copyCommand(true, '/p/a.txt', '/srv/a.txt');
        self::assertSame('scp', $copy[0]);
        self::assertContains('-P', $copy);
        self::assertNotContains('-T', $copy);
        self::assertSame(['--', '/p/a.txt', 'deploy@h:/srv/a.txt'], array_slice($copy, -3));
        self::assertSame(['--', 'deploy@h:/srv/a.txt', '/p/a.txt'], array_slice($runner->copyCommand(false, '/p/a.txt', '/srv/a.txt'), -3));
        $v6 = new SshRunner(SshHost::fromArray('v6', ['destination' => 'u@2001:db8::1']), new ProcessRunner(), '/tmp/x');
        self::assertSame('u@[2001:db8::1]:/f', $v6->copyCommand(true, '/a', '/f')[array_key_last($v6->copyCommand(true, '/a', '/f'))]);
    }
}
