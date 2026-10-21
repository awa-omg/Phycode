<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigWriter;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddProfileKeyTest extends TestCase
{
    private const CONFIG = <<<'JSON'
    {
        "default_profile": "local",
        "profiles": {
            "local": {"base_url": "http://127.0.0.1:8082", "model": "qwen-old"}
        }
    }
    JSON;

    private const VALID = [
        'protocol' => 'openai',
        'base_url' => 'https://models.example',
        'api_key_env' => null,
        'model' => 'gpt-new',
    ];

    public function testTheKeyGoesToTheVaultAndNotToTheFile(): void
    {
        [$file, $folder] = $this->configFile();
        $key = 'sk-test-' . bin2hex(random_bytes(8));

        ConfigWriter::addProfile($file, 'cloud', ['api_key' => $key] + self::VALID);

        self::assertStringNotContainsString($key, (string) file_get_contents($file));
        self::assertSame(
            ['base_url' => 'https://models.example', 'protocol' => 'openai', 'model' => 'gpt-new', 'api_key_store' => true],
            $this->profile($file, 'cloud'),
        );
        self::assertSame(['cloud'], (new Vault($folder))->profiles());
        self::assertSame($key, (new Vault($folder))->get('cloud'));
    }

    public function testTheSavedProfileReadsItsKeyFromTheVault(): void
    {
        [$file, $folder] = $this->configFile();
        $key = 'sk-test-' . bin2hex(random_bytes(8));
        ConfigWriter::addProfile($file, 'cloud', ['api_key' => $key] + self::VALID);

        $config = Config::fromArrays($this->decode($file), null, [], $folder);

        self::assertTrue($config->profile('cloud')->keyInVault());
        self::assertSame($key, $config->profile('cloud')->apiKey());
    }

    public function testTheKeyIsTrimmedBeforeItIsStored(): void
    {
        [$file, $folder] = $this->configFile();

        ConfigWriter::addProfile($file, 'cloud', ['api_key' => "  sk-spaced-key-123 \n"] + self::VALID);

        self::assertSame('sk-spaced-key-123', (new Vault($folder))->get('cloud'));
    }

    public static function unusableKeys(): iterable
    {
        yield 'blank' => ['   '];
        yield 'a number' => [12345];
        yield 'two lines' => ["sk-first\nsk-second"];
        yield 'a tab inside' => ["sk-a\tb"];
    }

    #[DataProvider('unusableKeys')]
    public function testAKeyThatIsNotOneLineOfTextIsRefusedAndNeitherFileNorVaultChanges(mixed $key): void
    {
        [$file, $folder] = $this->configFile();
        $before = (string) file_get_contents($file);

        try {
            ConfigWriter::addProfile($file, 'cloud', ['api_key' => $key] + self::VALID);
            self::fail('the key should have been refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('api_key must be the key itself, as one line of text', $error->getMessage());
            self::assertStringNotContainsString('sk-first', $error->getMessage());
        }
        self::assertSame($before, file_get_contents($file));
        self::assertSame(['config.json'], array_values(array_diff(scandir($folder) ?: [], ['.', '..'])), 'no vault file is made');
    }

    public function testAKeyAndAKeyVariableCannotBothBeGiven(): void
    {
        [$file, $folder] = $this->configFile();

        try {
            ConfigWriter::addProfile($file, 'cloud', ['api_key' => 'sk-test-KEY-123456', 'api_key_env' => 'CLOUD_KEY'] + self::VALID);
            self::fail('two key sources should have been refused');
        } catch (PhycodeException $error) {
            self::assertSame('api_key and api_key_env cannot both be set: a profile has one key source', $error->getMessage());
        }
        self::assertSame(['config.json'], array_values(array_diff(scandir($folder) ?: [], ['.', '..'])));
    }

    public function testANullKeyMeansNoKeyAndNoVaultIsMade(): void
    {
        [$file, $folder] = $this->configFile();

        ConfigWriter::addProfile($file, 'cloud', ['api_key' => null] + self::VALID);

        self::assertSame(['base_url' => 'https://models.example', 'protocol' => 'openai', 'model' => 'gpt-new'], $this->profile($file, 'cloud'));
        self::assertSame(['config.json', 'config.json.bak'], array_values(array_diff(scandir($folder) ?: [], ['.', '..'])), 'the backup is the only other file');
    }

    public function testWhenTheFileCannotBeSavedTheKeyIsTakenBackOutOfTheVault(): void
    {
        [$file, $folder] = $this->configFile();
        $outside = Kit::folder('add-profile-key-outside') . '/target.txt';
        Kit::write($outside, "untouched\n");
        symlink($outside, $file . '.bak');

        try {
            ConfigWriter::addProfile($file, 'cloud', ['api_key' => 'sk-test-KEY-123456'] + self::VALID);
            self::fail('a backup that is a link must be refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('symbolic link', $error->getMessage());
        }
        self::assertSame([], (new Vault($folder))->profiles(), 'the key does not stay in the vault');
        self::assertSame(self::CONFIG, file_get_contents($file));
        self::assertSame("untouched\n", file_get_contents($outside));
    }

    private function configFile(): array
    {
        $folder = Kit::folder('add-profile-key');
        Kit::write($folder . '/config.json', self::CONFIG);

        return [$folder . '/config.json', $folder];
    }

    private function decode(string $file): array
    {
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    private function profile(string $file, string $name): array
    {
        return $this->decode($file)['profiles'][$name];
    }
}
