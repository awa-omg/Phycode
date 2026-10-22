<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class MemorySettingsTest extends TestCase
{
    private function load(string $global, string $project = '{}'): Config
    {
        $home = Kit::folder('memory-settings-home');
        $folder = Kit::folder('memory-settings-project');
        Kit::write($home . '/config.json', '{"default_profile": "local", "profiles": {"local": {"base_url": "http://127.0.0.1:8082", "model": "m"}}, "memory": ' . $global . '}');
        Kit::write($folder . '/.phycode/config.json', $project);

        return Config::load($folder, $home, []);
    }

    public function testMemoryIsOnAndAsksFirstWhenTheConfigSaysNothing(): void
    {
        $config = $this->load('{}');

        self::assertTrue($config->memoryEnabled());
        self::assertSame('ask', $config->memoryAutonomy());
    }

    public function testBothSettingsAreRead(): void
    {
        $config = $this->load('{"enabled": false, "autonomy": "auto"}');

        self::assertFalse($config->memoryEnabled());
        self::assertSame('auto', $config->memoryAutonomy());
    }

    public function testTheProjectCannotChangeTheAutonomy(): void
    {
        $config = $this->load('{}', '{"memory": {"autonomy": "auto", "enabled": false}}');

        self::assertSame('ask', $config->memoryAutonomy());
        self::assertTrue($config->memoryEnabled());
    }

    public function testAnUnknownKeyOrValueIsRefused(): void
    {
        foreach (['{"autonomy": "always"}' => 'memory.autonomy', '{"enabled": "yes"}' => 'memory.enabled', '{"scope": "user"}' => 'memory'] as $memory => $name) {
            try {
                $this->load($memory);
                self::fail("{$memory} must be refused");
            } catch (ConfigException $error) {
                self::assertStringContainsString($name, $error->getMessage());
            }
        }
    }
}
