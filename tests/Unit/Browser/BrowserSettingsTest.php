<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Browser;

use Phycode\Browser\BrowserSettings;
use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrowserSettingsTest extends TestCase
{
    private ?string $path = null;

    protected function tearDown(): void
    {
        if ($this->path !== null) {
            putenv('PATH=' . $this->path);
            $this->path = null;
        }
    }

    private function load(?string $browser, ?string $project = null): Config
    {
        $home = Kit::folder('browser-home');
        $section = $browser === null ? '' : ', "browser": ' . $browser;
        Kit::write($home . '/config.json', '{"default_profile": "local", "profiles": {"local": {"base_url": "http://127.0.0.1:8082", "model": "m"}}' . $section . '}');
        $folder = Kit::folder('browser-project');
        if ($project !== null) {
            Kit::write($folder . '/.phycode/config.json', $project);
        }

        return Config::load($folder, $home, []);
    }

    private function pathWith(string ...$programs): void
    {
        $this->path = (string) getenv('PATH');
        $folder = Kit::folder('browser-path');
        foreach ($programs as $program) {
            Kit::write($folder . '/' . $program, "#!/bin/sh\nexit 0\n");
            chmod($folder . '/' . $program, 0755);
        }
        putenv('PATH=' . $folder);
    }

    public function testTheBrowserIsOffByDefaultAndSafeWhenSwitchedOn(): void
    {
        $settings = $this->load(null)->browser();

        self::assertFalse($settings->enabled);
        self::assertNull($settings->command);
        self::assertSame(['localhost', '127.0.0.1'], $settings->allowHosts);
        self::assertSame(3, $settings->maxPages);
        self::assertFalse($settings->noSandbox);
        self::assertEquals($settings, $this->load('{}')->browser());
        self::assertTrue($this->load('{"enabled": true}')->browser()->enabled);
    }

    public function testEverySettingIsRead(): void
    {
        $settings = $this->load('{"enabled": true, "command": " /opt/chrome/chrome ", "allow_hosts": ["App.Test", "10.0.0.5", "*.dev.test", "::1"], "max_pages": 5, "no_sandbox": true}')->browser();

        self::assertSame([true, '/opt/chrome/chrome', ['app.test', '10.0.0.5', '*.dev.test', '::1'], 5, true], [$settings->enabled, $settings->command, $settings->allowHosts, $settings->maxPages, $settings->noSandbox]);
    }

    public function testAnEmptyListOfHostsMeansThePublicInternetOnly(): void
    {
        self::assertSame([], $this->load('{"allow_hosts": []}')->browser()->allowHosts);
    }

    public static function invalid(): iterable
    {
        yield 'not an object' => ['true', 'browser must be an object'];
        yield 'a list' => ['[1]', 'browser must be an object'];
        yield 'an unknown key' => ['{"headless": false}', 'browser.headless is not a known setting'];
        yield 'enabled as text' => ['{"enabled": "yes"}', 'browser.enabled must be true or false'];
        yield 'no_sandbox as a number' => ['{"no_sandbox": 1}', 'browser.no_sandbox must be true or false'];
        yield 'an empty command' => ['{"command": " "}', 'browser.command must be the path'];
        yield 'a command that is a list' => ['{"command": ["chrome"]}', 'browser.command must be the path'];
        yield 'zero pages' => ['{"max_pages": 0}', 'browser.max_pages must be an integer from 1 to 10'];
        yield 'too many pages' => ['{"max_pages": 11}', 'browser.max_pages must be an integer from 1 to 10'];
        yield 'pages as text' => ['{"max_pages": "3"}', 'browser.max_pages must be an integer from 1 to 10'];
        yield 'hosts as text' => ['{"allow_hosts": "localhost"}', 'browser.allow_hosts must be a list'];
        yield 'a host with a scheme' => ['{"allow_hosts": ["http://localhost"]}', 'browser.allow_hosts must be a list of host names'];
        yield 'a host with a port' => ['{"allow_hosts": ["localhost:3000"]}', 'browser.allow_hosts must be a list of host names'];
        yield 'a host that is a number' => ['{"allow_hosts": [3]}', 'browser.allow_hosts must be a list of host names'];
    }

    #[DataProvider('invalid')]
    public function testInvalidSettingsAreRefusedWithTheReason(string $browser, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        $this->load($browser);
    }

    public function testAProjectCannotSwitchTheBrowserOnOrWidenItsHosts(): void
    {
        $config = $this->load(null, '{"browser": {"enabled": true, "allow_hosts": ["169.254.169.254", "10.0.0.5"], "no_sandbox": true}}');

        self::assertContains("project config key 'browser' ignored: only the global config can set it", $config->warnings);
        self::assertFalse($config->browser()->enabled);
        self::assertSame(['localhost', '127.0.0.1'], $config->browser()->allowHosts);
        self::assertFalse($config->browser()->noSandbox);
    }

    public function testTheBinaryIsLookedForUnderTheUsualNamesInOrder(): void
    {
        $this->pathWith('google-chrome', 'chromium-browser');

        self::assertStringEndsWith('/chromium-browser', (string) (new BrowserSettings(true))->binary());
    }

    public function testNoBinaryOnThePathMeansNone(): void
    {
        $this->pathWith('bash');

        self::assertSame(FakeBrowser::installed(), (new BrowserSettings(true))->binary(), 'only the application folders of macOS are left');
    }

    public function testACommandIsUsedInsteadOfTheSearch(): void
    {
        $this->pathWith('chromium', 'mybrowser');
        $own = Kit::folder('browser-own') . '/chrome';
        Kit::write($own, "#!/bin/sh\n");
        chmod($own, 0755);

        self::assertSame($own, (new BrowserSettings(true, $own))->binary());
        self::assertStringEndsWith('/mybrowser', (string) (new BrowserSettings(true, 'mybrowser'))->binary());
        self::assertNull((new BrowserSettings(true, '/nonexistent/chrome'))->binary(), 'a command that is not there is not replaced by another browser');
        self::assertNull((new BrowserSettings(true, 'other'))->binary());
    }

    public function testAFileThatIsNotExecutableIsNotABrowser(): void
    {
        $own = Kit::folder('browser-plain') . '/chrome';
        Kit::write($own, 'text');

        self::assertNull((new BrowserSettings(true, $own))->binary());
    }
}
