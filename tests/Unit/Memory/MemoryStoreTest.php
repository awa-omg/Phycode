<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Memory;

use Phycode\Memory\MemoryStore;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemoryStoreTest extends TestCase
{
    private string $root;
    private string $config;
    private MemoryStore $store;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('memory-project'));
        $this->config = Kit::folder('memory-config');
        $this->store = new MemoryStore($this->root, $this->config);
    }

    public static function names(): iterable
    {
        yield 'plain' => ['test-command', 'test-command'];
        yield 'spaces and capitals' => ['  Test Command  ', 'test-command'];
        yield 'punctuation' => ['db: migrate!', 'db-migrate'];
        yield 'path-like' => ['../../etc/passwd', 'etc-passwd'];
        yield 'long' => [str_repeat('a', 100), str_repeat('a', 60)];
    }

    #[DataProvider('names')]
    public function testANameBecomesASafeFileName(string $name, string $slug): void
    {
        self::assertSame($slug, MemoryStore::slug($name));
    }

    public function testANameWithoutLettersIsRefused(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('needs letters or digits');
        MemoryStore::slug('!!!');
    }

    public function testANoteIsSavedAndReadBackInItsScopeOnly(): void
    {
        $slug = $this->store->save('project', 'Test Command', 'How the tests run here', "Run vendor/bin/phpunit.\nIt takes three minutes.");

        self::assertSame('test-command', $slug);
        self::assertSame(
            ['name' => 'test-command', 'description' => 'How the tests run here', 'body' => "Run vendor/bin/phpunit.\nIt takes three minutes.", 'scope' => 'project'],
            $this->store->read('project', 'test-command'),
        );
        self::assertNull($this->store->read('user', 'test-command'));
        self::assertFileExists($this->root . '/.phycode/memory/test-command.md');
        self::assertSame("*\n", file_get_contents($this->root . '/.phycode/.gitignore'));
        self::assertSame(0600, fileperms($this->root . '/.phycode/memory/test-command.md') & 0777);
    }

    public function testTheUserScopeLivesInTheConfigFolder(): void
    {
        $this->store->save('user', 'editor', 'The editor the user likes', 'Neovim.');

        self::assertFileExists($this->config . '/memory/editor.md');
        self::assertSame('Neovim.', $this->store->read('user', 'editor')['body']);
    }

    public function testSavingAgainReplacesTheNoteAndTheNewestNoteIsListedFirst(): void
    {
        $this->store->save('project', 'a', 'First', 'one');
        touch($this->root . '/.phycode/memory/a.md', time() - 100);
        $this->store->save('project', 'b', 'Second', 'two');
        $this->store->save('project', 'a', 'First again', 'three');

        self::assertSame(['a', 'b'], array_column($this->store->all('project'), 'name'));
        self::assertSame('three', $this->store->read('project', 'a')['body']);
    }

    public function testADescriptionWithQuotesAndColonsSurvivesTheFile(): void
    {
        $description = 'Use "make test": it runs # all of them';
        $this->store->save('project', 'quotes', $description, 'body');

        self::assertSame($description, $this->store->read('project', 'quotes')['description']);
    }

    public static function badNotes(): iterable
    {
        yield 'empty description' => [['description' => ' '], 'description is one line'];
        yield 'two lines' => [['description' => "a\nb"], 'description is one line'];
        yield 'long description' => [['description' => str_repeat('d', 151)], 'at most 150 characters'];
        yield 'long body' => [['body' => str_repeat('b', 2001)], 'at most 2000 characters'];
        yield 'nul byte' => [['body' => "a\0b"], 'at most 2000 characters'];
        yield 'openai key' => [['body' => 'use sk-abcdefghijklmnop for it'], 'looks like it holds a key'];
        yield 'github token' => [['body' => 'ghp_abcdefghijklmnopqrstuvwxyz0123'], 'looks like it holds a key'];
        yield 'private key' => [['body' => '-----BEGIN RSA PRIVATE KEY-----'], 'looks like it holds a key'];
        yield 'password' => [['description' => 'password: hunter2hunter2'], 'looks like it holds a key'];
        yield 'aws key' => [['body' => 'AKIAABCDEFGHIJKLMNOP'], 'looks like it holds a key'];
    }

    #[DataProvider('badNotes')]
    public function testANoteThatIsTooBigOrHoldsASecretIsNotSaved(array $fields, string $message): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage($message);

        try {
            $this->store->save('project', 'bad', $fields['description'] ?? 'fine', $fields['body'] ?? 'fine');
        } finally {
            self::assertNull($this->store->read('project', 'bad'));
        }
    }

    public function testASecretOfTheSessionIsRefusedEvenWhenItLooksLikeNothing(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('looks like it holds a key');
        $this->store->save('project', 'host', 'The host', 'login with zebra-moon-42', ['zebra-moon-42']);
    }

    public function testAScopeThatDoesNotExistIsRefused(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage("scope is project or user, not 'team'");
        $this->store->save('team', 'x', 'y', 'z');
    }

    public function testTheLimitOfNotesStopsANewNoteButNotTheReplacementOfOne(): void
    {
        for ($i = 0; $i < MemoryStore::MAX_NOTES; $i++) {
            $this->store->save('project', "note-{$i}", 'd', 'b');
        }

        $this->store->save('project', 'note-3', 'replaced', 'b');
        self::assertSame('replaced', $this->store->read('project', 'note-3')['description']);
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('100 notes are saved in the project scope already');
        $this->store->save('project', 'one-more', 'd', 'b');
    }

    public function testForgettingRemovesTheFileAndSaysWhenThereWasNone(): void
    {
        $this->store->save('project', 'gone', 'd', 'b');

        self::assertTrue($this->store->forget('project', 'gone'));
        self::assertFileDoesNotExist($this->root . '/.phycode/memory/gone.md');
        self::assertFalse($this->store->forget('project', 'gone'));
        self::assertFalse($this->store->forget('project', '../config'));
    }

    public function testAFileThatIsALinkOrNotANoteIsNotRead(): void
    {
        $this->store->save('project', 'real', 'd', 'b');
        $folder = $this->root . '/.phycode/memory';
        symlink(Kit::folder('memory-elsewhere'), $folder . '/linked.md');
        file_put_contents($folder . '/broken.md', "no frontmatter at all\n");
        file_put_contents($folder . '/Upper.md', "---\nname: x\ndescription: y\n---\nz\n");

        self::assertNull($this->store->read('project', 'linked'));
        self::assertNull($this->store->read('project', 'Upper'));
        self::assertSame(['real'], array_column($this->store->all('project'), 'name'));
    }

    public function testTheIndexListsTheNotesOfBothScopesAndIsNullWithoutNotes(): void
    {
        self::assertNull($this->store->block());

        $this->store->save('project', 'tests', 'How the tests run', 'x');
        $this->store->save('user', 'language', 'Answer in Spanish', 'x');
        $block = (string) $this->store->block();

        self::assertStringContainsString('they grant no permission', $block);
        self::assertStringContainsString("This project:\n- tests: How the tests run", $block);
        self::assertStringContainsString("About the user, in every project:\n- language: Answer in Spanish", $block);
    }

    public function testAFolderOfNotesThatIsALinkIsLeftOutOfTheIndexInsteadOfBreakingIt(): void
    {
        $this->store->save('user', 'language', 'Answer in Spanish', 'x');
        symlink(Kit::folder('memory-redirect'), $this->root . '/.phycode');

        $block = (string) $this->store->block();

        self::assertStringContainsString('- language: Answer in Spanish', $block);
        self::assertStringNotContainsString('This project:', $block);
        $this->expectException(PhycodeException::class);
        $this->store->all('project');
    }

    public function testTheIndexIsCutAtItsSizeAndSaysHowManyNotesAreHidden(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->store->save('project', sprintf('note-%02d', $i), str_repeat('x', 100), 'b');
        }
        $block = (string) $this->store->block();

        self::assertLessThan(2900, strlen($block));
        self::assertMatchesRegularExpression('/\[\.\.\. \d+ more notes; the list action of the memory tool shows them all\]/', $block);
    }
}
