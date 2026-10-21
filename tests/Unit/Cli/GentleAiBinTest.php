<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class GentleAiBinTest extends TestCase
{
    private const PROFILES = ['profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen']]];

    public function testWithoutTheKeyNoBinaryIsNamed(): void
    {
        self::assertNull($this->config(self::PROFILES)->gentleAiBin());
    }

    public function testAnAbsoluteExecutableFileIsReturned(): void
    {
        $bin = $this->file(0755);

        self::assertSame($bin, $this->config(self::PROFILES + ['gentle_ai' => ['bin' => $bin]])->gentleAiBin());
    }

    public function testARelativePathIsRefused(): void
    {
        $config = $this->config(self::PROFILES + ['gentle_ai' => ['bin' => 'gentle-ai']]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('gentle_ai.bin must be an absolute path to an executable file');
        $config->gentleAiBin();
    }

    public function testAMissingFileIsRefused(): void
    {
        $config = $this->config(self::PROFILES + ['gentle_ai' => ['bin' => '/missing/phycode-test/gentle-ai']]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('gentle_ai.bin is not an executable file: /missing/phycode-test/gentle-ai');
        $config->gentleAiBin();
    }

    public function testAFileWithoutTheExecuteBitIsRefused(): void
    {
        $bin = $this->file(0644);
        $config = $this->config(self::PROFILES + ['gentle_ai' => ['bin' => $bin]]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("gentle_ai.bin is not an executable file: {$bin}");
        $config->gentleAiBin();
    }

    public function testTheProjectConfigCannotNameABinary(): void
    {
        $bin = $this->file(0755);

        $config = Config::fromArrays(self::PROFILES, ['gentle_ai' => ['bin' => $bin]], [], '/nonexistent');

        self::assertNull($config->gentleAiBin());
        self::assertContains("project config key 'gentle_ai' ignored: only the global config can set it", $config->warnings);
    }

    public function testTheSectionMustBeAnObject(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("'gentle_ai' must be an object");
        Config::fromArrays(self::PROFILES + ['gentle_ai' => 'gentle-ai'], null, [], '/nonexistent');
    }

    private function config(array $global): Config
    {
        return Config::fromArrays($global, null, [], '/nonexistent');
    }

    private function file(int $mode): string
    {
        $path = Kit::folder('gentle-bin') . '/gentle-ai';
        file_put_contents($path, "#!/bin/sh\n");
        chmod($path, $mode);

        return $path;
    }
}
