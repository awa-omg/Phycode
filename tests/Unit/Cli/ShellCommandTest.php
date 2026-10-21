<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Gentle\ShellIntegration;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class ShellCommandTest extends TestCase
{
    private const PROFILE = ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'];

    public function testTheStatusIsTheDefaultAndTheCommandExitsZero(): void
    {
        $run = PhycodeRun::run(['shell'], self::PROFILE, '', ['SHELL' => '/bin/zsh']);

        self::assertSame(0, $run->status, $run->stderr);
        self::assertStringStartsWith("shell: zsh\nfile: {$run->home}/.zshrc\nblock: not installed\nbin: " . ShellIntegration::defaultBinDirectory() . "\n", $run->stdout);
    }

    public function testAnInstallWithoutATerminalChangesNothingAndExitsZero(): void
    {
        $home = Kit::folder('shell-home');
        Kit::write("{$home}/.zshrc", "export A=1\n");

        $run = PhycodeRun::run(['shell', 'install'], self::PROFILE, "yes\n", ['SHELL' => '/bin/zsh'], [], $home);

        self::assertSame(0, $run->status, $run->stderr);
        self::assertStringContainsString('not approved: nothing was changed.', $run->stdout);
        self::assertSame("export A=1\n", file_get_contents("{$home}/.zshrc"));
    }

    public function testAShellWithoutAnRcFileIsAnErrorWithExitOne(): void
    {
        $run = PhycodeRun::run(['shell', 'install'], self::PROFILE, '', ['SHELL' => '/usr/bin/fish']);

        self::assertSame(1, $run->status);
        self::assertStringContainsString('phycode shell supports zsh and bash, not fish', $run->stderr);
    }

    public function testAnUnknownActionIsAUsageErrorWithExitOne(): void
    {
        $run = PhycodeRun::run(['shell', 'reset'], self::PROFILE, '', ['SHELL' => '/bin/zsh']);

        self::assertSame(1, $run->status);
        self::assertStringContainsString('usage: phycode shell status, phycode shell install or phycode shell uninstall', $run->stderr);
    }
}
