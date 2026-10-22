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

    public function testWhatADescendantThatLeadsAGroupLeftBehindIsStoppedToo(): void
    {
        $leader = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('posix_setpgid(0, 0); passthru("(sleep 94.25 &); sleep 94.5");');
        $pid = $this->start($leader . ' & wait');
        self::assertTrue($this->eventually(static fn (): bool => Kit::sleeping('94.25') && Kit::sleeping('94.5')));

        ProcessTree::signal($pid, 9);

        self::assertTrue($this->eventually(static fn (): bool => !Kit::sleeping('94.25') && !Kit::sleeping('94.5')));
    }

    public function testOnlyTheProcessesThatCarryTheMarkAreFound(): void
    {
        $otherMark = ProcessTree::mark();
        $other = proc_open(['sh', '-c', 'sleep 99.5 & wait'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PATH' => (string) getenv('PATH'), ProcessTree::MARK => $otherMark]);
        try {
            for ($attempt = 0, $found = false; $attempt < 4 && !$found; $attempt++) {
                $mark = ProcessTree::mark();
                $this->process = proc_open(['sh', '-c', 'sleep 99.25 & wait'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PATH' => (string) getenv('PATH'), ProcessTree::MARK => $mark]);
                $found = $this->eventually(static fn (): bool => count(ProcessTree::marked($mark)) >= 2 && Kit::sleeping('99.5'));
                if (!$found) {
                    ProcessTree::signal((int) proc_get_status($this->process)['pid'], 9);
                    proc_close($this->process);
                }
            }
            self::assertTrue($found, 'FreeBSD 15.1 cannot read the environment of a few processes in 100, so up to four new pairs are tried');
            self::assertSame([], ProcessTree::marked('not-a-mark'));

            ProcessTree::signalMarked($mark, 9);

            self::assertTrue($this->eventually(static fn (): bool => !Kit::sleeping('99.25')));
            self::assertTrue(Kit::sleeping('99.5'), 'a process with another mark is left alone');
        } finally {
            ProcessTree::signalMarked($otherMark, 9);
            proc_close($other);
        }
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
