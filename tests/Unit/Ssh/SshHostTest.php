<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ssh;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Ssh\SshHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SshHostTest extends TestCase
{
    public function testAHostTakesItsSettingsAndTheSafeDefaults(): void
    {
        $host = SshHost::fromArray('prod', ['destination' => 'deploy@203.0.113.10', 'port' => 2222, 'cwd' => '/srv/app', 'identity_file' => '/keys/id']);

        self::assertSame('deploy@203.0.113.10', $host->destination);
        self::assertSame(2222, $host->port);
        self::assertSame('/srv/app', $host->cwd);
        self::assertSame('always', $host->confirm, 'every call asks unless the person says otherwise');
        self::assertSame('strict', $host->hostKey, 'a host key must be known unless the person says otherwise');
        self::assertSame('203.0.113.10', $host->hostname());
    }

    public function testTheHomeFolderIsOpenedInPathsOfThisMachineOnly(): void
    {
        $home = (string) getenv('HOME');
        $host = SshHost::fromArray('prod', ['destination' => 'h', 'identity_file' => '~/.ssh/id', 'cwd' => '~/app']);

        self::assertSame($home . '/.ssh/id', $host->identityFile);
        self::assertSame('~/app', $host->cwd, 'the directory on the host is opened by the host');
    }

    public static function destinations(): iterable
    {
        yield 'an option' => ['-oProxyCommand=touch /tmp/x'];
        yield 'an option with a user' => ['-l@host'];
        yield 'a dash first, short' => ['-p'];
        yield 'a space' => ['host name'];
        yield 'a semicolon' => ['host;id'];
        yield 'a backtick' => ['host`id`'];
        yield 'a dollar' => ['$(id)@host'];
        yield 'a newline' => ["host\nid"];
        yield 'an empty string' => [''];
        yield 'an empty user' => ['@host'];
        yield 'two at signs' => ['a@b@c'];
        yield 'a slash' => ['host/../x'];
        yield 'a quote' => ["ho'st"];
        yield 'a very long name' => [str_repeat('a', 300)];
    }

    #[DataProvider('destinations')]
    public function testADestinationThatSshCouldReadAsAnOptionOrAShellWordIsRefused(string $destination): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('destination');
        SshHost::fromArray('prod', ['destination' => $destination]);
    }

    public static function goodDestinations(): iterable
    {
        yield 'a name' => ['server'];
        yield 'a domain' => ['deploy@app.example.com'];
        yield 'an address' => ['root@192.0.2.7'];
        yield 'an ipv6 address' => ['root@2001:db8::1'];
        yield 'an ipv6 address in brackets' => ['[2001:db8::1]'];
        yield 'an underscore user' => ['_svc@host-1'];
    }

    #[DataProvider('goodDestinations')]
    public function testOrdinaryDestinationsAreAccepted(string $destination): void
    {
        self::assertSame($destination, SshHost::fromArray('prod', ['destination' => $destination])->destination);
    }

    public function testNamesSettingsAndValuesAreChecked(): void
    {
        foreach ([
            ['Prod', ['destination' => 'h'], 'name'],
            ['-prod', ['destination' => 'h'], 'name'],
            ['prod', [], 'destination'],
            ['prod', ['destination' => 'h', 'port' => 0], 'port'],
            ['prod', ['destination' => 'h', 'port' => '22'], 'port'],
            ['prod', ['destination' => 'h', 'cwd' => 'srv'], 'cwd'],
            ['prod', ['destination' => 'h', 'cwd' => "/srv\n/x"], 'cwd'],
            ['prod', ['destination' => 'h', 'confirm' => 'never'], 'confirm'],
            ['prod', ['destination' => 'h', 'host_key' => 'no'], 'host_key'],
            ['prod', ['destination' => 'h', 'identity_file' => 'key'], 'identity_file'],
            ['prod', ['destination' => 'h', 'proxy_command' => 'x'], 'proxy_command'],
        ] as [$name, $raw, $word]) {
            try {
                SshHost::fromArray($name, $raw);
                self::fail('accepted: ' . json_encode([$name, $raw]));
            } catch (ConfigException $error) {
                self::assertStringContainsString($word, $error->getMessage());
            }
        }
    }

    public function testToArrayKeepsOnlyWhatDiffersFromTheDefaults(): void
    {
        $host = SshHost::fromArray('prod', ['destination' => 'h', 'cwd' => '/srv', 'confirm' => 'rules']);

        self::assertSame(['destination' => 'h', 'cwd' => '/srv', 'confirm' => 'rules'], $host->toArray());
        self::assertSame(['destination' => 'h'], SshHost::fromArray('x', ['destination' => 'h'])->toArray());
    }

    public function testTheConfigReadsTheHostsOfTheGlobalFileOnly(): void
    {
        $profiles = ['profiles' => ['p' => ['base_url' => 'http://127.0.0.1:1', 'model' => 'm']]];
        $global = $profiles + ['ssh' => ['hosts' => ['prod' => ['destination' => 'deploy@h', 'cwd' => '/srv/app']]]];

        $config = Config::fromArrays($global, ['ssh' => ['hosts' => ['evil' => ['destination' => 'attacker@h']]]], [], '/nonexistent');

        self::assertSame(['prod'], array_keys($config->sshHosts()));
        self::assertContains("project config key 'ssh' ignored: only the global config can set it", $config->warnings);
        self::assertSame([], Config::fromArrays($profiles, null, [], '/nonexistent')->sshHosts());
    }

    public function testABadSshSectionIsAnErrorThatNamesTheSetting(): void
    {
        $profiles = ['profiles' => ['p' => ['base_url' => 'http://127.0.0.1:1', 'model' => 'm']]];

        foreach ([
            [['ssh' => ['hosts' => ['prod' => ['destination' => '-oFoo=bar']]]], 'ssh.hosts.prod.destination'],
            [['ssh' => ['servers' => []]], 'ssh.servers'],
            [['ssh' => ['hosts' => ['prod' => 'host']]], 'ssh.hosts.prod'],
            [['ssh' => ['hosts' => ['prod' => ['destination' => 'h', 'extra' => 1]]]], 'ssh.hosts.prod.extra'],
        ] as [$section, $label]) {
            try {
                Config::fromArrays($profiles + $section, null, [], '/nonexistent');
                self::fail('accepted: ' . json_encode($section));
            } catch (ConfigException $error) {
                self::assertStringContainsString($label, $error->getMessage());
            }
        }
    }
}
