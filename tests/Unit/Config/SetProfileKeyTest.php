<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigWriter;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class SetProfileKeyTest extends TestCase
{
    private const CONFIG = <<<'JSON'
    {
        "default_profile": "local",
        "profiles": {
            "local": {"base_url": "http://127.0.0.1:8082", "model": "qwen"},
            "cloud": {"base_url": "https://api.example.com", "api_key_env": "CLOUD_KEY", "model": "gpt", "extra_body": {}},
            "filed": {"base_url": "https://api.example.org", "model": "m", "api_key_file": "/etc/key"}
        },
        "note": "Ñandú"
    }
    JSON;

    public function testTheKeyGoesToTheVaultAndTheFileOnlySaysSoAndLosesTheKeyVariable(): void
    {
        $file = $this->configFile();

        ConfigWriter::setProfileKey($file, 'cloud', '  sk-new-123  ');

        $saved = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['base_url' => 'https://api.example.com', 'model' => 'gpt', 'extra_body' => [], 'api_key_store' => true], $saved['profiles']['cloud']);
        self::assertSame('Ñandú', $saved['note']);
        self::assertSame('qwen', $saved['profiles']['local']['model'], 'the other profiles are untouched');
        self::assertSame('sk-new-123', (new Vault(dirname($file)))->get('cloud'));
        self::assertStringNotContainsString('sk-new-123', (string) file_get_contents($file));
        self::assertSame(self::CONFIG, file_get_contents($file . '.bak'));
        $config = Config::fromArrays($saved, null, [], dirname($file));
        self::assertSame('sk-new-123', $config->profile('cloud')->apiKey(), 'the loader finds the key in the vault');
    }

    public function testAKeyReplacesTheOneTheVaultHad(): void
    {
        $file = $this->configFile();
        ConfigWriter::setProfileKey($file, 'local', 'sk-first');

        ConfigWriter::setProfileKey($file, 'local', 'sk-second');

        self::assertSame('sk-second', (new Vault(dirname($file)))->get('local'));
    }

    public static function refusals(): iterable
    {
        yield 'a profile that is not in the file' => ['nowhere', 'sk-x', "profile 'nowhere' is not defined"];
        yield 'a profile that reads its key from a file' => ['filed', 'sk-x', 'reads its key from api_key_file'];
        yield 'an empty key' => ['local', '   ', 'api_key must be the key itself'];
        yield 'a key of two lines' => ['local', "sk-a\nsk-b", 'api_key must be the key itself'];
        yield 'no key' => ['local', null, 'no API key was given'];
        yield 'a key that is not text' => ['local', 12, 'api_key must be the key itself'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testAnUnusableRequestChangesNothing(string $profile, mixed $key, string $message): void
    {
        $file = $this->configFile();

        try {
            ConfigWriter::setProfileKey($file, $profile, $key);
            self::fail('the request must be refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
            self::assertStringNotContainsString('sk-', $error->getMessage());
        }
        self::assertSame(self::CONFIG, file_get_contents($file));
        self::assertFileDoesNotExist($file . '.bak');
        self::assertNull((new Vault(dirname($file)))->get('local'));
    }

    public function testTheKeyThatTheVaultHadIsPutBackWhenTheFileCannotBeSaved(): void
    {
        $file = $this->configFile();
        ConfigWriter::setProfileKey($file, 'local', 'sk-old');
        $before = (string) file_get_contents($file);
        $outside = Kit::folder('set-key-outside') . '/target.txt';
        Kit::write($outside, "untouched\n");
        unlink($file . '.bak');
        symlink($outside, $file . '.bak');

        try {
            ConfigWriter::setProfileKey($file, 'local', 'sk-new');
            self::fail('a backup that is a link must be refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('symbolic link', $error->getMessage());
        }
        self::assertSame($before, file_get_contents($file));
        self::assertSame('sk-old', (new Vault(dirname($file)))->get('local'), 'the old key is back');
    }

    public function testAKeyThatWasNotThereIsTakenOutAgainWhenTheFileCannotBeSaved(): void
    {
        $file = $this->configFile();
        $outside = Kit::folder('set-key-outside') . '/target.txt';
        Kit::write($outside, "untouched\n");
        symlink($outside, $file . '.bak');

        try {
            ConfigWriter::setProfileKey($file, 'local', 'sk-new');
            self::fail('a backup that is a link must be refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('symbolic link', $error->getMessage());
        }
        self::assertNull((new Vault(dirname($file)))->get('local'));
    }

    public function testAProfileGoesFromTheFileAndItsKeyFromTheVault(): void
    {
        $file = $this->configFile();
        ConfigWriter::setProfileKey($file, 'cloud', 'sk-gone-soon');

        ConfigWriter::removeProfile($file, 'cloud');

        $saved = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['local', 'filed'], array_keys($saved['profiles']));
        self::assertSame('Ñandú', $saved['note']);
        self::assertNull((new Vault(dirname($file)))->get('cloud'));
    }

    public function testTheDefaultProfileAndOneThatAnotherFallsBackToAreRefused(): void
    {
        $file = Kit::folder('remove-profile') . '/config.json';
        Kit::write($file, json_encode([
            'default_profile' => 'main',
            'profiles' => [
                'main' => ['base_url' => 'http://127.0.0.1:1', 'model' => 'm', 'fallback' => ['spare']],
                'spare' => ['base_url' => 'http://127.0.0.1:2', 'model' => 'm'],
            ],
        ], JSON_THROW_ON_ERROR));
        $before = (string) file_get_contents($file);

        foreach (['main' => 'is the default_profile', 'spare' => "falls back to 'spare'", 'nowhere' => 'is not defined'] as $profile => $message) {
            try {
                ConfigWriter::removeProfile($file, $profile);
                self::fail("{$profile} must be refused");
            } catch (PhycodeException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        self::assertSame($before, file_get_contents($file));
        self::assertFileDoesNotExist($file . '.bak');
    }

    private function configFile(): string
    {
        $file = Kit::folder('set-key') . '/config.json';
        Kit::write($file, self::CONFIG);

        return $file;
    }
}
