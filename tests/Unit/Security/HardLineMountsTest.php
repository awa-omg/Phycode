<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\HardLine;
use PHPUnit\Framework\TestCase;

final class HardLineMountsTest extends TestCase
{
    public function testTheMountsOfFreeBsdAreRead(): void
    {
        $lines = [
            '/dev/gpt/rootfs on / (ufs, local, noatime, soft-updates)',
            'devfs on /dev (devfs)',
            '/dev/gpt/efiboot0 on /boot/efi (msdosfs, local)',
        ];

        self::assertSame(['/', '/dev', '/boot/efi'], HardLine::mountsFrom($lines));
    }

    public function testTheMountsOfMacOsAreRead(): void
    {
        $lines = [
            '/dev/disk3s1s1 on / (apfs, sealed, local, read-only, journaled)',
            '/dev/disk3s5 on /System/Volumes/Data (apfs, local, journaled, nobrowse)',
            'map auto_home on /System/Volumes/Data/home (autofs, automounted, nobrowse)',
            '/dev/disk4s1 on /Volumes/My Disk (copy) (apfs, local, nodev, nosuid, journaled)',
        ];

        self::assertSame(['/', '/System/Volumes/Data', '/System/Volumes/Data/home', '/Volumes/My Disk (copy)'], HardLine::mountsFrom($lines));
    }

    public function testALineThatIsNotAMountIsIgnored(): void
    {
        self::assertSame([], HardLine::mountsFrom(['', 'not a mount line', 'sh: mount: not found']));
    }
}
