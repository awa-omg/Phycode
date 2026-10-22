<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Config\Config;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Unit\Browser\FakeBrowser;
use Phycode\Tools\BrowserTool;
use Phycode\Tools\ToolRegistry;
use Phycode\Tools\ToolSettings;
use PHPUnit\Framework\TestCase;

final class BrowserOfferTest extends TestCase
{
    private ?string $path = null;

    protected function tearDown(): void
    {
        if ($this->path !== null) {
            putenv('PATH=' . $this->path);
            $this->path = null;
        }
    }

    private function config(array $browser): Config
    {
        $home = Kit::folder('browser-offer-home');
        Kit::write($home . '/config.json', json_encode([
            'default_profile' => 'blind',
            'profiles' => [
                'blind' => ['base_url' => 'http://127.0.0.1:8082', 'model' => 'm', 'vision' => false],
                'seeing' => ['base_url' => 'http://127.0.0.1:8082', 'model' => 'm', 'vision' => true],
            ],
            'browser' => $browser,
        ], JSON_THROW_ON_ERROR));

        return Config::load(Kit::folder('browser-offer-project'), $home, []);
    }

    private function emptyPath(): void
    {
        $this->path = (string) getenv('PATH');
        putenv('PATH=' . Kit::folder('browser-offer-path'));
    }

    public function testNothingIsOfferedWhileTheBrowserIsOff(): void
    {
        $fake = new FakeBrowser();
        $ui = new RecordingUi();

        self::assertSame([], BrowserTool::offered($this->config(['command' => $fake->binary]), $ui));
        self::assertSame([], $ui->events);
    }

    public function testTheToolIsOfferedWhenItIsOnAndABinaryExists(): void
    {
        $fake = new FakeBrowser();

        $tools = BrowserTool::offered($this->config(['enabled' => true, 'command' => $fake->binary]), new RecordingUi());

        self::assertCount(1, $tools);
        self::assertSame('browser', $tools[0]->name());
        self::assertSame([], $fake->launches(), 'offering the tool starts nothing');
    }

    public function testWithoutABinaryTheToolIsNotOfferedAndTheUserIsTold(): void
    {
        $this->emptyPath();
        $ui = new RecordingUi();

        $tools = BrowserTool::offered($this->config(['enabled' => true]), $ui);

        self::assertSame([], $tools);
        self::assertSame([['notice', 'warn', 'browser.enabled is true, but no Chrome or Chromium was found, so the browser tool is not offered: install one, or set browser.command to its path']], $ui->events);
    }

    public function testAMissingConfiguredCommandIsNotReplacedByAnotherBrowser(): void
    {
        self::assertSame([], BrowserTool::offered($this->config(['enabled' => true, 'command' => '/nonexistent/chrome']), new RecordingUi()));
    }

    public function testTheOptionalToolsOfTheKernelIncludeIt(): void
    {
        $fake = new FakeBrowser();
        $settings = new ToolSettings([], []);

        $on = $settings->optionalTools($this->config(['enabled' => true, 'command' => $fake->binary]), new RecordingUi(), Kit::folder('browser-offer-root'));
        $off = $settings->optionalTools($this->config([]), new RecordingUi(), Kit::folder('browser-offer-root'));

        self::assertContains('browser', array_map(static fn ($tool): string => $tool->name(), $on));
        self::assertNotContains('browser', array_map(static fn ($tool): string => $tool->name(), $off));
    }

    public function testTheRegistryOffersItToAnAgentThatMayRunCommands(): void
    {
        $fake = new FakeBrowser();
        $registry = new ToolRegistry();
        foreach (BrowserTool::offered($this->config(['enabled' => true, 'command' => $fake->binary]), new RecordingUi()) as $tool) {
            $registry->register($tool);
        }

        self::assertSame(['browser'], $registry->available(Kit::policy('ask')));
        self::assertSame([], $registry->available(Kit::policy('ask', denied: ['exec'])), 'a read-only agent does not get a tool of the exec category');
        self::assertSame([], $registry->available(Kit::policy('ask', allowed: ['read'])));
        self::assertSame('browser', $registry->specs(Kit::policy('ask'))[0]['function']['name']);
    }

    public function testAScreenshotAsksTheActiveProfileWhetherItSees(): void
    {
        $fake = new FakeBrowser();
        $tool = BrowserTool::offered($this->config(['enabled' => true, 'command' => $fake->binary]), new RecordingUi())[0];
        $ui = new RecordingUi();

        $blind = $tool->execute(['action' => 'screenshot'], Kit::context(Kit::folder('browser-offer-ctx'), Kit::agent(), Kit::policy('yolo'), $ui, profile: 'blind'));
        $seeing = $tool->execute(['action' => 'screenshot'], Kit::context(Kit::folder('browser-offer-ctx'), Kit::agent(), Kit::policy('yolo'), $ui, profile: 'seeing'));
        $unknown = $tool->execute(['action' => 'screenshot'], Kit::context(Kit::folder('browser-offer-ctx'), Kit::agent(), Kit::policy('yolo'), $ui, profile: 'gone'));

        self::assertStringContainsString('the profile blind is not set to read images', $blind->content);
        self::assertSame('no page is open: use action=open with a url first', $seeing->content, 'a profile that sees goes on to the capture');
        self::assertStringContainsString('the profile gone is not set to read images', $unknown->content);
    }
}
