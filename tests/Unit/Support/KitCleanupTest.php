<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Support;

use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class KitCleanupTest extends TestCase
{
    private const CHILD = __DIR__ . '/../../Support/kit-child.php';

    public function testTheFoldersOfAProcessAreRemovedWhenItEnds(): void
    {
        [$outside, $output] = $this->fixtures();

        $process = proc_open([PHP_BINARY, self::CHILD, $outside], $this->descriptors($output), $pipes);
        self::assertIsResource($process);
        $status = proc_close($process);
        clearstatcache();

        self::assertSame(3, $status, (string) file_get_contents($output . '/stderr.txt'));
        $folder = $this->printedFolder($output);
        self::assertStringStartsWith('/tmp/opencode/phpunit-kit-child-', $folder);
        self::assertDirectoryDoesNotExist($folder, 'the folder and everything in it are removed when the process ends');
        self::assertSame("keep\n", file_get_contents($outside . '/keep.txt'), 'the link is removed and its target is kept');
    }

    public function testTheFoldersOfAProcessAreRemovedWhenItIsStopped(): void
    {
        [$outside, $output] = $this->fixtures();

        $process = proc_open([PHP_BINARY, self::CHILD, $outside, 'wait'], $this->descriptors($output), $pipes);
        self::assertIsResource($process);
        $folder = '';
        for ($waited = 0; $folder === '' && $waited < 1500; $waited++) {
            usleep(20_000);
            $folder = $this->printedFolder($output);
        }
        proc_terminate($process);
        proc_close($process);
        clearstatcache();

        self::assertNotSame('', $folder, 'the child made its folder');
        self::assertDirectoryDoesNotExist($folder, 'a process stopped by SIGTERM, as timeout stops one, removes its folders too');
        self::assertSame("keep\n", file_get_contents($outside . '/keep.txt'), 'the link is removed and its target is kept');
    }

    private function fixtures(): array
    {
        $outside = Kit::folder('kit-outside');
        Kit::write($outside . '/keep.txt', "keep\n");

        return [$outside, Kit::folder('kit-output')];
    }

    private function descriptors(string $output): array
    {
        return [1 => ['file', $output . '/stdout.txt', 'w'], 2 => ['file', $output . '/stderr.txt', 'w']];
    }

    private function printedFolder(string $output): string
    {
        $text = (string) @file_get_contents($output . '/stdout.txt');

        return str_ends_with($text, "\n") ? trim($text) : '';
    }
}
