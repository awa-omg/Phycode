<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\HardLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HardLineRemoteTest extends TestCase
{
    private const OUTPUT = <<<'OUT'
        @HOME /export/home/bob
        @PATH /opt/tools/bin:/usr/bin:/bin
        @OS Linux
        @PASSWD root:x:0:0:root:/root:/bin/sh
        @PASSWD bob:x:1500:1500:Bob:/export/home/bob:/bin/sh
        @PASSWD carol:x:1501:1501:Carol:/export/home/carol:/bin/sh
        @DEFS UID_MIN 1000
        @DEFS UID_MAX 60000
        @MOUNT /
        @MOUNT /data/disk1
        @MOUNT /var/lib/docker/overlay\040x
        @TOP /export
        @TOP /data
        @TOP /lib
        @REAL /lib	/usr/lib
        @REAL /export/home/bob	/export/home/bob
        @END
        OUT;

    private static function facts(): array
    {
        $facts = HardLine::remoteFacts(self::OUTPUT);
        self::assertNotNull($facts);

        return $facts;
    }

    public static function refused(): iterable
    {
        yield 'the remote home' => ['rm -rf ~', '/export/home/bob/app'];
        yield 'a neighbour home on the remote host' => ['rm -rf /export/home/carol', '/export/home/bob/app'];
        yield 'a neighbour by tilde' => ['rm -rf ~carol', '/export/home/bob/app'];
        yield 'the home root of the remote host' => ['rm -rf /export/home', '/export/home/bob/app'];
        yield 'a mount point of the remote host' => ['rm -rf /data/disk1', '/export/home/bob/app'];
        yield 'a mount point with an escaped space' => ['rm -rf "/var/lib/docker/overlay x"', '/export/home/bob/app'];
        yield 'a folder on the remote PATH' => ['rm -rf /opt/tools/bin', '/export/home/bob/app'];
        yield 'the parent of the remote folder' => ['rm -rf ../..', '/export/home/bob/app'];
        yield 'every home by glob' => ['rm -rf /export/home/*', '/export/home/bob/app'];
    }

    #[DataProvider('refused')]
    public function testACommandIsJudgedWithTheFactsOfTheRemoteHost(string $command, string $cwd): void
    {
        self::assertNotNull(HardLine::reason('ssh box: ' . $command, $cwd, self::facts()), $command);
    }

    public function testThePathsOfThisMachineDoNotDecideForTheRemoteHost(): void
    {
        self::assertNull(HardLine::reason('ssh box: rm -rf /export/home/carol', '/tmp'), 'this machine has no such home');
        self::assertNull(HardLine::reason('ssh box: rm -rf /export/home/bob/app/build', '/export/home/bob/app', self::facts()));
        self::assertNull(HardLine::reason('ssh box: rm -rf build', '/export/home/bob/app', self::facts()));
        self::assertNull(HardLine::reason('ssh box: rm -rf /data/disk1/cache/old', '/export/home/bob/app', self::facts()));
    }

    public function testAnOutputThatIsCutShortGivesNoFacts(): void
    {
        self::assertNull(HardLine::remoteFacts("@HOME /export/home/bob\n@PATH /usr/bin\n"));
        self::assertNull(HardLine::remoteFacts("@HOME relative\n@END\n"));
    }

    public function testTheScriptReadsTheFactsOfThisMachineLikeTheLocalCheck(): void
    {
        $facts = HardLine::remoteFacts((string) shell_exec('sh -c ' . escapeshellarg(HardLine::FACTS_SCRIPT)));
        self::assertNotNull($facts);
        $home = (string) getenv('HOME');

        foreach (['rm -rf /', 'rm -rf /usr', 'rm -rf /home/*', 'rm -rf ~', 'find /etc -delete', 'rm -rf ~/phycode-remote-project/build', 'rm -rf /srv/phycode-remote-project'] as $command) {
            self::assertSame(HardLine::reason('bash: ' . $command, $home) === null, HardLine::reason('ssh here: ' . $command, $home, $facts) === null, $command);
        }
    }
}
