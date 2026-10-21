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

        self::assertNotNull(HardLine::reason('bash', 'bash: ' . $command), $command);
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
        self::assertNull(HardLine::reason('bash', 'bash: ' . $command), $command);
    }

    public function testOtherToolsAreNotLookedAt(): void
    {
        self::assertNull(HardLine::reason('write', 'write rm -rf /: x'));
        self::assertNull(HardLine::reason('bash', 'bash'));
    }
}
