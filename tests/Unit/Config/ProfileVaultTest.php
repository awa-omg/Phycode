<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileVaultTest extends TestCase
{
    private const KEY = 'sk-test-PROFILE-0123456789';

    private function config(array $profile, string $folder): Config
    {
        return Config::fromArrays(['profiles' => ['cloud' => ['base_url' => 'https://models.example/v1', 'model' => 'gpt'] + $profile]], null, [], $folder);
    }

    public function testTheProfileReadsTheKeyStoredInTheVaultOfItsConfigFolder(): void
    {
        $folder = Kit::folder('profile-vault');
        (new Vault($folder))->store('cloud', self::KEY);

        $profile = $this->config(['api_key_store' => true], $folder)->profile('cloud');

        self::assertTrue($profile->keyInVault());
        self::assertSame(self::KEY, $profile->apiKey());
    }

    public function testAProfileWithoutAStoredKeySendsNoKey(): void
    {
        $profile = $this->config(['api_key_store' => true], Kit::folder('profile-empty'))->profile('cloud');

        self::assertNull($profile->apiKey());
    }

    public function testTheKeyIsReadAgainEachTimeSoAChangedKeyIsUsedAtOnce(): void
    {
        $folder = Kit::folder('profile-live');
        $profile = $this->config(['api_key_store' => true], $folder)->profile('cloud');
        (new Vault($folder))->store('cloud', 'sk-test-FIRST-0123456789');
        self::assertSame('sk-test-FIRST-0123456789', $profile->apiKey());

        (new Vault($folder))->store('cloud', 'sk-test-SECOND-0123456789');
        self::assertSame('sk-test-SECOND-0123456789', $profile->apiKey());
    }

    public function testAnOverriddenProfileKeepsItsVaultKey(): void
    {
        $folder = Kit::folder('profile-override');
        (new Vault($folder))->store('cloud', self::KEY);

        $moved = $this->config(['api_key_store' => true], $folder)->profile('cloud')->override('https://other.example/v1', 'other');

        self::assertTrue($moved->keyInVault());
        self::assertSame(self::KEY, $moved->apiKey());
    }

    public function testAProfileWithTheFlagOffDoesNotReadTheVault(): void
    {
        $folder = Kit::folder('profile-flag-off');
        (new Vault($folder))->store('cloud', self::KEY);

        $profile = $this->config(['api_key_store' => false], $folder)->profile('cloud');

        self::assertFalse($profile->keyInVault());
        self::assertNull($profile->apiKey());
    }

    public static function badKeySources(): iterable
    {
        $combined = 'api_key_store cannot be combined with api_key_env, api_key_file or api_key_files';
        yield 'with api_key_env' => [['api_key_store' => true, 'api_key_env' => 'CLOUD_KEY'], $combined];
        yield 'with api_key_file' => [['api_key_store' => true, 'api_key_file' => '/keys/cloud'], $combined];
        yield 'with api_key_files' => [['api_key_store' => true, 'api_key_files' => ['/keys/cloud']], $combined];
        yield 'as text' => [['api_key_store' => 'yes'], "profile 'cloud': api_key_store must be true or false"];
        yield 'with an inline key' => [['api_key_store' => true, 'api_key' => 'sk-test-INLINE-123456'], 'api_key cannot be written in the config'];
    }

    #[DataProvider('badKeySources')]
    public function testTheKeySourcesOfAProfileAreChecked(array $profile, string $message): void
    {
        try {
            $this->config($profile, Kit::folder('profile-bad'));
            self::fail('the profile should have been refused');
        } catch (ConfigException $error) {
            self::assertStringContainsString($message, $error->getMessage());
            self::assertStringNotContainsString('sk-test-INLINE', $error->getMessage());
        }
    }

    public function testAVaultThatCannotBeReadMakesTheKeyFailWithItsReason(): void
    {
        $folder = Kit::folder('profile-broken');
        (new Vault($folder))->store('cloud', self::KEY);
        chmod($folder . '/master.key', 0644);
        $profile = $this->config(['api_key_store' => true], $folder)->profile('cloud');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('must be readable only by its owner');
        $profile->apiKey();
    }
}
