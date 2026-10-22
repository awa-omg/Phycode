<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Browser;

use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Cli\PhycodeRun;
use PHPUnit\Framework\TestCase;

final class BrowserDoctorTest extends TestCase
{
    private function doctor(array $global, ?string $path = null): string
    {
        $profile = ['base_url' => 'http://127.0.0.1:9', 'model' => 'm'];

        return PhycodeRun::run(['doctor', '--cwd', Kit::folder('doctor-browser-project')], $profile, '', $path === null ? [] : ['PATH' => $path], $global)->stdout;
    }

    private static function line(string $output): ?array
    {
        return preg_match('/^\[(ok|FAIL|--)\] browser +(.*)$/m', $output, $match) === 1 ? [$match[1], $match[2]] : null;
    }

    private function withBrowser(): string
    {
        $folder = Kit::folder('doctor-browser-path');
        Kit::write($folder . '/chromium', "#!/bin/sh\nexit 0\n");
        chmod($folder . '/chromium', 0755);
        foreach (['timeout', 'bash', 'git'] as $program) {
            symlink((string) trim((string) shell_exec('command -v ' . $program)), $folder . '/' . $program);
        }

        return $folder;
    }

    public function testTheBrowserBinaryIsListedLikeTheOtherPrograms(): void
    {
        $path = $this->withBrowser();

        $output = $this->doctor([], $path);

        self::assertSame(['ok', $path . '/chromium (the browser tool is off: set browser.enabled to use it)'], self::line($output));
    }

    public function testItSaysWhenTheBrowserToolIsOn(): void
    {
        $path = $this->withBrowser();

        $output = $this->doctor(['browser' => ['enabled' => true]], $path);

        self::assertSame(['ok', $path . '/chromium (the browser tool is on)'], self::line($output));
    }

    public function testAConfiguredCommandIsTheOneListed(): void
    {
        $path = $this->withBrowser();
        $own = Kit::folder('doctor-browser-own') . '/my-chrome';
        Kit::write($own, "#!/bin/sh\nexit 0\n");
        chmod($own, 0755);

        $output = $this->doctor(['browser' => ['enabled' => true, 'command' => $own]], $path);

        self::assertSame(['ok', $own . ' (the browser tool is on)'], self::line($output));
    }

    public function testNoBrowserIsReportedWithTheFixAndTheToolIsNotOffered(): void
    {
        $empty = Kit::folder('doctor-browser-empty');

        $quiet = $this->doctor([], $empty);
        $enabled = $this->doctor(['browser' => ['enabled' => true]], $empty);

        self::assertSame(['--', 'not found (install Chrome or Chromium, or set browser.command)'], self::line($quiet));
        self::assertSame(['--', 'not found (install Chrome or Chromium, or set browser.command); the browser tool is not offered'], self::line($enabled));
    }
}
