<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigWriter;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddProfileTest extends TestCase
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

    private const VALID = [
        'protocol' => 'openai',
        'base_url' => 'http://127.0.0.1:9000',
        'api_key_env' => null,
        'model' => 'qwen-new',
    ];

    public function testTheProfileIsAddedAfterTheOthersAndEveryOtherKeyKeepsItsPlaceAndValue(): void
    {
        $file = $this->configFile();

        ConfigWriter::addProfile($file, 'remote', self::VALID);

        $expected = json_decode(self::CONFIG, true, 512, JSON_THROW_ON_ERROR);
        $expected['profiles']['remote'] = ['base_url' => 'http://127.0.0.1:9000', 'protocol' => 'openai', 'model' => 'qwen-new'];
        self::assertSame($expected, $this->decode($file), 'assertSame also compares the key order');
    }

    public function testTheOldFileIsKeptAsBakAndNoTemporaryFileIsLeftBehind(): void
    {
        $file = $this->configFile();

        ConfigWriter::addProfile($file, 'remote', self::VALID);

        self::assertSame(self::CONFIG, file_get_contents($file . '.bak'));
        self::assertSame(['config.json', 'config.json.bak'], array_values(array_diff(scandir(dirname($file)), ['.', '..'])));
    }

    public function testTheWrittenProfileIsLoadedByTheConfigLoader(): void
    {
        $file = $this->configFile();

        ConfigWriter::addProfile($file, 'remote', ['api_key_env' => 'REMOTE_KEY'] + self::VALID);

        $profile = Config::fromArrays($this->decode($file), null, [], '/nonexistent')->profile('remote');
        self::assertSame(['http://127.0.0.1:9000', 'openai', 'qwen-new'], [$profile->baseUrl, $profile->protocol(), $profile->model]);
    }

    public function testTheKeyVariableIsWrittenAsItsNameAndOnlyWhenGiven(): void
    {
        $file = $this->configFile();

        ConfigWriter::addProfile($file, 'remote', ['api_key_env' => 'REMOTE_KEY'] + self::VALID);

        self::assertSame(['base_url' => 'http://127.0.0.1:9000', 'protocol' => 'openai', 'model' => 'qwen-new', 'api_key_env' => 'REMOTE_KEY'], $this->profile($file, 'remote'));
    }

    public function testSurroundingSpacesOfTheUrlAndTheModelAreRemoved(): void
    {
        $file = $this->configFile();

        ConfigWriter::addProfile($file, 'remote', ['base_url' => '  http://127.0.0.1:9000  ', 'model' => ' qwen-new '] + self::VALID);

        self::assertSame(['base_url' => 'http://127.0.0.1:9000', 'protocol' => 'openai', 'model' => 'qwen-new'], $this->profile($file, 'remote'));
    }

    public function testAProfileNameOfThirtyTwoCharactersIsAccepted(): void
    {
        $file = $this->configFile();
        $name = '1' . str_repeat('a_', 15) . 'b';

        ConfigWriter::addProfile($file, $name, self::VALID);

        self::assertArrayHasKey($name, $this->decode($file)['profiles']);
    }

    public function testAFileWithoutAProfilesKeyGetsOneAfterItsOtherKeys(): void
    {
        $file = Kit::folder('add-profile-bare') . '/config.json';
        Kit::write($file, '{"permissions": {"mode": "ask"}}');

        ConfigWriter::addProfile($file, 'local', self::VALID);

        self::assertSame(
            ['permissions' => ['mode' => 'ask'], 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9000', 'protocol' => 'openai', 'model' => 'qwen-new']]],
            $this->decode($file),
        );
    }

    public function testAnEmptyProfilesObjectGetsTheFirstProfile(): void
    {
        $file = Kit::folder('add-profile-empty') . '/config.json';
        Kit::write($file, '{"profiles": {}}');

        ConfigWriter::addProfile($file, 'local', self::VALID);

        self::assertSame(['local' => ['base_url' => 'http://127.0.0.1:9000', 'protocol' => 'openai', 'model' => 'qwen-new']], $this->decode($file)['profiles']);
    }

    public function testTheModeOfTheFileAndOfItsBackupIsKept(): void
    {
        $file = $this->configFile();
        chmod($file, 0640);

        ConfigWriter::addProfile($file, 'remote', self::VALID);

        self::assertSame(0640, fileperms($file) & 0777);
        self::assertSame(0640, fileperms($file . '.bak') & 0777);
    }

    public function testAProtocolWithoutAClientIsWrittenToo(): void
    {
        $file = $this->configFile();

        ConfigWriter::addProfile($file, 'claude', ['protocol' => 'anthropic'] + self::VALID);

        self::assertSame('anthropic', $this->profile($file, 'claude')['protocol']);
    }

    public function testTheNameIsLowercaseLettersDigitsDashAndUnderscoreOfUpToThirtyTwoCharacters(): void
    {
        foreach (['Remote', 'my remote', '-remote', '_remote', 'remote.one', '', str_repeat('a', 33)] as $name) {
            try {
                ConfigWriter::addProfile($this->configFile(), $name, self::VALID);
                self::fail("the name '{$name}' should have been refused");
            } catch (PhycodeException $error) {
                self::assertStringContainsString('a profile name is 1 to 32 characters: lowercase letters, digits, - and _', $error->getMessage());
            }
        }
    }

    public function testTheNameMustNotBeTheNameOfAProfileInTheFile(): void
    {
        $this->assertRefused('local', self::VALID, "profile 'local' already exists");
    }

    public static function unknownProtocols(): iterable
    {
        yield 'a typing mistake' => ['opeai'];
        yield 'capitals' => ['OpenAI'];
        yield 'a trailing space' => ['openai '];
        yield 'missing' => [null];
    }

    #[DataProvider('unknownProtocols')]
    public function testTheProtocolIsOpenaiOllamaOpenaiResponsesOrAnthropic(?string $protocol): void
    {
        $this->assertRefused('remote', ['protocol' => $protocol] + self::VALID, 'protocol must be openai, ollama, openai-responses or anthropic');
    }

    public static function unusableUrls(): iterable
    {
        yield 'ftp' => ['ftp://127.0.0.1/models'];
        yield 'no scheme' => ['127.0.0.1:8082'];
        yield 'capitals in the scheme' => ['HTTP://127.0.0.1:8082'];
        yield 'empty' => [''];
        yield 'no host' => ['http:///models'];
        yield 'a space in the host' => ['http://127.0.0.1 8082'];
        yield 'a socket path that is not absolute' => ['unix://run/llama.sock'];
        yield 'a socket that is not a .sock file' => ['unix:///tmp/llama.socket'];
    }

    #[DataProvider('unusableUrls')]
    public function testBaseUrlIsHttpHttpsOrAUnixSocketWithAnAbsolutePath(string $url): void
    {
        $this->assertRefused('remote', ['base_url' => $url] + self::VALID, 'base_url must be an http:// or https:// URL, or unix:///absolute/path.sock');
    }

    public static function urlsWithUserInformation(): iterable
    {
        yield 'a name and a password' => ['https://user:hunter2@models.example/v1'];
        yield 'a name alone' => ['http://user@127.0.0.1:8082'];
        yield 'a password with an at sign' => ['https://user:hun@ter2@models.example'];
    }

    #[DataProvider('urlsWithUserInformation')]
    public function testBaseUrlHasNoUserNameOrPassword(string $url): void
    {
        $message = $this->assertRefused('remote', ['base_url' => $url] + self::VALID, 'base_url must not contain a user name or a password');

        self::assertStringNotContainsString('hunter2', $message);
    }

    public static function urlsWithAQuery(): iterable
    {
        yield 'a key in the query' => ['http://127.0.0.1:8082/v1?key=hunter2'];
        yield 'a bare question mark' => ['https://models.example/?'];
    }

    #[DataProvider('urlsWithAQuery')]
    public function testBaseUrlHasNoQuery(string $url): void
    {
        $message = $this->assertRefused('remote', ['base_url' => $url] + self::VALID, 'base_url must not contain a query');

        self::assertStringNotContainsString('hunter2', $message);
    }

    public static function urlsEndingInV1(): iterable
    {
        yield 'a path that ends in /v1' => ['http://127.0.0.1:8082/v1'];
        yield 'a path that ends in /v1/' => ['https://api.example.com/v1/'];
    }

    #[DataProvider('urlsEndingInV1')]
    public function testBaseUrlDoesNotEndInV1BecausePhycodeAddsIt(string $url): void
    {
        $this->assertRefused('remote', ['base_url' => $url] + self::VALID, 'remove /v1: phycode adds it');
    }

    public function testAHostNamedV1IsAcceptedAsItIs(): void
    {
        $file = $this->configFile();

        ConfigWriter::addProfile($file, 'remote', ['base_url' => 'http://v1', 'model' => 'qwen-new'] + self::VALID);

        self::assertSame('http://v1', $this->profile($file, 'remote')['base_url']);
    }

    public static function invalidKeyVariables(): iterable
    {
        yield 'lower case' => ['cloud_key'];
        yield 'a digit first' => ['1CLOUD'];
        yield 'one letter' => ['A'];
        yield 'a dash' => ['CLOUD-KEY'];
        yield 'a key instead of a name' => ['sk-test-SECRET-123456'];
        yield 'sixty-five characters' => [str_repeat('A', 65)];
    }

    #[DataProvider('invalidKeyVariables')]
    public function testApiKeyEnvIsTheNameOfAnEnvironmentVariableAndNeverAKey(string $name): void
    {
        $message = $this->assertRefused('remote', ['api_key_env' => $name] + self::VALID, 'api_key_env must be the name of an environment variable');

        self::assertStringNotContainsString('SECRET', $message);
    }

    public static function missingModels(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces only' => ['   '];
        yield 'missing' => [null];
    }

    #[DataProvider('missingModels')]
    public function testTheModelIsOptionalAndAProfileWithoutOneIsSavedWithoutAModelKey(?string $model): void
    {
        $file = $this->configFile();

        ConfigWriter::addProfile($file, 'remote', ['model' => $model] + self::VALID);

        self::assertArrayNotHasKey('model', $this->profile($file, 'remote'));
        $config = Config::fromArrays($this->decode($file), null, [], '/nonexistent');
        self::assertSame('', $config->profile('remote')->model, 'the loader reads a profile without a model as one that has none yet');
    }

    public function testAModelNameOfMoreThanOneHundredAndSixtyCharactersIsRefused(): void
    {
        $this->assertRefused('remote', ['model' => str_repeat('m', 161)] + self::VALID, 'the model name may be at most 160 characters');
    }

    public function testAModelNameOfExactlyOneHundredAndSixtyCharactersIsKept(): void
    {
        $file = $this->configFile();

        ConfigWriter::addProfile($file, 'remote', ['model' => str_repeat('m', 160)] + self::VALID);

        self::assertSame(str_repeat('m', 160), $this->profile($file, 'remote')['model']);
    }

    public static function keyFiles(): iterable
    {
        yield 'api_key_file' => ['api_key_file', '/home/me/.key'];
        yield 'api_key_files' => ['api_key_files', ['/home/me/.key']];
    }

    #[DataProvider('keyFiles')]
    public function testAKeyFileIsNeverWrittenToTheConfig(string $key, mixed $value): void
    {
        $this->assertRefused('remote', [$key => $value] + self::VALID, "{$key} cannot be saved in the config");
    }

    public function testAnyOtherSettingIsRefused(): void
    {
        $this->assertRefused('remote', ['thinking' => 'on'] + self::VALID, "'thinking' is not a setting a profile can be added with");
    }

    public function testAConfigFileThatIsALinkIsRefusedAndItsTargetIsNotChanged(): void
    {
        $target = Kit::folder('add-profile-target') . '/config.json';
        Kit::write($target, self::CONFIG);
        $link = Kit::folder('add-profile-link') . '/config.json';
        symlink($target, $link);

        try {
            ConfigWriter::addProfile($link, 'remote', self::VALID);
            self::fail('a config file that is a link must be refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('symbolic link', $error->getMessage());
        }
        self::assertSame(self::CONFIG, file_get_contents($target));
        self::assertFileDoesNotExist($link . '.bak');
    }

    public function testABackupThatIsALinkIsRefusedAndItsTargetIsNotWritten(): void
    {
        $file = $this->configFile();
        $outside = Kit::folder('add-profile-outside') . '/target.txt';
        Kit::write($outside, "untouched\n");
        symlink($outside, $file . '.bak');

        try {
            ConfigWriter::addProfile($file, 'remote', self::VALID);
            self::fail('a backup that is a link must be refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('symbolic link', $error->getMessage());
        }
        self::assertSame("untouched\n", file_get_contents($outside));
        self::assertSame(self::CONFIG, file_get_contents($file), 'the config file is not changed either');
    }

    public function testAMissingConfigIsRefusedAndNoFileIsCreated(): void
    {
        $file = Kit::folder('add-profile-absent') . '/config.json';

        try {
            ConfigWriter::addProfile($file, 'remote', self::VALID);
            self::fail('a missing config must be refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('cannot read', $error->getMessage());
        }
        self::assertFileDoesNotExist($file);
    }

    public static function unusableFiles(): iterable
    {
        yield 'truncated' => ['{"profiles": {"local": {"model": "qwen"}', 'is not valid JSON'];
        yield 'empty' => ['', 'is not valid JSON'];
        yield 'a list' => ['[{"profiles": {}}]', 'must contain a JSON object'];
        yield 'profiles as a list' => ['{"profiles": [{"model": "qwen"}]}', 'profiles in'];
    }

    #[DataProvider('unusableFiles')]
    public function testAFileThatCannotHoldAProfileIsRefusedAndNotTouched(string $content, string $message): void
    {
        $file = Kit::folder('add-profile-invalid') . '/config.json';
        Kit::write($file, $content);

        try {
            ConfigWriter::addProfile($file, 'remote', self::VALID);
            self::fail('the file should have been refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertSame($content, file_get_contents($file));
        self::assertFileDoesNotExist($file . '.bak');
    }

    private function assertRefused(string $name, array $profile, string $message): string
    {
        $file = $this->configFile();
        try {
            ConfigWriter::addProfile($file, $name, $profile);
        } catch (PhycodeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
            self::assertSame(self::CONFIG, file_get_contents($file), 'the file must not change');
            self::assertFileDoesNotExist($file . '.bak');

            return $error->getMessage();
        }
        self::fail('the profile should have been refused');
    }

    private function configFile(): string
    {
        $file = Kit::folder('add-profile') . '/config.json';
        Kit::write($file, self::CONFIG);

        return $file;
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
