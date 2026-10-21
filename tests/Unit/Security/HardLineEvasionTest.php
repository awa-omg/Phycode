<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\HardLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HardLineEvasionTest extends TestCase
{
    public static function refused(): iterable
    {
        yield 'time -p in front' => ['time -p rm -rf /'];
        yield 'brace expansion of the whole command' => ['{rm,-rf,/}'];
        yield 'brace expansion of a target' => ['rm -rf {/,/tmp}'];
        yield 'brace expansion with a prefix' => ['rm -rf /{usr,etc}'];
        yield 'an ANSI-C quoted program' => ["\$'rm' -rf /"];
        yield 'a double-quoted program' => ['"rm" -rf /'];
        yield 'a parameter inside the program' => ['r${X}m -rf /'];
        yield 'a substitution as the program' => ['$(echo rm) -rf /'];
        yield 'a glob as the program' => ['r? -rf /'];
        yield 'xargs fed by a root listing' => ['echo / | xargs rm -rf'];
        yield 'xargs with a placeholder after a root listing' => ['ls / | xargs -I{} rm -rf {}'];
        yield 'strace in front' => ['strace -q rm -rf /'];
        yield 'setpriv in front' => ['setpriv --reuid=0 rm -rf /'];
        yield 'script -c' => ["script -qc 'rm -rf /' /dev/null"];
        yield 'busybox applet' => ['busybox rm -rf /'];
        yield 'taskset in front' => ['taskset -c 0 rm -rf /'];
        yield 'chroot in front' => ['chroot /mnt rm -rf /'];
        yield 'rm -rf of /usr' => ['rm -rf /usr'];
        yield 'rm -rf of the contents of /etc' => ['rm -rf /etc/*'];
        yield 'chmod -R of /usr' => ['chmod -R 777 /usr'];
        yield 'find -delete of the root' => ['find / -delete'];
        yield 'wipefs on a disk' => ['wipefs -a /dev/sda'];
        yield 'shred on an nvme disk' => ['shred -n 1 /dev/nvme0n1'];
        yield 'busybox mkfs' => ['busybox mkfs.ext4 /dev/sda1'];
    }

    #[DataProvider('refused')]
    public function testAHiddenDestructiveCommandIsRefused(string $command): void
    {
        self::assertNotNull(HardLine::reason('bash: ' . $command), $command);
    }

    public static function allowed(): iterable
    {
        yield 'a recursive delete fed by a list of the project' => ['cat list.txt | xargs rm -rf build'];
        yield 'a non-recursive delete fed by find' => ['find . -name "*.log" | xargs rm -f'];
        yield 'a brace list of project folders' => ['rm -rf {build,dist}'];
        yield 'a parameter that is only read' => ['echo ${HOME}'];
        yield 'a brace list that is only printed' => ['ls {a,b}'];
        yield 'a program named by a substitution with no destructive words' => ['$(which python3) script.py'];
        yield 'time in front of a build' => ['time -p make test'];
        yield 'strace of a read-only command' => ['strace -q ls'];
        yield 'a read-only listing of a disk' => ['fdisk -l /dev/sda'];
        yield 'the quoted text of a rm command in an argument' => ['git log --grep "rm -rf /"'];
        yield 'a recursive delete of a project subfolder' => ['rm -rf ./build/cache'];
    }

    #[DataProvider('allowed')]
    public function testAnOrdinaryCommandIsNotOnTheLine(string $command): void
    {
        self::assertNull(HardLine::reason('bash: ' . $command), $command);
    }
}
