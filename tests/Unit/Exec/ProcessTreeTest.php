<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Exec;

use Phycode\Exec\ProcessTree;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class ProcessTreeTest extends TestCase
{
    private $process = null;

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }
    }

    private function start(string $script): int
    {
        $this->process = proc_open(['sh', '-c', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $pid = (int) proc_get_status($this->process)['pid'];
        for ($attempt = 0; $attempt < 100 && ProcessTree::descendants($pid) === []; $attempt++) {
            usleep(50000);
        }

        return $pid;
    }

    private function eventually(callable $check): bool
    {
        for ($attempt = 0; $attempt < 100 && !$check(); $attempt++) {
            usleep(50000);
        }

        return $check();
    }

    public function testTheDescendantsOfAProcessAreFoundAtEveryDepth(): void
    {
        $pid = $this->start('sh -c "sleep 91.25 & wait" & wait');
        self::assertTrue($this->eventually(static fn (): bool => Kit::sleeping('91.25')));

        self::assertGreaterThanOrEqual(2, count(ProcessTree::descendants($pid)));
        ProcessTree::signal($pid, 9);
        self::assertTrue($this->eventually(static fn (): bool => !Kit::sleeping('91.25')));
    }

    public function testAProcessThatLeadsNoGroupIsStoppedTogetherWithWhatItStarted(): void
    {
        $pid = $this->start('sleep 92.5 & wait');
        self::assertTrue(Kit::sleeping('92.5'));
        self::assertNotSame($pid, posix_getpgid($pid), 'the child shares the group of this test, so a group signal cannot be used');

        ProcessTree::signal($pid, 15);

        self::assertTrue($this->eventually(static fn (): bool => !Kit::sleeping('92.5')));
    }

    public function testAProcessThatIsNotOwnedIsNeverSignalledOutsideItsGroup(): void
    {
        $pid = $this->start('sleep 93.75 & wait');

        ProcessTree::signal($pid, 15, false);
        usleep(300000);

        self::assertTrue(Kit::sleeping('93.75'));
        self::assertTrue(ProcessTree::alive($pid));
        self::assertFalse(ProcessTree::alive($pid, false), 'without a group of its own, a process that is not owned counts as gone');
        self::assertFalse(ProcessTree::alive(0));
        self::assertFalse(ProcessTree::alive(1));
        ProcessTree::signal($pid, 9);
        self::assertTrue($this->eventually(static fn (): bool => !Kit::sleeping('93.75')));
    }
}
