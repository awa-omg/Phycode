<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\ConfigWriter;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigWriterTest extends TestCase
{
    private const CONFIG = <<<'JSON'
    {
        "default_profile": "local",
        "profiles": {
            "local": {
                "base_url": "http://127.0.0.1:8082",
                "model": "qwen-old",
                "context_limit": 32768,
                "sampling": {"temperature": 0.6, "top_p": 0.95}
            },
            "cloud": {
                "base_url": "https://api.example.com/v1",
                "model": "gpt-old",
                "api_key_env": "CLOUD_KEY",
                "extra_body": {}
            }
        },
        "permissions": {"mode": "ask"},
        "note": "Ñandú / listo"
    }
    JSON;

    public function testOnlyTheModelChangesAndEveryOtherKeyKeepsItsPlaceAndValue(): void
    {
        $file = $this->configFile();

        ConfigWriter::setProfileModel($file, 'local', 'qwen-new');

        $expected = json_decode(self::CONFIG, true, 512, JSON_THROW_ON_ERROR);
        $expected['profiles']['local']['model'] = 'qwen-new';
        self::assertSame($expected, $this->decode($file), 'assertSame also compares the key order');
    }

    public function testTheOldFileIsKeptAsBakAndTheNextSaveReplacesIt(): void
    {
        $file = $this->configFile();

        ConfigWriter::setProfileModel($file, 'local', 'qwen-new');
        self::assertSame(self::CONFIG, file_get_contents($file . '.bak'));

        $first = (string) file_get_contents($file);
        ConfigWriter::setProfileModel($file, 'local', 'qwen-newer');
        self::assertSame($first, file_get_contents($file . '.bak'));
    }

    public function testSlashesUnicodeAndEmptyObjectsAreWrittenAsTheyWere(): void
    {
        $file = $this->configFile();

        ConfigWriter::setProfileModel($file, 'cloud', 'gpt-new');

        $written = (string) file_get_contents($file);
        self::assertStringContainsString('"base_url": "https://api.example.com/v1"', $written);
        self::assertStringContainsString('"note": "Ñandú / listo"', $written);
        self::assertStringContainsString('"extra_body": {}', $written);
    }

    public function testThinkingIsWrittenOnlyWhenItIsGiven(): void
    {
        $file = $this->configFile();

        ConfigWriter::setProfileModel($file, 'local', 'qwen-new');
        self::assertArrayNotHasKey('thinking', $this->profile($file, 'local'));

        ConfigWriter::setProfileModel($file, 'local', 'qwen-new', 'high');
        self::assertSame('high', $this->profile($file, 'local')['thinking']);

        ConfigWriter::setProfileModel($file, 'local', 'qwen-other');
        self::assertSame('high', $this->profile($file, 'local')['thinking'], 'a save without a level keeps the level it has');
        self::assertSame('qwen-other', $this->profile($file, 'local')['model']);
    }

    public function testAMissingProfileIsRefusedAndTheFileIsLeftAsItWas(): void
    {
        $file = $this->configFile();

        $message = $this->assertRefused($file, static fn () => ConfigWriter::setProfileModel($file, 'missing', 'qwen-new'));

        self::assertStringContainsString("profile 'missing'", $message);
    }

    public function testAMissingFileIsRefusedAndNoFileIsCreated(): void
    {
        $file = Kit::folder('config-writer-absent') . '/config.json';

        $message = $this->assertRefused($file, static fn () => ConfigWriter::setProfileModel($file, 'local', 'qwen-new'));

        self::assertStringContainsString('cannot read', $message);
    }

    public static function unusableFiles(): iterable
    {
        yield 'truncated' => ['{"profiles": {"local": {"model": "qwen"}'];
        yield 'not json' => ['profiles: local'];
        yield 'empty' => [''];
        yield 'a list' => ['[{"profiles": {"local": {"model": "qwen"}}}]'];
        yield 'profiles as a list' => ['{"profiles": [{"model": "qwen"}]}'];
    }

    #[DataProvider('unusableFiles')]
    public function testAFileThatIsNotAJsonObjectIsRefusedAndNotTouched(string $content): void
    {
        $file = Kit::folder('config-writer-invalid') . '/config.json';
        Kit::write($file, $content);

        $this->assertRefused($file, static fn () => ConfigWriter::setProfileModel($file, 'local', 'qwen-new'));
    }

    public static function rejectedValues(): iterable
    {
        yield 'unknown thinking level' => ['qwen-new', 'ultra'];
        yield 'empty model' => ['  ', null];
    }

    #[DataProvider('rejectedValues')]
    public function testAValueTheConfigWouldNotLoadIsRefused(string $model, ?string $thinking): void
    {
        $file = $this->configFile();

        $this->assertRefused($file, static fn () => ConfigWriter::setProfileModel($file, 'local', $model, $thinking));
    }

    public function testThePermissionsOfTheFileAreKept(): void
    {
        $file = $this->configFile();
        chmod($file, 0600);

        ConfigWriter::setProfileModel($file, 'local', 'qwen-new');

        self::assertSame(0600, fileperms($file) & 0777);
    }

    public function testNoTemporaryFileIsLeftNextToTheConfig(): void
    {
        $file = $this->configFile();

        ConfigWriter::setProfileModel($file, 'local', 'qwen-new');
        ConfigWriter::setProfileModel($file, 'local', 'qwen-newer');

        self::assertSame(['config.json', 'config.json.bak'], array_values(array_diff(scandir(dirname($file)), ['.', '..'])));
    }

    public function testABackupThatIsALinkIsRefusedAndItsTargetIsNotWritten(): void
    {
        $file = $this->configFile();
        $outside = Kit::folder('config-writer-outside') . '/target.txt';
        Kit::write($outside, "untouched\n");
        symlink($outside, $file . '.bak');

        try {
            ConfigWriter::setProfileModel($file, 'local', 'qwen-new');
            self::fail('a backup that is a link must be refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('symbolic link', $error->getMessage());
        }
        self::assertSame("untouched\n", file_get_contents($outside));
        self::assertSame(self::CONFIG, file_get_contents($file), 'the config file is not changed either');
    }

    public function testAConfigThatIsALinkIsWrittenThroughTheLinkWhichStaysInPlace(): void
    {
        $target = Kit::folder('config-writer-target') . '/config.json';
        Kit::write($target, self::CONFIG);
        $link = Kit::folder('config-writer-link') . '/config.json';
        symlink($target, $link);

        ConfigWriter::setProfileModel($link, 'local', 'qwen-new');

        self::assertTrue(is_link($link), 'the link is kept');
        self::assertSame('qwen-new', $this->decode($target)['profiles']['local']['model']);
        self::assertSame(self::CONFIG, file_get_contents($link . '.bak'));
    }

    public function testTheConfigAndItsBackupKeepTheModeOfTheConfig(): void
    {
        $file = $this->configFile();
        chmod($file, 0640);

        ConfigWriter::setProfileModel($file, 'local', 'qwen-new');

        self::assertSame(0640, fileperms($file) & 0777);
        self::assertSame(0640, fileperms($file . '.bak') & 0777, 'the backup does not get the default mode');
    }

    private function configFile(): string
    {
        $file = Kit::folder('config-writer') . '/config.json';
        Kit::write($file, self::CONFIG);

        return $file;
    }

    private function assertRefused(string $file, callable $write): string
    {
        $before = is_file($file) ? file_get_contents($file) : null;
        try {
            $write();
        } catch (PhycodeException $error) {
            self::assertSame($before, is_file($file) ? file_get_contents($file) : null, 'the file must not change');
            self::assertFileDoesNotExist($file . '.bak');

            return $error->getMessage();
        }
        self::fail('the write should have been refused');
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
