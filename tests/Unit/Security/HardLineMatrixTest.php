<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\HardLine;
use PHPUnit\Framework\TestCase;

final class HardLineMatrixTest extends TestCase
{
    private const COMMANDS = [
        'rm -rf %s',
        'rm -r -f %s',
        'rm -fr %s',
        'rm --recursive --force %s',
        'rm -rf -- %s',
        'chmod -R 777 %s',
        'chown -R nobody %s',
        'chgrp -R nogroup %s',
        'find %s -delete',
        'find %s -type f -exec rm -f {} +',
    ];

    private const WRAPPERS = [
        '%s',
        'sudo %s',
        'sudo -u root -- %s',
        'env FOO=1 %s',
        'nice -n 5 %s',
        'nohup %s',
        'timeout 5 %s',
        'command %s',
        'exec %s',
        'time %s',
        'doas %s',
        'ionice -c3 %s',
    ];

    private const SHELLS = [
        '%s',
        'sh -c "%s"',
        "bash -c '%s'",
        '(%s)',
        '{ %s; }',
        'if true; then %s; fi',
        'echo start && %s',
        'false || %s',
        'echo x | %s',
        'for i in 1; do %s; done',
        '%s &',
        'eval "%s"',
    ];

    private const DANGEROUS = ['/', '/*', '/.', '//', '~', '~/', '~/*', '$HOME', '${HOME}', '"$HOME"/', '/home', '/usr', '/etc/*', '/var', '/mnt', '/media/', '/opt', '/srv'];

    private const SAFE = ['build', './build', 'build/*', '/tmp/phycode-matrix-build', '/tmp/phycode-matrix/*', '$HOME/phycode-matrix-project/build', '/srv/phycode-matrix-project', '/opt/phycode-matrix-app/cache'];

    private static function combinations(array $targets): iterable
    {
        foreach (self::COMMANDS as $command) {
            foreach ($targets as $target) {
                foreach (self::WRAPPERS as $wrapper) {
                    foreach (self::SHELLS as $shell) {
                        yield sprintf($shell, sprintf($wrapper, sprintf($command, $target)));
                    }
                }
            }
        }
    }

    public function testEveryWayToWrapADestructiveCommandIsRefused(): void
    {
        $missed = [];
        $total = 0;
        foreach (self::combinations(self::DANGEROUS) as $command) {
            $total++;
            if (HardLine::reason('bash: ' . $command, sys_get_temp_dir()) === null) {
                $missed[] = $command;
            }
        }

        self::assertGreaterThan(20000, $total);
        self::assertSame([], array_slice($missed, 0, 25), count($missed) . ' of ' . $total . ' were not refused');
    }

    public function testTheSameWrappersAroundAProjectFolderAreNotRefused(): void
    {
        $refused = [];
        $total = 0;
        foreach (self::combinations(self::SAFE) as $command) {
            $total++;
            if (HardLine::reason('bash: ' . $command, sys_get_temp_dir() . '/phycode-matrix-project') !== null) {
                $refused[] = $command;
            }
        }

        self::assertGreaterThan(8000, $total);
        self::assertSame([], array_slice($refused, 0, 25), count($refused) . ' of ' . $total . ' were refused');
    }
}
