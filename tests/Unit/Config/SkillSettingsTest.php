<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SkillSettingsTest extends TestCase
{
    private const PROFILE = ['profiles' => ['p' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'm']]];

    private function config(array $skills = []): Config
    {
        return Config::fromArrays(self::PROFILE + ['skills' => $skills], null, [], '/nonexistent/phycode');
    }

    public function testTheIndexIsOnAndTheAutonomyIsAskByDefault(): void
    {
        self::assertTrue($this->config()->skillsIndexEnabled());
        self::assertSame('ask', $this->config()->skillsAutonomy(), 'a saved skill is read in every later session, so the person is asked first');
        self::assertSame('auto', $this->config(['autonomy' => 'auto'])->skillsAutonomy(), 'auto is still a choice');
    }

    public function testTheIndexAndTheAutonomyAreReadWithTheirChoices(): void
    {
        $config = $this->config(['index' => 'off', 'autonomy' => 'ask', 'enabled' => false]);

        self::assertFalse($config->skillsIndexEnabled());
        self::assertSame('ask', $config->skillsAutonomy());
        self::assertFalse($config->skillsEnabled(), 'the switch of skills is read as before');
    }

    public static function badValues(): iterable
    {
        yield 'an index that is a word' => [['index' => 'maybe'], 'skills.index must be on or off'];
        yield 'an index that is a boolean' => [['index' => false], 'skills.index must be on or off'];
        yield 'an autonomy that is not a choice' => [['autonomy' => 'always'], 'skills.autonomy must be auto or ask'];
        yield 'an unknown setting' => [['mode' => 'auto'], 'skills.mode is not a known setting'];
    }

    #[DataProvider('badValues')]
    public function testAValueOutsideItsChoicesIsRefusedWithThePlace(array $skills, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        $this->config($skills);
    }
}
