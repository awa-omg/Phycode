<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\Application;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class TuiLaunchTest extends TestCase
{
    private const PHP = '/usr/bin/php';
    private const SCRIPT = '/opt/phycode/bin/phycode';

    private string $folder;
    private string $binary;

    protected function setUp(): void
    {
        $this->folder = Kit::folder('tui-launch');
        $this->binary = $this->folder . '/phycode-tui';
    }

    private static function options(?string $cwd = null, ?string $profile = null, ?string $agent = null, ?string $sandbox = null, bool $yolo = false, array $addDir = []): array
    {
        return [
            'command' => null,
            'positional' => [],
            'cwd' => $cwd,
            'profile' => $profile,
            'agent' => $agent,
            'sandbox' => $sandbox,
            'add_dir' => $addDir,
            'tui' => true,
            'yolo' => $yolo,
            'json' => false,
            'help' => false,
        ];
    }

    private function buildBinary(int $mode): void
    {
        Kit::write($this->binary, "#!/bin/sh\n");
        chmod($this->binary, $mode);
    }

    public function testWithoutOptionsOnlyTheRpcCommandIsPassedOn(): void
    {
        $this->buildBinary(0755);

        $launch = Application::tuiLaunch(self::options(), $this->binary, self::PHP, self::SCRIPT, true);

        self::assertSame('exec', $launch['outcome']);
        self::assertSame(['--', self::PHP, self::SCRIPT, 'rpc'], $launch['argv']);
    }

    public function testEveryOptionThatWasGivenIsPassedOnInTheSameForm(): void
    {
        $this->buildBinary(0755);

        $launch = Application::tuiLaunch(self::options('/work/project', 'local', 'plan', 'podman', true), $this->binary, self::PHP, self::SCRIPT, true);

        self::assertSame('exec', $launch['outcome']);
        self::assertSame(
            ['--', self::PHP, self::SCRIPT, 'rpc', '--cwd', '/work/project', '--profile', 'local', '--agent', 'plan', '--sandbox', 'podman', '--yolo'],
            $launch['argv'],
        );
    }

    public function testEveryReadOnlyFolderIsPassedOnInTheOrderGiven(): void
    {
        $this->buildBinary(0755);

        $launch = Application::tuiLaunch(self::options(null, null, null, null, false, ['/docs', '/data']), $this->binary, self::PHP, self::SCRIPT, true);

        self::assertSame(['--', self::PHP, self::SCRIPT, 'rpc', '--add-dir', '/docs', '--add-dir', '/data'], $launch['argv']);
    }

    public function testAnOptionThatWasNotGivenIsLeftOut(): void
    {
        $this->buildBinary(0755);

        $launch = Application::tuiLaunch(self::options(null, 'local'), $this->binary, self::PHP, self::SCRIPT, true);

        self::assertSame(['--', self::PHP, self::SCRIPT, 'rpc', '--profile', 'local'], $launch['argv']);
    }

    public function testAMissingBinaryMeansThePhpUiIsUsed(): void
    {
        $launch = Application::tuiLaunch(self::options('/work'), $this->binary, self::PHP, self::SCRIPT, true);

        self::assertSame('not-built', $launch['outcome']);
        self::assertSame(['--', self::PHP, self::SCRIPT, 'rpc', '--cwd', '/work'], $launch['argv']);
    }

    public function testABinaryThatIsNotExecutableMeansThePhpUiIsUsed(): void
    {
        $this->buildBinary(0644);

        $launch = Application::tuiLaunch(self::options(), $this->binary, self::PHP, self::SCRIPT, true);

        self::assertSame('not-built', $launch['outcome']);
    }

    public function testWithoutPcntlTheBuiltBinaryIsNotStarted(): void
    {
        $this->buildBinary(0755);

        $launch = Application::tuiLaunch(self::options(), $this->binary, self::PHP, self::SCRIPT, false);

        self::assertSame('no-pcntl', $launch['outcome']);
        self::assertSame(['--', self::PHP, self::SCRIPT, 'rpc'], $launch['argv']);
    }
}
