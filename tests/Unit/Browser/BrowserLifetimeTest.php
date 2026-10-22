<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Browser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrowserLifetimeTest extends TestCase
{
    private ?FakeBrowser $fake = null;
    private $child = null;

    protected function tearDown(): void
    {
        if (is_resource($this->child)) {
            proc_terminate($this->child, SIGKILL);
            proc_close($this->child);
        }
        $this->fake?->stop();
    }

    private function start(string $mode): array
    {
        $this->fake = new FakeBrowser(['pages' => ['http://127.0.0.1:3000/' => ['title' => 'Up', 'text' => 'up']]]);
        $this->child = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/browser-child.php', $this->fake->binary, $mode], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $line = fgets($pipes[1]);
        self::assertSame("ready\n", $line, 'the session started a browser');
        $launch = $this->fake->launches()[0];
        $profile = substr(array_values(array_filter($launch['argv'], static fn (string $flag): bool => str_starts_with($flag, '--user-data-dir=')))[0], strlen('--user-data-dir='));
        self::assertTrue($this->fake->alive($launch['pid']));

        return [$launch['pid'], $profile, proc_get_status($this->child)['pid']];
    }

    private function goneWithin(int $pid, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while ($this->fake->alive($pid) && microtime(true) < $deadline) {
            usleep(100000);
        }

        return !$this->fake->alive($pid);
    }

    public function testTheBrowserEndsWithTheProcessThatEndsNormally(): void
    {
        [$browser, $profile] = $this->start('finish');

        self::assertTrue($this->goneWithin($browser, 8.0));
        for ($deadline = microtime(true) + 5.0; is_dir($profile) && microtime(true) < $deadline; usleep(100000)) {
            clearstatcache();
        }
        self::assertDirectoryDoesNotExist($profile);
    }

    public static function deaths(): iterable
    {
        yield 'killed' => [SIGKILL];
        yield 'terminated without a handler' => [SIGTERM];
    }

    #[DataProvider('deaths')]
    public function testTheBrowserDoesNotOutliveAProcessThatDiesWithoutCleaningUp(int $signal): void
    {
        [$browser, $profile, $owner] = $this->start('wait');

        posix_kill($owner, $signal);

        self::assertTrue($this->goneWithin($browser, 10.0), 'a browser must not be left behind');
        $deadline = microtime(true) + 5.0;
        while (clearstatcache() === null && is_dir($profile) && microtime(true) < $deadline) {
            usleep(100000);
        }
        self::assertDirectoryDoesNotExist($profile, 'the throw-away folder is removed even when the owner could not do it');
    }
}
