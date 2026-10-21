<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Vault;

use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VaultTest extends TestCase
{
    private const KEY = 'sk-test-VAULT-0123456789abcdef';

    private array $environment = [];
    private string $folder;

    protected function setUp(): void
    {
        foreach (['HOME', 'XDG_CONFIG_HOME'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        $this->folder = Kit::folder('vault');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testAStoredKeyComesBackUnchanged(): void
    {
        $this->vault()->store('cloud', self::KEY);

        self::assertSame(self::KEY, $this->vault()->get('cloud'));
        self::assertNull($this->vault()->get('missing'));
    }

    public function testTheStoredValueIsNotInTheFileInClear(): void
    {
        $this->vault()->store('cloud', self::KEY);

        $entries = (string) file_get_contents($this->file('keys.json'));
        self::assertStringNotContainsString(self::KEY, $entries);
        self::assertStringNotContainsString(base64_encode(self::KEY), $entries);
        $master = (string) file_get_contents($this->file('master.key'));
        self::assertStringNotContainsString($master, $entries, 'the master key is not in the file either');
        self::assertStringNotContainsString(base64_encode($master), $entries);
    }

    public function testEachEntryHasItsOwnIvAndTagAndTheFileHasTheDocumentedShape(): void
    {
        $vault = $this->vault();
        $vault->store('cloud', self::KEY);
        $vault->store('local', self::KEY);
        $first = $this->entries();
        $vault->store('cloud', self::KEY);
        $second = $this->entries();

        self::assertSame(['cloud', 'local'], array_keys($first));
        foreach ($first as $entry) {
            self::assertSame(['iv', 'tag', 'data'], array_keys($entry));
            self::assertSame(12, strlen(base64_decode($entry['iv'], true) ?: ''), 'a 12-byte IV');
            self::assertSame(16, strlen(base64_decode($entry['tag'], true) ?: ''), 'a 16-byte tag');
            self::assertNotSame('', base64_decode($entry['data'], true) ?: '');
        }
        self::assertNotSame($first['local']['iv'], $first['cloud']['iv'], 'two values never share an IV');
        self::assertNotSame($first['cloud']['iv'], $second['cloud']['iv'], 'a new store takes a new IV');
        self::assertNotSame($first['cloud']['data'], $second['cloud']['data']);
    }

    public function testTheFilesAreCreatedWithModeZeroSixHundred(): void
    {
        $this->vault()->store('cloud', self::KEY);

        self::assertSame(0600, fileperms($this->file('master.key')) & 0777);
        self::assertSame(0600, fileperms($this->file('keys.json')) & 0777);
        self::assertSame(32, strlen((string) file_get_contents($this->file('master.key'))));
        self::assertSame(['keys.json', 'master.key'], $this->names(), 'no temporary file is left behind');
    }

    public function testAWrongMasterKeyIsRefusedAndTheValueIsNotShown(): void
    {
        $this->vault()->store('cloud', self::KEY);
        file_put_contents($this->file('master.key'), random_bytes(32));

        $message = $this->refusal(fn () => $this->vault()->get('cloud'), 'cannot be decrypted');

        self::assertStringNotContainsString(self::KEY, $message);
    }

    public function testAChangedValueIsRefused(): void
    {
        $this->vault()->store('cloud', self::KEY);
        $entries = $this->entries();
        $data = (string) base64_decode($entries['cloud']['data'], true);
        $data[0] = chr(ord($data[0]) ^ 1);
        $entries['cloud']['data'] = base64_encode($data);
        $this->writeEntries($entries);

        $message = $this->refusal(fn () => $this->vault()->get('cloud'), 'cannot be decrypted');
        self::assertStringNotContainsString(self::KEY, $message);
    }

    public function testAChangedTagIsRefused(): void
    {
        $this->vault()->store('cloud', self::KEY);
        $entries = $this->entries();
        $tag = (string) base64_decode($entries['cloud']['tag'], true);
        $tag[15] = chr(ord($tag[15]) ^ 0x80);
        $entries['cloud']['tag'] = base64_encode($tag);
        $this->writeEntries($entries);

        $this->refusal(fn () => $this->vault()->get('cloud'), 'cannot be decrypted');
    }

    public function testAnEntryMovedUnderAnotherProfileNameIsRefused(): void
    {
        $vault = $this->vault();
        $vault->store('cloud', self::KEY);
        $entries = $this->entries();
        $entries['local'] = $entries['cloud'];
        $this->writeEntries($entries);

        $this->refusal(fn () => $this->vault()->get('local'), 'cannot be decrypted');
        self::assertSame(self::KEY, $this->vault()->get('cloud'));
    }

    public static function modes(): iterable
    {
        yield 'owner only' => [0600, true];
        yield 'read only for the owner' => [0400, true];
        yield 'the group can read' => [0640, false];
        yield 'others can read' => [0604, false];
        yield 'anyone can write' => [0666, false];
        yield 'the owner can run it' => [0700, false];
    }

    #[DataProvider('modes')]
    public function testTheMasterKeyIsOnlyUsedWhenItIsNoWiderThanZeroSixHundred(int $mode, bool $accepted): void
    {
        $this->vault()->store('cloud', self::KEY);
        chmod($this->file('master.key'), $mode);

        if ($accepted) {
            self::assertSame(self::KEY, $this->vault()->get('cloud'));

            return;
        }
        $message = $this->refusal(fn () => $this->vault()->get('cloud'), 'must be readable only by its owner');
        self::assertStringContainsString('chmod 600', $message);
        self::assertStringNotContainsString(self::KEY, $message);
    }

    public function testAWiderMasterKeyIsNotUsedToStoreEither(): void
    {
        $this->vault()->store('cloud', self::KEY);
        chmod($this->file('master.key'), 0644);

        $this->refusal(fn () => $this->vault()->store('local', self::KEY), 'must be readable only by its owner');
        self::assertSame(['cloud'], $this->storedProfiles(), 'the file is not changed');
    }

    public static function keysFileModes(): iterable
    {
        yield 'the group can read' => [0640];
        yield 'anyone can read' => [0644];
    }

    #[DataProvider('keysFileModes')]
    public function testAWiderKeysFileIsRefused(int $mode): void
    {
        $this->vault()->store('cloud', self::KEY);
        chmod($this->file('keys.json'), $mode);

        $this->refusal(fn () => $this->vault()->profiles(), 'must be readable only by its owner');
        $this->refusal(fn () => $this->vault()->get('cloud'), 'must be readable only by its owner');
        $this->refusal(fn () => $this->vault()->store('local', self::KEY), 'must be readable only by its owner');
    }

    public function testASymbolicLinkInPlaceOfTheMasterKeyIsRefused(): void
    {
        $this->vault()->store('cloud', self::KEY);
        $outside = Kit::folder('vault-outside') . '/master.key';
        Kit::write($outside, random_bytes(32));
        $contents = (string) file_get_contents($outside);
        unlink($this->file('master.key'));
        symlink($outside, $this->file('master.key'));

        $this->refusal(fn () => $this->vault()->get('cloud'), 'is a symbolic link');
        $this->refusal(fn () => $this->vault()->store('local', self::KEY), 'is a symbolic link');
        self::assertSame($contents, file_get_contents($outside), 'the target is not changed');
    }

    public function testASymbolicLinkInPlaceOfTheKeysFileIsRefusedAndTheTargetIsNotWritten(): void
    {
        $this->vault()->store('cloud', self::KEY);
        $outside = Kit::folder('vault-outside') . '/keys.json';
        rename($this->file('keys.json'), $outside);
        symlink($outside, $this->file('keys.json'));
        $contents = (string) file_get_contents($outside);

        $this->refusal(fn () => $this->vault()->profiles(), 'is a symbolic link');
        $this->refusal(fn () => $this->vault()->store('local', self::KEY), 'is a symbolic link');
        self::assertSame($contents, file_get_contents($outside));
    }

    public function testProfilesListsTheNamesSortedAndNoValue(): void
    {
        $vault = $this->vault();
        $vault->store('zeta', self::KEY);
        $vault->store('alpha', self::KEY);
        $vault->store('mid', self::KEY);

        $names = $vault->profiles();

        self::assertSame(['alpha', 'mid', 'zeta'], $names);
        self::assertStringNotContainsString(self::KEY, implode(' ', $names));
    }

    public function testRemoveDeletesOneKeyAndKeepsTheOthers(): void
    {
        $vault = $this->vault();
        $vault->store('cloud', self::KEY);
        $vault->store('local', 'sk-test-OTHER-0123456789');

        self::assertTrue($vault->remove('cloud'));
        self::assertNull($vault->get('cloud'));
        self::assertSame('sk-test-OTHER-0123456789', $vault->get('local'));
        self::assertFalse($vault->remove('cloud'), 'a second remove finds nothing');
        self::assertFalse($vault->remove('never-stored'));
    }

    public function testReadsCreateNothing(): void
    {
        $vault = $this->vault();

        self::assertNull($vault->get('cloud'));
        self::assertSame([], $vault->profiles());
        self::assertFalse($vault->remove('cloud'));
        self::assertSame([], $this->names());
    }

    public function testAStoredKeyCannotBeReadWhileItsMasterKeyIsMissing(): void
    {
        $vault = $this->vault();
        $vault->store('cloud', self::KEY);
        unlink($this->file('master.key'));

        $this->refusal(fn () => $this->vault()->get('cloud'), 'master.key is missing');
        $this->refusal(fn () => $this->vault()->store('local', self::KEY), 'master.key is missing');
        self::assertSame(['cloud'], $this->storedProfiles(), 'the stored key is kept');
        self::assertFileDoesNotExist($this->file('master.key'), 'no new master key replaces the old one');
    }

    public function testAMasterKeyOfTheWrongLengthIsRefused(): void
    {
        $this->vault()->store('cloud', self::KEY);
        file_put_contents($this->file('master.key'), str_repeat('k', 31));

        $this->refusal(fn () => $this->vault()->get('cloud'), 'does not hold a 32-byte key');
    }

    public function testTheSameProfileGetsTheNewKeyWhenItIsStoredAgain(): void
    {
        $vault = $this->vault();
        $vault->store('cloud', 'sk-test-FIRST-0123456789');
        $vault->store('cloud', 'sk-test-SECOND-0123456789');

        self::assertSame('sk-test-SECOND-0123456789', $vault->get('cloud'));
        self::assertSame(['cloud'], $vault->profiles());
    }

    public static function unusableKeys(): iterable
    {
        yield 'blank' => ["   \n", 'no API key was given'];
        yield 'two lines' => ["sk-first-line\nsk-second", 'an API key is one line of text'];
    }

    #[DataProvider('unusableKeys')]
    public function testAKeyThatIsNotOneLineIsRefusedWithoutRepeatingIt(string $key, string $message): void
    {
        $shown = $this->refusal(fn () => $this->vault()->store('cloud', $key), $message);

        self::assertStringNotContainsString('sk-first', $shown);
        self::assertSame([], $this->names(), 'nothing is written');
    }

    public static function badProfileNames(): iterable
    {
        yield 'empty' => [''];
        yield 'a line break' => ["cloud\nx"];
        yield 'too long' => [str_repeat('p', 129)];
    }

    #[DataProvider('badProfileNames')]
    public function testAProfileNameMustBeOneLineOfUpToOneHundredAndTwentyEightCharacters(string $name): void
    {
        $this->refusal(fn () => $this->vault()->store($name, self::KEY), 'a profile name is 1 to 128 characters');
    }

    public function testAProfileNamedZeroRoundTrips(): void
    {
        $this->vault()->store('0', self::KEY);

        self::assertSame(self::KEY, $this->vault()->get('0'));
        self::assertSame(['0'], $this->vault()->profiles());
        self::assertTrue($this->vault()->remove('0'));
        self::assertSame([], $this->vault()->profiles());
    }

    public static function damagedFiles(): iterable
    {
        yield 'not JSON' => ['{"cloud": {', 'is not valid JSON'];
        yield 'a list' => ['[{"iv": "x"}]', 'must contain a JSON object'];
    }

    #[DataProvider('damagedFiles')]
    public function testAKeysFileThatIsNotAJsonObjectIsRefused(string $content, string $message): void
    {
        file_put_contents($this->file('keys.json'), $content);
        chmod($this->file('keys.json'), 0600);

        $this->refusal(fn () => $this->vault()->profiles(), $message);
    }

    public function testAnEntryThatIsNotBase64IsReportedAsDamaged(): void
    {
        $this->vault()->store('cloud', self::KEY);
        $entries = $this->entries();
        $entries['cloud'] = ['iv' => '%%%', 'tag' => '', 'data' => ''];
        $this->writeEntries($entries);

        $this->refusal(fn () => $this->vault()->get('cloud'), 'is damaged');
    }

    public function testTheUserVaultIsInXdgConfigHomeOrElseInTheHomeConfigFolder(): void
    {
        $home = Kit::folder('vault-home');
        $xdg = Kit::folder('vault-xdg');
        putenv("HOME={$home}");
        putenv("XDG_CONFIG_HOME={$xdg}");

        Vault::inUserConfig()->store('cloud', self::KEY);

        self::assertFileExists("{$xdg}/phycode/keys.json");
        self::assertFileDoesNotExist("{$home}/.config/phycode/keys.json");

        putenv('XDG_CONFIG_HOME');
        Vault::inUserConfig()->store('local', self::KEY);

        self::assertFileExists("{$home}/.config/phycode/keys.json");
        self::assertSame(self::KEY, Vault::inUserConfig()->get('local'));
    }

    private function vault(): Vault
    {
        return new Vault($this->folder);
    }

    private function file(string $name): string
    {
        return $this->folder . '/' . $name;
    }

    private function names(): array
    {
        $names = array_values(array_diff(scandir($this->folder) ?: [], ['.', '..']));
        sort($names);

        return $names;
    }

    private function storedProfiles(): array
    {
        return array_map('strval', array_keys($this->entries()));
    }

    private function entries(): array
    {
        return json_decode((string) file_get_contents($this->file('keys.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeEntries(array $entries): void
    {
        file_put_contents($this->file('keys.json'), json_encode($entries, JSON_THROW_ON_ERROR));
    }

    private function refusal(callable $call, string $fragment): string
    {
        try {
            $call();
        } catch (PhycodeException $error) {
            self::assertStringContainsString($fragment, $error->getMessage());

            return $error->getMessage();
        }
        self::fail("the call should have been refused with: {$fragment}");
    }
}
