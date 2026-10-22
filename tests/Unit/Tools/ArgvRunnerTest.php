<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Tests\Support\Kit;
use Phycode\Tools\ArgvRunner;
use PHPUnit\Framework\TestCase;

final class ArgvRunnerTest extends TestCase
{
    public function testATimeoutAlsoStopsWhatLeftTheSessionAndTheProcessTree(): void
    {
        $escape = '(' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('posix_setsid(); passthru("sleep 98.5");') . ' &); sleep 30';

        $result = ArgvRunner::run(['bash', '-c', $escape], sys_get_temp_dir(), 2, 4096);

        self::assertSame('timeout', $result->interrupted);
        for ($attempt = 0; $attempt < 100 && Kit::sleeping('98.5'); $attempt++) {
            usleep(50000);
        }
        self::assertFalse(Kit::sleeping('98.5'), 'the sleep in its own session is stopped with the command');
    }
}
