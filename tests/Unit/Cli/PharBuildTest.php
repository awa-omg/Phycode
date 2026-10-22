<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Exec\ProcessRunner;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RpcProcess;
use PHPUnit\Framework\TestCase;

final class PharBuildTest extends TestCase
{
    private static function execute(array $argv, string $home): array
    {
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3), ['PATH' => (string) getenv('PATH'), 'HOME' => $home, 'XDG_CONFIG_HOME' => $home . '/config', 'XDG_CACHE_HOME' => $home . '/cache', 'COMPOSER_HOME' => (string) (getenv('COMPOSER_HOME') ?: $home . '/.composer')]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function testTheArchiveBuildsAndRunsAsASingleFileWithItsOwnAgents(): void
    {
        if (ProcessRunner::which('composer') === null) {
            self::markTestSkipped('composer is not installed');
        }
        $home = Kit::folder('phar-home');
        RpcProcess::configure($home, 'http://127.0.0.1:9', 'text');
        $phar = Kit::folder('phar-out') . '/phycode.phar';

        [$built, $output, $error] = self::execute([PHP_BINARY, dirname(__DIR__, 3) . '/bin/build-phar', $phar], $home);
        self::assertSame(0, $built, $output . $error);
        self::assertFileExists($phar);

        [$status, $version] = self::execute([PHP_BINARY, $phar, 'version'], $home);
        [$listed, $agents] = self::execute([PHP_BINARY, $phar, 'agents', '--cwd', $home], $home);

        self::assertSame(0, $status);
        self::assertStringStartsWith('phycode ', $version);
        self::assertSame(0, $listed);
        self::assertStringContainsString('explore', $agents);
        self::assertStringContainsString('reviewer-risk', $agents);
    }
}
