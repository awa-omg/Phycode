<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Support;

use Phycode\Support\PhycodeException;
use Phycode\Support\StateStore;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateStoreTest extends TestCase
{
    private string|false $previousHome;
    private string|false $previousStateHome;

    protected function setUp(): void
    {
        $this->previousHome = getenv('HOME');
        $this->previousStateHome = getenv('XDG_STATE_HOME');
    }

    protected function tearDown(): void
    {
        self::restoreEnvironment('HOME', $this->previousHome);
        self::restoreEnvironment('XDG_STATE_HOME', $this->previousStateHome);
    }

    public function testAMissingFileReadsAsEmptyAndReadingCreatesNothing(): void
    {
        $file = $this->file();

        $store = new StateStore($file);

        self::assertSame([], $store->favorites());
        self::assertSame([], $store->recents());
        self::assertFileDoesNotExist($file);
    }

    public function testFavoritesAreAddedOnceAndCanBeRemoved(): void
    {
        $store = new StateStore($this->file());

        $store->setFavorite('local', 'qwen', true);
        $store->setFavorite('local', 'qwen', true);
        $store->setFavorite('cloud', 'gpt', true);
        self::assertSame([
            ['profile' => 'local', 'model' => 'qwen'],
            ['profile' => 'cloud', 'model' => 'gpt'],
        ], $store->favorites());

        $store->setFavorite('local', 'qwen', false);
        $store->setFavorite('local', 'missing', false);
        self::assertSame([['profile' => 'cloud', 'model' => 'gpt']], $store->favorites());
    }

    public function testRecentsAreNewestFirstWithoutDuplicates(): void
    {
        $store = new StateStore($this->file());

        $store->addRecent('local', 'qwen');
        $store->addRecent('cloud', 'gpt');
        $store->addRecent('local', 'qwen');

        self::assertSame([
            ['profile' => 'local', 'model' => 'qwen'],
            ['profile' => 'cloud', 'model' => 'gpt'],
        ], $store->recents());
    }

    public function testRecentsKeepOnlyTheTenNewest(): void
    {
        $store = new StateStore($this->file());

        for ($index = 1; $index <= 12; $index++) {
            $store->addRecent('local', "model-{$index}");
        }

        $models = array_column($store->recents(), 'model');
        self::assertCount(10, $models);
        self::assertSame('model-12', $models[0]);
        self::assertSame('model-3', $models[9]);
    }

    public function testTwoStoresOnTheSameFileSeeEachOthersChanges(): void
    {
        $file = $this->file();
        $writer = new StateStore($file);
        $reader = new StateStore($file);

        $writer->setFavorite('local', 'qwen', true);
        $writer->addRecent('local', 'qwen');

        self::assertSame([['profile' => 'local', 'model' => 'qwen']], $reader->favorites());
        self::assertSame([['profile' => 'local', 'model' => 'qwen']], $reader->recents());
    }

    public static function corruptFiles(): iterable
    {
        yield 'truncated' => ['{"favorites": [{"profile": "local", "mo'];
        yield 'not json' => ['favorites: local'];
        yield 'empty' => [''];
        yield 'a list' => ['[1, 2, 3]'];
        yield 'a number' => ['42'];
    }

    #[DataProvider('corruptFiles')]
    public function testACorruptFileReadsAsEmptyAndTheNextWriteReplacesIt(string $content): void
    {
        $file = $this->file();
        file_put_contents($file, $content);
        $store = new StateStore($file);

        self::assertSame([], $store->favorites());
        self::assertSame([], $store->recents());

        $store->addRecent('local', 'qwen');

        self::assertSame([['profile' => 'local', 'model' => 'qwen']], $store->recents());
        self::assertJson((string) file_get_contents($file));
    }

    public function testEntriesThatAreNotProfileAndModelPairsAreDropped(): void
    {
        $file = $this->file();
        file_put_contents($file, json_encode([
            'favorites' => [['profile' => 'local', 'model' => 'qwen'], 'junk', ['profile' => 'cloud'], ['profile' => 'local', 'model' => 'qwen']],
            'recents' => 'oops',
        ], JSON_THROW_ON_ERROR));

        $store = new StateStore($file);

        self::assertSame([['profile' => 'local', 'model' => 'qwen']], $store->favorites());
        self::assertSame([], $store->recents());
    }

    public function testAWriteLeavesNoTemporaryFileBehind(): void
    {
        $file = $this->file();
        $store = new StateStore($file);

        $store->addRecent('local', 'qwen');
        $store->setFavorite('local', 'qwen', true);
        $store->addRecent('cloud', 'gpt');

        self::assertSame(['state.json'], array_values(array_diff(scandir(dirname($file)), ['.', '..'])));
    }

    public function testAStateFileThatIsALinkIsNeitherReadNorWrittenThrough(): void
    {
        $original = '{"favorites":[{"profile":"x","model":"y"}]}';
        $outside = Kit::folder('state-outside') . '/target.json';
        Kit::write($outside, $original);
        $file = $this->file();
        symlink($outside, $file);
        $store = new StateStore($file);

        self::assertSame([], $store->favorites(), 'a link reads as no state');
        try {
            $store->addRecent('local', 'qwen');
            self::fail('a link must not be written through');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('symbolic link', $error->getMessage());
        }
        self::assertSame($original, file_get_contents($outside));
    }

    public function testTheStateFileIsPrivate(): void
    {
        $file = $this->file();

        (new StateStore($file))->addRecent('local', 'qwen');

        self::assertSame(0600, fileperms($file) & 0777);
    }

    public function testTheFolderIsCreatedOnTheFirstWrite(): void
    {
        $file = Kit::folder('state-nested') . '/phycode/state.json';

        (new StateStore($file))->addRecent('local', 'qwen');

        self::assertFileExists($file);
    }

    public function testTheDefaultFileIsInsideXdgStateHome(): void
    {
        $state = Kit::folder('xdg-state');
        putenv('XDG_STATE_HOME=' . $state);

        self::assertSame($state . '/phycode/state.json', StateStore::defaultFile());

        putenv('XDG_STATE_HOME=' . $state . '/');
        self::assertSame($state . '/phycode/state.json', StateStore::defaultFile(), 'a trailing slash is not doubled');
    }

    public function testWithoutXdgStateHomeTheDefaultIsUnderHomeLocalState(): void
    {
        $home = Kit::folder('home');
        putenv('HOME=' . $home);
        putenv('XDG_STATE_HOME');

        self::assertSame($home . '/.local/state/phycode/state.json', StateStore::defaultFile());
        self::assertDirectoryDoesNotExist($home . '/.local', 'the default file is computed, not created');
    }

    public function testAnEmptyXdgStateHomeCountsAsUnset(): void
    {
        $home = Kit::folder('home-empty-xdg');
        putenv('HOME=' . $home);
        putenv('XDG_STATE_HOME=');

        self::assertSame($home . '/.local/state/phycode/state.json', StateStore::defaultFile());
    }

    public function testCustomModelsAreAddedOnceInOrderAndCanBeRemoved(): void
    {
        $store = new StateStore($this->file());

        $store->addCustomModel('local', 'qwen3:4b');
        $store->addCustomModel('local', 'mistral:7b');
        $store->addCustomModel('local', 'qwen3:4b');
        self::assertSame(['qwen3:4b', 'mistral:7b'], $store->customModels('local'));

        $store->removeCustomModel('local', 'qwen3:4b');
        $store->removeCustomModel('local', 'missing');
        self::assertSame(['mistral:7b'], $store->customModels('local'));
    }

    public function testCustomModelsBelongToTheirProfile(): void
    {
        $store = new StateStore($this->file());

        $store->addCustomModel('local', 'qwen');
        $store->addCustomModel('cloud', 'gpt-4o');

        self::assertSame(['qwen'], $store->customModels('local'));
        self::assertSame(['gpt-4o'], $store->customModels('cloud'));
        self::assertSame([], $store->customModels('nowhere'));
    }

    public static function modelIdsOutsideTheRule(): iterable
    {
        yield 'empty' => [''];
        yield 'a space' => ['qwen 3'];
        yield 'a quote' => ['qwen"x'];
        yield 'a semicolon' => ['qwen;rm'];
        yield 'a dollar sign' => ['qwen$'];
        yield 'a newline' => ["qwen\n"];
        yield 'a non-ASCII letter' => ['qwén'];
        yield 'one character too many' => [str_repeat('a', 161)];
    }

    #[DataProvider('modelIdsOutsideTheRule')]
    public function testAModelIdOutsideTheRuleIsRefusedAndNothingIsWritten(string $model): void
    {
        $file = $this->file();
        $store = new StateStore($file);

        foreach (['addCustomModel', 'removeCustomModel'] as $method) {
            try {
                $store->{$method}('local', $model);
                self::fail("{$method} must refuse the id");
            } catch (PhycodeException $error) {
                self::assertStringContainsString('a custom model id may use', $error->getMessage());
            }
        }
        self::assertFileDoesNotExist($file);
    }

    public function testAModelIdOfExactlyTheLimitIsKept(): void
    {
        $store = new StateStore($this->file());
        $id = str_repeat('a', 160);

        $store->addCustomModel('local', $id);

        self::assertSame([$id], $store->customModels('local'));
    }

    public function testCustomModelsKeepTheFavoritesAndRecents(): void
    {
        $store = new StateStore($this->file());
        $store->setFavorite('local', 'qwen', true);
        $store->addRecent('local', 'qwen');

        $store->addCustomModel('local', 'mistral');
        $store->removeCustomModel('local', 'mistral');

        self::assertSame([['profile' => 'local', 'model' => 'qwen']], $store->favorites());
        self::assertSame([['profile' => 'local', 'model' => 'qwen']], $store->recents());
        self::assertSame([], $store->customModels('local'));
    }

    public function testCustomIdsThatBreakTheRuleInTheFileAreDropped(): void
    {
        $file = $this->file();
        file_put_contents($file, json_encode(['custom' => [
            ['profile' => 'local', 'model' => 'qwen'],
            ['profile' => 'local', 'model' => 'bad id'],
            ['profile' => 'local', 'model' => 'mistral'],
        ]], JSON_THROW_ON_ERROR));

        self::assertSame(['qwen', 'mistral'], (new StateStore($file))->customModels('local'));
    }

    public function testTwoStoresOnTheSameFileSeeEachOthersCustomModels(): void
    {
        $file = $this->file();

        (new StateStore($file))->addCustomModel('local', 'qwen');

        self::assertSame(['qwen'], (new StateStore($file))->customModels('local'));
    }

    private function file(): string
    {
        return Kit::folder('state') . '/state.json';
    }

    private static function restoreEnvironment(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
}
