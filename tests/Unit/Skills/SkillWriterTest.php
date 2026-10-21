<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Skills;

use Phycode\Gentle\GentleAi;
use Phycode\Skills\SkillCatalog;
use Phycode\Skills\SkillLoader;
use Phycode\Skills\SkillRegistry;
use Phycode\Skills\SkillWriter;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SkillWriterTest extends TestCase
{
    private string $config;
    private string $skills;
    private string $project;
    private SkillWriter $writer;

    protected function setUp(): void
    {
        $this->config = Kit::folder('skill-writer') . '/phycode';
        $this->skills = $this->config . '/skills';
        $this->project = Kit::folder('skill-writer-project');
        $this->writer = new SkillWriter($this->config);
    }

    private function saved(string $name = 'go-testing', string $body = "Use table tests.\n"): string
    {
        $this->writer->apply($this->writer->create($name, 'Write Go tests.', $body));

        return "{$this->skills}/{$name}/SKILL.md";
    }

    private function kept(string $relative): string
    {
        $copies = glob("{$this->config}/trash/*/skills/{$relative}") ?: [];
        self::assertCount(1, $copies, "one copy of {$relative} in the trash");

        return $copies[0];
    }

    private function refusal(callable $change): string
    {
        try {
            $change();
        } catch (PhycodeException $error) {
            return $error->getMessage();
        }
        self::fail('the change should have been refused');
    }

    public function testCreatePlansTheFileAndWritesNothingUntilItIsApplied(): void
    {
        $change = $this->writer->create('go-testing', '  Write Go tests.  ', "Use table tests.\n");

        self::assertDirectoryDoesNotExist($this->config, 'a plan writes nothing');
        self::assertNull($change->before);
        self::assertSame("---\nname: go-testing\ndescription: 'Write Go tests.'\n---\n\nUse table tests.\n", $change->after);

        $message = $this->writer->apply($change);

        self::assertSame($change->after, file_get_contents("{$this->skills}/go-testing/SKILL.md"));
        self::assertSame(0600, fileperms("{$this->skills}/go-testing/SKILL.md") & 0777);
        self::assertSame("created {$this->skills}/go-testing/SKILL.md (6 lines)", $message);
    }

    public function testADescriptionWithColonsAndQuotesIsSavedAndReadsBackExactly(): void
    {
        $description = 'Use when: the user asks "why" # and never for "tests"';
        $this->writer->apply($this->writer->create('go-testing', $description, 'Body.'));

        $notes = [];
        $items = SkillCatalog::discover($this->project, null, null, null, $notes, $this->skills);

        self::assertSame([$description], array_column($items, 'detail'));
        self::assertSame([], $notes);
    }

    public function testASkillSavedInOneTurnIsListedAndLoadedByTheNextCallWithoutARestart(): void
    {
        $loader = new SkillLoader(new SkillRegistry(new GentleAi(), '/nonexistent/gentle-ai'), places: [[SkillCatalog::PHYCODE, $this->skills]]);
        $notes = [];
        self::assertSame([], SkillCatalog::discover($this->project, null, null, null, $notes, $this->skills));
        self::assertStringContainsString('no skill named go-testing', $this->refusal(fn () => $loader->load('go-testing')));

        $this->saved();

        $listed = SkillCatalog::discover($this->project, null, null, null, $notes, $this->skills);
        self::assertSame([['phycode', 'go-testing']], array_map(static fn (array $item): array => [$item['category'], $item['title']], $listed));
        self::assertStringContainsString('Use table tests.', $loader->load('go-testing'));
    }

    public function testCreateRefusesASkillThatExistsAndSaysToPatchIt(): void
    {
        $this->saved();

        self::assertSame(
            'a skill named go-testing already exists; use patch to change it',
            $this->refusal(fn () => $this->writer->create('go-testing', 'Other.', 'Body.')),
        );
    }

    public function testCreateRefusesAFolderThatExistsWithoutASkillFile(): void
    {
        mkdir("{$this->skills}/go-testing", 0700, true);

        self::assertSame(
            'a skill named go-testing already exists; use patch to change it',
            $this->refusal(fn () => $this->writer->create('go-testing', 'Write Go tests.', 'Body.')),
        );
    }

    public function testPatchReplacesOneOccurrenceAndKeepsThePreviousTextInTheTrash(): void
    {
        $path = $this->saved('go-testing', "Step one.\nStep two.\n");
        $before = file_get_contents($path);

        $change = $this->writer->patch('go-testing', '', 'Step two.', 'Step 2.', false);

        self::assertSame($before, file_get_contents($path), 'the plan writes nothing');
        self::assertSame(1, $change->occurrences);
        self::assertSame(7, $change->line);

        $message = $this->writer->apply($change);

        self::assertSame("---\nname: go-testing\ndescription: 'Write Go tests.'\n---\n\nStep one.\nStep 2.\n", file_get_contents($path));
        $kept = $this->kept('go-testing/SKILL.md');
        self::assertSame($before, file_get_contents($kept));
        self::assertSame("patched {$path}: replaced 1 occurrence(s), first at line 7; the previous text is kept at {$kept}", $message);
    }

    public function testPatchWithAnOldStringThatIsNotThereShowsTheFileSoTheModelCanCorrectIt(): void
    {
        $this->saved('go-testing', "Use table tests.\nKeep them small.\n");

        $message = $this->refusal(fn () => $this->writer->patch('go-testing', '', 'Use mocks.', 'x', false));

        self::assertStringStartsWith('old_string not found in go-testing/SKILL.md. Copy the exact text, including indentation. The file reads:', $message);
        self::assertStringContainsString("     1  ---\n     2  name: go-testing", $message);
        self::assertStringContainsString('Keep them small.', $message);
    }

    public function testPatchWithAnOldStringThatOccursTwiceIsRefusedUnlessReplaceAllIsSet(): void
    {
        $path = $this->saved('go-testing', "Use tables.\nUse tables.\n");

        self::assertSame(
            'old_string matches 2 places in go-testing/SKILL.md; add surrounding lines to make it unique, or set replace_all to true',
            $this->refusal(fn () => $this->writer->patch('go-testing', '', 'Use tables.', 'Use maps.', false)),
        );

        $change = $this->writer->patch('go-testing', '', 'Use tables.', 'Use maps.', true);
        self::assertSame(2, $change->occurrences);
        $this->writer->apply($change);

        self::assertSame(2, substr_count((string) file_get_contents($path), 'Use maps.'));
    }

    public function testPatchThatBreaksTheFrontmatterIsRefusedAndTheFileStaysAsItWas(): void
    {
        $path = $this->saved();
        $before = file_get_contents($path);

        self::assertSame(
            'the frontmatter name must be go-testing, the name of the skill folder',
            $this->refusal(fn () => $this->writer->patch('go-testing', '', 'name: go-testing', 'name: other', false)),
        );
        self::assertSame($before, file_get_contents($path));
    }

    public static function badPatches(): iterable
    {
        yield 'an unknown skill' => ['missing', '', 'Use table tests.', 'Use maps.', 'no skill named missing; create it first with create'];
        yield 'an unknown file' => ['go-testing', 'references/none.md', 'x', 'y', 'no file references/none.md in skill go-testing; write_file creates one'];
        yield 'an empty old_string' => ['go-testing', '', '', 'x', 'old_string is empty; use write_file to create or replace a whole file'];
        yield 'the same old and new text' => ['go-testing', '', 'same', 'same', 'old_string and new_string are identical; nothing to change'];
        yield 'a file outside the support folders' => ['go-testing', 'docs/a.md', 'x', 'y', 'file_path must be a file under references/, templates/, scripts/ or assets/'];
    }

    #[DataProvider('badPatches')]
    public function testPatchRefusesAMissingSkillOrFileOrAnEmptyChange(string $name, string $file, string $old, string $new, string $start): void
    {
        $this->saved();

        self::assertStringStartsWith($start, $this->refusal(fn () => $this->writer->patch($name, $file, $old, $new, false)));
    }

    public function testWriteFileCreatesASupportFileInItsFolderAndKeepsThePreviousContent(): void
    {
        $this->saved();

        $message = $this->writer->apply($this->writer->writeFile('go-testing', 'references/api/rules.md', "Rule one.\n"));
        self::assertSame("created {$this->skills}/go-testing/references/api/rules.md (1 line)", $message);
        self::assertSame("Rule one.\n", file_get_contents("{$this->skills}/go-testing/references/api/rules.md"));

        $message = $this->writer->apply($this->writer->writeFile('go-testing', 'references/api/rules.md', "Rule two.\n"));

        self::assertSame("Rule two.\n", file_get_contents("{$this->skills}/go-testing/references/api/rules.md"));
        self::assertStringStartsWith("overwrote {$this->skills}/go-testing/references/api/rules.md (1 line); the previous content is kept at ", $message);
        self::assertSame("Rule one.\n", file_get_contents($this->kept('go-testing/references/api/rules.md')));
    }

    public static function pathsOutsideTheSupportFolders(): iterable
    {
        yield 'the skill file' => ['SKILL.md'];
        yield 'another folder' => ['docs/a.md'];
        yield 'a parent folder' => ['../other/a.md'];
        yield 'a way up and back' => ['references/../../other/x.md'];
    }

    #[DataProvider('pathsOutsideTheSupportFolders')]
    public function testWriteFileNeverWritesOutsideTheSupportFolders(string $file): void
    {
        $this->saved();

        $this->refusal(fn () => $this->writer->writeFile('go-testing', $file, 'x'));

        self::assertDirectoryDoesNotExist("{$this->skills}/other");
        self::assertSame(['SKILL.md'], array_values(array_diff(scandir("{$this->skills}/go-testing") ?: [], ['.', '..'])));
    }

    public function testWriteFileNeverWritesToAnAbsolutePath(): void
    {
        $this->saved();
        $outside = Kit::folder('skill-writer-absolute') . '/x.md';

        $this->refusal(fn () => $this->writer->writeFile('go-testing', $outside, 'x'));

        self::assertFileDoesNotExist($outside);
    }

    public function testWriteFileRefusesToWriteThroughASymbolicLinkedFolder(): void
    {
        $this->saved();
        $outside = Kit::folder('skill-writer-outside');
        symlink($outside, "{$this->skills}/go-testing/references");

        self::assertSame(
            'references/x.md goes through a symbolic link; skill_manage changes only real files and folders',
            $this->refusal(fn () => $this->writer->writeFile('go-testing', 'references/x.md', 'x')),
        );
        self::assertFileDoesNotExist("{$outside}/x.md");
    }

    public function testWriteFileRefusesToReplaceAFileThatIsASymbolicLinkAndLeavesItsTargetAlone(): void
    {
        $this->saved();
        $target = Kit::folder('skill-writer-target') . '/notes.md';
        Kit::write($target, "outside\n");
        mkdir("{$this->skills}/go-testing/assets", 0700, true);
        symlink($target, "{$this->skills}/go-testing/assets/notes.md");

        $message = $this->refusal(fn () => $this->writer->writeFile('go-testing', 'assets/notes.md', 'changed'));

        self::assertStringContainsString('goes through a symbolic link', $message);
        self::assertSame("outside\n", file_get_contents($target));
    }

    public function testWriteFileRefusesBinaryContentAndContentLargerThanOneMegabyte(): void
    {
        $this->saved();

        self::assertSame('content has a NUL byte: a skill file is text', $this->refusal(fn () => $this->writer->writeFile('go-testing', 'assets/a.bin', "a\0b")));
        self::assertStringContainsString('larger than 1 MB', $this->refusal(fn () => $this->writer->writeFile('go-testing', 'assets/big.txt', str_repeat('a', 1048577))));
        self::assertFileDoesNotExist("{$this->skills}/go-testing/assets/a.bin");
    }

    public function testRemoveFileMovesTheFileToTheTrashAndNothingIsDeletedForGood(): void
    {
        $this->saved();
        $this->writer->apply($this->writer->writeFile('go-testing', 'scripts/run.sh', "echo hi\n"));
        $change = $this->writer->removeFile('go-testing', 'scripts/run.sh');

        self::assertFileExists("{$this->skills}/go-testing/scripts/run.sh", 'the plan removes nothing');

        $message = $this->writer->apply($change);

        self::assertFileDoesNotExist("{$this->skills}/go-testing/scripts/run.sh");
        self::assertSame("echo hi\n", file_get_contents($this->kept('go-testing/scripts/run.sh')));
        self::assertStringStartsWith("removed {$this->skills}/go-testing/scripts/run.sh; it is kept at ", $message);
    }

    public function testRemoveFileRefusesTheSkillFileAndAMissingFile(): void
    {
        $path = $this->saved();

        self::assertStringStartsWith('file_path must be a file under references/', $this->refusal(fn () => $this->writer->removeFile('go-testing', 'SKILL.md')));
        self::assertSame('no file references/x.md in skill go-testing', $this->refusal(fn () => $this->writer->removeFile('go-testing', 'references/x.md')));
        self::assertFileExists($path);
    }

    public function testArchiveMovesTheWholeSkillToTheTrashAndItIsNoLongerListedOrLoaded(): void
    {
        $this->saved();
        $this->writer->apply($this->writer->writeFile('go-testing', 'references/api.md', "API.\n"));

        $change = $this->writer->archive('go-testing');
        self::assertDirectoryExists("{$this->skills}/go-testing", 'the plan moves nothing');

        $message = $this->writer->apply($change);

        self::assertDirectoryDoesNotExist("{$this->skills}/go-testing");
        self::assertStringContainsString('Write Go tests.', (string) file_get_contents($this->kept('go-testing/SKILL.md')));
        self::assertSame("API.\n", file_get_contents($this->kept('go-testing/references/api.md')));
        self::assertStringStartsWith('archived skill go-testing: its folder is kept at ', $message);
        $notes = [];
        self::assertSame([], SkillCatalog::discover($this->project, null, null, null, $notes, $this->skills));
        $loader = new SkillLoader(new SkillRegistry(new GentleAi(), '/nonexistent/gentle-ai'), places: [[SkillCatalog::PHYCODE, $this->skills]]);
        self::assertStringContainsString('no skill named go-testing', $this->refusal(fn () => $loader->load('go-testing')));
    }

    public function testArchiveOfAnUnknownSkillIsRefused(): void
    {
        self::assertSame('no skill named nope; create it first with create', $this->refusal(fn () => $this->writer->archive('nope')));
    }

    public function testArchiveRefusesASkillFolderThatIsASymbolicLink(): void
    {
        $real = Kit::folder('skill-writer-real') . '/go-testing';
        Kit::write("{$real}/SKILL.md", "---\nname: go-testing\ndescription: Write Go tests.\n---\nBody.\n");
        mkdir($this->skills, 0700, true);
        symlink($real, "{$this->skills}/go-testing");

        self::assertSame(
            'skill go-testing is a symbolic link; skill_manage changes only real folders',
            $this->refusal(fn () => $this->writer->archive('go-testing')),
        );
        self::assertDirectoryExists($real, 'the target of the link is not moved');
    }

    public function testTheTrashCannotBeASymbolicLinkSoNothingIsMovedOutOfTheConfig(): void
    {
        $this->saved();
        $elsewhere = Kit::folder('skill-writer-trash-target');
        symlink($elsewhere, "{$this->config}/trash");
        $change = $this->writer->archive('go-testing');

        self::assertStringContainsString(
            'is a symbolic link; phycode keeps its own files only in real folders',
            $this->refusal(fn () => $this->writer->apply($change)),
        );
        self::assertDirectoryExists("{$this->skills}/go-testing", 'the skill is still there');
        self::assertSame([], array_values(array_diff(scandir($elsewhere) ?: [], ['.', '..'])));
    }

    public function testAPatchOfASkillFolderThatIsALinkIsRefusedAndTheTargetIsNotRead(): void
    {
        $real = Kit::folder('skill-writer-link') . '/go-testing';
        Kit::write("{$real}/SKILL.md", "---\nname: go-testing\ndescription: Write Go tests.\n---\nBody.\n");
        mkdir($this->skills, 0700, true);
        symlink($real, "{$this->skills}/go-testing");

        self::assertSame(
            'skill go-testing is a symbolic link; skill_manage changes only real folders',
            $this->refusal(fn () => $this->writer->patch('go-testing', '', 'Body.', 'Changed.', false)),
        );
        self::assertSame("---\nname: go-testing\ndescription: Write Go tests.\n---\nBody.\n", file_get_contents("{$real}/SKILL.md"));
    }
}
