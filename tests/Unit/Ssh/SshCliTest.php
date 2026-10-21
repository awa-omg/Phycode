<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ssh;

use Phycode\Ssh\SshCli;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\SshServer;
use PHPUnit\Framework\TestCase;

final class SshCliTest extends TestCase
{
    private string $config;

    protected function setUp(): void
    {
        $this->config = Kit::folder('ssh-cli') . '/phycode/config.json';
    }

    private function cli(array $args, string $input = '', bool $interactive = false): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $in = fopen('php://memory', 'w+');
        fwrite($in, $input);
        rewind($in);

        $code = SshCli::run($args, $this->config, $out, $err, $in, $interactive);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private function server(): SshServer
    {
        return SshRunnerTest::server() ?? self::markTestSkipped('no sshd that this user can run') ?? throw new \LogicException();
    }

    public function testWithoutHostsListSaysHowToAddOne(): void
    {
        [$code, $out] = $this->cli([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('No ssh hosts. Add one: phycode ssh add NAME user@host', $out);
    }

    public function testAddWritesTheHostToTheConfigAndKeepsTheRestOfTheFile(): void
    {
        mkdir(dirname($this->config), 0700, true);
        file_put_contents($this->config, json_encode(['default_profile' => 'p', 'profiles' => ['p' => ['base_url' => 'http://127.0.0.1:1', 'model' => 'm']]], JSON_PRETTY_PRINT));

        [$code, $out] = $this->cli(['add', 'prod', 'deploy@203.0.113.10', '--port', '2222', '--cwd', '/srv/app', '--confirm', 'rules']);

        self::assertSame(0, $code);
        self::assertStringContainsString('phycode ssh trust prod', $out);
        $data = json_decode((string) file_get_contents($this->config), true);
        self::assertSame(['destination' => 'deploy@203.0.113.10', 'port' => 2222, 'cwd' => '/srv/app', 'confirm' => 'rules'], $data['ssh']['hosts']['prod']);
        self::assertSame('p', $data['default_profile']);
        self::assertFileExists($this->config . '.bak');
    }

    public function testAddCreatesTheConfigFileWhenThereIsNone(): void
    {
        [$code, $out] = $this->cli(['add', 'prod', 'h']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Every command on prod will ask for your approval', $out);
        self::assertSame(0600, fileperms($this->config) & 0777);
        self::assertSame(0700, fileperms(dirname($this->config)) & 0777);
        self::assertSame(['destination' => 'h'], json_decode((string) file_get_contents($this->config), true)['ssh']['hosts']['prod']);
    }

    public function testAddRefusesWhatSshCouldTakeForAnOptionAndWhatWasAddedBefore(): void
    {
        self::assertSame(0, $this->cli(['add', 'prod', 'h'])[0]);
        $before = (string) file_get_contents($this->config);

        foreach ([
            [['add', 'evil', '-oProxyCommand=touch /tmp/x'], 'destination'],
            [['add', 'prod', 'other'], 'already exists'],
            [['add', 'Bad Name', 'h'], 'name'],
            [['add', 'x', 'h', '--port', 'abc'], 'port'],
            [['add', 'x', 'h', '--confirm', 'never'], 'confirm'],
            [['add', 'x', 'h', '--proxy', 'y'], 'unknown option --proxy'],
            [['add', 'x', 'h', '--port'], 'needs a value'],
            [['add', 'x'], 'a name and a destination'],
        ] as [$args, $message]) {
            [$code, , $err] = $this->cli($args);

            self::assertSame(1, $code, implode(' ', $args));
            self::assertStringContainsString($message, $err, implode(' ', $args));
        }
        self::assertSame($before, (string) file_get_contents($this->config), 'a refused add changes nothing');
    }

    public function testRemoveTakesAHostOut(): void
    {
        $this->cli(['add', 'prod', 'h']);
        $this->cli(['add', 'stage', 'h2']);

        self::assertSame(0, $this->cli(['remove', 'prod'])[0]);

        $hosts = json_decode((string) file_get_contents($this->config), true)['ssh']['hosts'];
        self::assertSame(['stage'], array_keys($hosts));
        [$code, , $err] = $this->cli(['remove', 'prod']);
        self::assertSame(1, $code);
        self::assertStringContainsString("no ssh host named 'prod'", $err);
    }

    public function testAnUnknownCommandShowsTheUsage(): void
    {
        [$code, , $err] = $this->cli(['frobnicate']);

        self::assertSame(1, $code);
        self::assertStringContainsString("unknown ssh command 'frobnicate'", $err);
        self::assertStringContainsString('phycode ssh trust NAME', $err);
    }

    public function testListShowsWhetherTheKeyIsTrusted(): void
    {
        $server = $this->server();
        $this->cli(['add', 'known', '127.0.0.1', '--port', (string) $server->port, '--known-hosts', $server->knownHosts()]);
        $this->cli(['add', 'unknown', '127.0.0.1', '--port', (string) $server->port, '--known-hosts', $server->emptyKnownHosts()]);
        $this->cli(['add', 'lax', '127.0.0.1', '--host-key', 'accept-new', '--known-hosts', $server->emptyKnownHosts()]);

        [$code, $out] = $this->cli(['list']);

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/^NAME\s+DESTINATION\s+PORT\s+CWD\s+CONFIRM\s+HOST KEY$/m', $out);
        self::assertMatchesRegularExpression('/^known\s.*always\s+trusted$/m', $out);
        self::assertMatchesRegularExpression('/^unknown\s.*always\s+not trusted: phycode ssh trust unknown$/m', $out);
        self::assertMatchesRegularExpression('/^lax\s.*accept-new$/m', $out);
    }

    public function testTrustShowsTheFingerprintAndAddsTheKeyOnlyOnAYes(): void
    {
        $server = $this->server();
        $file = Kit::folder('ssh-trust') . '/known_hosts';
        $this->cli(['add', 'box', '127.0.0.1', '--port', (string) $server->port, '--known-hosts', $file]);

        [$code, $out] = $this->cli(['trust', 'box'], "n\n", true);
        self::assertSame(1, $code);
        self::assertStringContainsString('SHA256:', $out);
        self::assertStringContainsString('(ED25519)', $out);
        self::assertStringContainsString('Nothing was added.', $out);
        self::assertFileDoesNotExist($file);

        [$code, $out] = $this->cli(['trust', 'box'], "y\n", true);
        self::assertSame(0, $code);
        self::assertStringContainsString("Added to {$file}", $out);
        self::assertStringContainsString("[127.0.0.1]:{$server->port} ", (string) file_get_contents($file));
        self::assertSame(0600, fileperms($file) & 0777);

        [$code, $out] = $this->cli(['trust', 'box']);
        self::assertSame(0, $code);
        self::assertStringContainsString('is in', $out);
        self::assertSame(1, substr_count((string) file_get_contents($file), "[127.0.0.1]:{$server->port} ssh-ed25519"), 'a trusted host is not added twice');
    }

    public function testTrustWithoutATerminalNeedsYes(): void
    {
        $server = $this->server();
        $file = Kit::folder('ssh-trust2') . '/known_hosts';
        $this->cli(['add', 'box', '127.0.0.1', '--port', (string) $server->port, '--known-hosts', $file]);

        [$code, , $err] = $this->cli(['trust', 'box']);
        self::assertSame(1, $code);
        self::assertStringContainsString('run again with --yes', $err);
        self::assertFileDoesNotExist($file);

        self::assertSame(0, $this->cli(['trust', 'box', '--yes'])[0]);
        self::assertFileExists($file);
    }

    public function testTrustReportsAHostThatDoesNotAnswer(): void
    {
        $file = Kit::folder('ssh-trust3') . '/known_hosts';
        $this->cli(['add', 'gone', '127.0.0.1', '--port', '1', '--known-hosts', $file]);

        [$code, , $err] = $this->cli(['trust', 'gone', '--yes']);

        self::assertSame(1, $code);
        self::assertStringContainsString('gave no host key', $err);
    }

    public function testTestRunsACommandOnTheHostAfterTheKeyIsTrusted(): void
    {
        $server = $this->server();
        $this->cli(['add', 'box', '127.0.0.1', '--port', (string) $server->port, '--known-hosts', $server->knownHosts(), '--identity', $server->host()->identityFile ?? '', '--config-file', $server->host()->configFile ?? '', '--cwd', '/tmp']);
        $config = json_decode((string) file_get_contents($this->config), true);
        $config['ssh']['hosts']['box']['destination'] = $server->user() . '@127.0.0.1';
        file_put_contents($this->config, json_encode($config));

        [$code, $out] = $this->cli(['test', 'box']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('box: ok in', $out);
        self::assertStringContainsString($server->user() . '@', $out);
        self::assertStringContainsString("\n/tmp\n", $out);
    }

    public function testTestExplainsAFailureOfSshItself(): void
    {
        $server = $this->server();
        $this->cli(['add', 'box', $server->user() . '@127.0.0.1', '--port', (string) $server->port, '--known-hosts', $server->emptyKnownHosts(), '--config-file', $server->host()->configFile ?? '']);

        [$code, , $err] = $this->cli(['test', 'box']);

        self::assertSame(1, $code);
        self::assertStringContainsString('box: failed with exit code 255', $err);
        self::assertStringContainsString('phycode ssh trust box', $err);
    }

    public function testThePhycodeCommandPassesItsOwnOptionsToSsh(): void
    {
        $home = Kit::folder('ssh-bin');
        $env = ['PATH' => (string) getenv('PATH'), 'HOME' => $home, 'XDG_CONFIG_HOME' => $home . '/cfg'];

        $run = static function (array $args) use ($env): array {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/bin/phycode', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);

            return [proc_close($process), (string) $out, (string) $err];
        };

        [$code, $out] = $run(['ssh', 'add', 'prod', 'deploy@h', '--port', '2200', '--cwd', '/srv']);
        self::assertSame(0, $code, $out);
        [$code, $out] = $run(['ssh', 'list']);
        self::assertSame(0, $code);
        self::assertStringContainsString('deploy@h', $out);
        self::assertStringContainsString('2200', $out);
        [$code, , $err] = $run(['ssh', '--bogus']);
        self::assertSame(1, $code);
        self::assertStringContainsString("unknown ssh command '--bogus'", $err);
        [$code, , $err] = $run(['run', '--bogus', 'x']);
        self::assertStringContainsString('unknown option --bogus', $err, 'other commands still refuse unknown options');
    }
}
