<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Config\ConfigWriter;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class KeyedUrlRulesTest extends TestCase
{
    private const KEYED = ['base_url' => 'http://203.0.113.5:8000/v1', 'model' => 'm', 'api_key_env' => 'PHYCODE_TEST_KEY'];
    private const KEYLESS = ['base_url' => 'http://203.0.113.5:8000/v1', 'model' => 'm'];

    private function load(?array $profile, array $env, string $default = 'remote'): Config
    {
        $global = $profile === null ? null : ['default_profile' => $default, 'profiles' => [$default => $profile]];

        return Config::fromArrays($global, null, $env, Kit::folder('keyed-url'));
    }

    public function testAKeyedProfileOverPlainHttpToAnotherHostIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must be an https:// URL');

        $this->load(self::KEYED, []);
    }

    public function testAKeylessProfileOverPlainHttpIsStillAllowed(): void
    {
        self::assertSame('http://203.0.113.5:8000/v1', $this->load(self::KEYLESS, [])->profile()->baseUrl);
    }

    public function testAKeyedProfileOnThisMachineOverHttpIsAllowed(): void
    {
        $profile = self::KEYED;
        $profile['base_url'] = 'http://127.0.0.1:8000/v1';

        self::assertSame('http://127.0.0.1:8000/v1', $this->load($profile, [])->profile()->baseUrl);
    }

    public function testAnEnvironmentBaseUrlOverHttpIsRefusedWhenTheDefaultProfileSendsAKey(): void
    {
        $profile = self::KEYED;
        $profile['base_url'] = 'https://llm.example.com/v1';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("profile 'remote' sends its key");

        $this->load($profile, ['PHYCODE_BASE_URL' => 'http://203.0.113.9:8000/v1']);
    }

    public function testAnEnvironmentBaseUrlOverHttpIsAllowedWhenTheDefaultProfileHasNoKey(): void
    {
        self::assertSame('http://203.0.113.9:8000/v1', $this->load(self::KEYLESS, ['PHYCODE_BASE_URL' => 'http://203.0.113.9:8000/v1'])->profile()->baseUrl);
    }

    public function testAProfileOfTheEnvironmentOverHttpIsRefusedOnlyWhenAKeyIsSet(): void
    {
        $env = ['PHYCODE_BASE_URL' => 'http://203.0.113.9:8000/v1', 'PHYCODE_MODEL' => 'm'];

        self::assertSame('http://203.0.113.9:8000/v1', $this->load(null, $env)->profile()->baseUrl);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('PHYCODE_BASE_URL must be an https:// URL when PHYCODE_API_KEY is set');

        $this->load(null, $env + ['PHYCODE_API_KEY' => 'sk-test']);
    }

    public function testTheProfileWriterRefusesAKeyOverPlainHttp(): void
    {
        $file = Kit::folder('keyed-writer') . '/config.json';
        file_put_contents($file, json_encode(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:8082', 'model' => 'm']]], JSON_PRETTY_PRINT));

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('must be an https:// URL for a profile with a key');
        ConfigWriter::addProfile($file, 'remote', ['protocol' => 'openai', 'base_url' => 'http://203.0.113.5:8000', 'api_key_env' => 'REMOTE_KEY', 'model' => 'm']);
    }

    public function testTheProfileWriterStillAcceptsAKeylessProfileOverPlainHttp(): void
    {
        $file = Kit::folder('keyless-writer') . '/config.json';
        file_put_contents($file, json_encode(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:8082', 'model' => 'm']]], JSON_PRETTY_PRINT));

        ConfigWriter::addProfile($file, 'remote', ['protocol' => 'openai', 'base_url' => 'http://203.0.113.5:8000', 'api_key_env' => null, 'model' => 'm']);

        self::assertStringContainsString('"remote"', (string) file_get_contents($file));
    }
}
