<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Config\Sandbox;
use Phycode\Exec\BwrapRunner;
use Phycode\Exec\ProcessRunner;
use Phycode\Exec\SandboxSelector;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class SandboxSettingsTest extends TestCase
{
    private function load(string $sandbox): Config
    {
        $home = Kit::folder('sandbox-home');
        Kit::write($home . '/config.json', '{"default_profile": "local", "profiles": {"local": {"base_url": "http://127.0.0.1:8082", "model": "m"}}, "sandbox": ' . $sandbox . '}');

        return Config::load(Kit::folder('sandbox-project'), $home, []);
    }

    public function testBwrapIsASandboxAndTheListOfDomainsIsKept(): void
    {
        $config = $this->load('{"default": "bwrap", "allow_domains": ["github.com", " *.npmjs.org "]}');

        self::assertSame(['bwrap', 'none', ['github.com', '*.npmjs.org']], [$config->sandbox->default, $config->sandbox->network, $config->sandbox->allowDomains]);
    }

    public function testBwrapNeedsNoImage(): void
    {
        self::assertNull($this->load('{"default": "bwrap"}')->sandbox->image);
    }

    public function testTheListWithTheWholeNetworkIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('bridge is the whole network');
        $this->load('{"default": "bwrap", "network": "bridge", "allow_domains": ["github.com"]}');
    }

    public function testTheListWhereNothingFiltersIsRefusedInsteadOfIgnored(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('bwrap only');
        $this->load('{"default": "host", "allow_domains": ["github.com"]}');
    }

    public function testAnEntryThatIsNotAHostIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->load('{"default": "bwrap", "allow_domains": ["https://github.com/x"]}');
    }

    public function testTheSelectorDescribesBwrapByItsNetwork(): void
    {
        $root = realpath(Kit::folder('sandbox-selector'));
        $host = new ProcessRunner();

        self::assertSame('bwrap (no network)', (new SandboxSelector(new Sandbox('bwrap', null, 'none'), null, $host, $root))->describe());
        self::assertSame('bwrap (host network)', (new SandboxSelector(new Sandbox('bwrap', null, 'bridge'), null, $host, $root))->describe());
        self::assertSame('bwrap (network limited to 2 hosts)', (new SandboxSelector(new Sandbox('bwrap', null, 'none', ['a.com', 'b.com']), null, $host, $root))->describe());
        self::assertSame('bwrap (no network)', (new SandboxSelector(new Sandbox('host', null, 'none'), 'bwrap', $host, $root))->describe());
    }

    public function testTheSelectorBuildsTheJailOnlyWhenBubblewrapIsThere(): void
    {
        $root = realpath(Kit::folder('sandbox-selector-run'));
        $selector = new SandboxSelector(new Sandbox('bwrap', null, 'none'), null, new ProcessRunner(), $root);

        if (BwrapRunner::available()) {
            self::assertInstanceOf(BwrapRunner::class, $selector->runnerFor(null));

            return;
        }
        $this->expectExceptionMessage('needs bubblewrap');
        $selector->runnerFor(null);
    }
}
