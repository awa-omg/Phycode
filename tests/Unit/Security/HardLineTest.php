<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\HardLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HardLineTest extends TestCase
{
    public static function refused(): iterable
    {
        yield 'rm -rf of the root' => ['rm -rf /'];
        yield 'rm -rf of everything in the root' => ['rm -rf /*'];
        yield 'rm with the flags apart' => ['rm -r -f /'];
        yield 'rm --recursive' => ['rm --recursive --force /'];
        yield 'rm -fr' => ['rm -fr /'];
        yield 'rm of the root with extra slashes' => ['rm -rf //'];
        yield 'rm -rf of the home folder' => ['rm -rf ~'];
        yield 'rm -rf of the contents of the home folder' => ['rm -rf ~/*'];
        yield 'rm -rf of $HOME' => ['rm -rf $HOME'];
        yield 'rm -rf of ${HOME}/' => ['rm -rf ${HOME}/'];
        yield 'rm -rf of /home' => ['rm -rf /home'];
        yield 'rm -rf of the real home' => ['rm -rf ' . '%HOME%'];
        yield 'rm --no-preserve-root' => ['rm --no-preserve-root -rf /tmp/x'];
        yield 'after another command' => ['cd /tmp && rm -rf /'];
        yield 'behind sudo' => ['sudo rm -rf /'];
        yield 'behind a path to the program' => ['/bin/rm -rf /'];
        yield 'inside a substitution' => ['echo $(rm -rf /)'];
        yield 'inside sh -c' => ['sh -c "rm -rf /"'];
        yield 'quoted target' => ['rm -rf "/"'];
        yield 'mkfs' => ['mkfs /dev/sda1'];
        yield 'mkfs.ext4' => ['sudo mkfs.ext4 /dev/sda1'];
        yield 'dd onto a disk' => ['dd if=/dev/zero of=/dev/sda bs=1M'];
        yield 'dd onto an nvme disk' => ['dd if=x of=/dev/nvme0n1'];
        yield 'a redirect onto a disk' => ['cat x > /dev/sda'];
        yield 'chmod -R of the root' => ['chmod -R 777 /'];
        yield 'chown -R of the root' => ['chown -R me /'];
        yield 'the fork bomb' => [':(){ :|:& };:'];
        yield 'the fork bomb with spaces' => [':() { : | : & } ; :'];
    }

    #[DataProvider('refused')]
    public function testACommandThatDestroysTheMachineIsRefused(string $command): void
    {
        if (str_contains($command, '%HOME%') && (string) getenv('HOME') === '') {
            self::markTestSkipped('HOME is not set');
        }
        $command = str_replace('%HOME%', (string) getenv('HOME'), $command);

        self::assertNotNull(HardLine::reason('bash: ' . $command), $command);
    }

    public static function allowed(): iterable
    {
        yield 'rm -rf of a folder in the project' => ['rm -rf build'];
        yield 'rm -rf of everything in the current folder' => ['rm -rf *'];
        yield 'rm -rf of a path under the root' => ['rm -rf /tmp/build'];
        yield 'rm -rf of a path under the home folder' => ['rm -rf ~/project/build'];
        yield 'rm of the root without -r' => ['rm /'];
        yield 'rm -rf of the contents of a folder' => ['rm -rf /tmp/build/*'];
        yield 'ls of the root' => ['ls -la /'];
        yield 'chmod -R of the project' => ['chmod -R 755 build'];
        yield 'chmod of a file in the root folder' => ['chmod 644 /etc/hosts'];
        yield 'mkfs mentioned in text' => ['echo mkfs /dev/sda1'];
        yield 'dd to a file' => ['dd if=/dev/zero of=disk.img bs=1M count=10'];
        yield 'a redirect onto a file' => ['echo x > /tmp/sda'];
        yield 'a command that only names rm' => ['git log --grep "rm -rf /"'];
        yield 'a here-document that holds the text' => ["cat <<'EOF'\nrm -rf /\nEOF"];
    }

    #[DataProvider('allowed')]
    public function testOrdinaryCommandsAreNotOnTheLine(string $command): void
    {
        self::assertNull(HardLine::reason('bash: ' . $command), $command);
    }

    public function testASummaryWithoutACommandIsNotOnTheLine(): void
    {
        self::assertNull(HardLine::reason('bash'));
        self::assertNull(HardLine::reason('custom__wipe: ./wipe.sh {"path":"build"}'));
    }

    public static function shapes(): iterable
    {
        yield 'a top-level folder that is in no list' => ['rm -rf /mnt'];
        yield 'the removable media root' => ['rm -rf /media'];
        yield 'any other top-level folder' => ['rm -rf /data'];
        yield 'a top-level folder named by a glob' => ['rm -rf /m?t'];
        yield 'a neighbour home under the home root' => ['rm -rf /home/neighbour'];
        yield 'every home under the home root' => ['rm -rf /home/*/'];
        yield 'a neighbour home by tilde' => ['rm -rf ~neighbour'];
        yield 'the parent of the home folder' => ['rm -rf ~/..'];
        yield 'the root reached through dot-dot' => ['rm -rf /usr/..'];
        yield 'two globs under the root' => ['rm -rf /*/*'];
        yield 'chown -R of a neighbour home' => ['chown -R me /home/neighbour'];
        yield 'find -delete of a neighbour home' => ['find /home/neighbour -delete'];
        yield 'the home of a system account' => ['rm -rf ~root'];
    }

    #[DataProvider('shapes')]
    public function testTheTargetIsJudgedByItsShapeNotByAList(string $command): void
    {
        self::assertNotNull(HardLine::reason('bash: ' . $command), $command);
    }

    public function testARelativeTargetIsResolvedFromTheProjectFolder(): void
    {
        $home = (string) getenv('HOME');
        if (!str_starts_with($home, '/') || $home === '/') {
            self::markTestSkipped('HOME is not a folder below the root');
        }

        self::assertNotNull(HardLine::reason('bash: rm -rf ..', $home . '/project'));
        self::assertNotNull(HardLine::reason('bash: rm -rf .', $home));
        self::assertNotNull(HardLine::reason('bash: rm -rf *', $home));
        self::assertNotNull(HardLine::reason('bash: find . | xargs rm -rf', $home));
        self::assertNull(HardLine::reason('bash: rm -rf build', $home . '/project'));
        self::assertNull(HardLine::reason('bash: rm -rf *', $home . '/project'));
        self::assertNull(HardLine::reason('bash: rm -rf ../other/build', $home . '/project'));
    }

    public function testAMountPointAndTheFoldersAboveItAreGuarded(): void
    {
        $point = null;
        foreach (file('/proc/self/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $candidate = explode(' ', $line)[1] ?? '';
            if (substr_count($candidate, '/') >= 3 && !str_contains($candidate, '\\')) {
                $point = $candidate;
                break;
            }
        }
        if ($point === null) {
            self::markTestSkipped('no mount point three levels deep');
        }

        self::assertNotNull(HardLine::reason('bash: rm -rf ' . $point), $point);
        self::assertNotNull(HardLine::reason('bash: rm -rf ' . dirname($point)), dirname($point));
    }
}
