<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Skills;

use Phycode\Skills\SkillException;
use Phycode\Skills\SkillWriter;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class SkillMergeRestoreTest extends TestCase
{
    private string $config;
    private string $skills;
    private SkillWriter $writer;

    protected function setUp(): void
    {
        $this->config = Kit::folder('skill-join') . '/phycode';
        $this->skills = $this->config . '/skills';
        $this->writer = new SkillWriter($this->config);
    }

    private function saved(string $name, string $body = "Use table tests.\n"): void
    {
        $this->writer->apply($this->writer->create($name, "The {$name} skill.", $body));
    }

    private function skillText(string $name, string $body): string
    {
        return "---\nname: {$name}\ndescription: Joined skill.\n---\n{$body}";
    }

    private function refusal(callable $change): string
    {
        try {
            $change();
        } catch (SkillException $error) {
            return $error->getMessage();
        }
        self::fail('the change should have been refused');
    }

    private function archived(string $name): string
    {
        $copies = array_values(array_filter(glob("{$this->config}/trash/*/skills/{$name}") ?: [], 'is_dir'));
        self::assertCount(1, $copies, "one archived copy of {$name}");

        return $copies[0];
    }

    public function testMergeWritesTheJoinedSkillAndArchivesTheOnesItJoins(): void
    {
        $this->saved('api-rest', "REST rules.\n");
        $this->saved('api-errors', "Error rules.\n");

        $change = $this->writer->merge('api', ['api-rest', 'api-errors'], $this->skillText('api', "REST and errors.\n"));
        self::assertDirectoryExists("{$this->skills}/api-rest", 'the plan moves nothing');
        $message = $this->writer->apply($change);

        self::assertSame($this->skillText('api', "REST and errors.\n"), file_get_contents("{$this->skills}/api/SKILL.md"));
        self::assertDirectoryDoesNotExist("{$this->skills}/api-rest");
        self::assertDirectoryDoesNotExist("{$this->skills}/api-errors");
        self::assertStringContainsString('REST rules.', (string) file_get_contents($this->archived('api-rest') . '/SKILL.md'));
        self::assertStringStartsWith("joined api-rest, api-errors into {$this->skills}/api/SKILL.md; archived: ", $message);
        self::assertSame(['action' => 'merge', 'name' => 'api', 'scope' => 'phycode', 'from' => ['api-rest', 'api-errors']], $this->lastLog());
    }

    public function testAMergeThatIsNotValidChangesNothing(): void
    {
        $this->saved('api-rest');
        $this->saved('api-errors');
        $before = [file_get_contents("{$this->skills}/api-rest/SKILL.md"), file_get_contents("{$this->skills}/api-errors/SKILL.md")];
        $valid = $this->skillText('api', "Joined.\n");

        self::assertStringContainsString('merge needs at least two skills', $this->refusal(fn () => $this->writer->merge('api', ['api-rest'], $valid)));
        self::assertSame('api-rest is the skill that merge writes, so it cannot be in from', $this->refusal(fn () => $this->writer->merge('api-rest', ['api-rest', 'api-errors'], $valid)));
        self::assertSame('api-rest is listed twice in from', $this->refusal(fn () => $this->writer->merge('api', ['api-rest', 'api-rest'], $valid)));
        self::assertSame('nope is not a skill saved by phycode, so merge cannot archive it', $this->refusal(fn () => $this->writer->merge('api', ['api-rest', 'nope'], $valid)));
        self::assertSame('the frontmatter name must be api, the name of the skill folder', $this->refusal(fn () => $this->writer->merge('api', ['api-rest', 'api-errors'], $this->skillText('other', "Body.\n"))));
        self::assertStringContainsString('the text has a pipe into a shell', $this->refusal(fn () => $this->writer->merge('api', ['api-rest', 'api-errors'], $this->skillText('api', "Run: cat x | sh\n"))));
        self::assertStringStartsWith('a skill name is 1 to 64 characters', $this->refusal(fn () => $this->writer->merge('Bad Name', ['api-rest', 'api-errors'], $valid)));

        self::assertSame($before, [file_get_contents("{$this->skills}/api-rest/SKILL.md"), file_get_contents("{$this->skills}/api-errors/SKILL.md")]);
        self::assertDirectoryDoesNotExist("{$this->skills}/api", 'the joined skill was not created');
        self::assertCount(2, file("{$this->skills}/.changes.jsonl"), 'only the two creates are in the registry');
    }

    public function testAMergeIntoAnExistingSkillKeepsItsPreviousTextInTheTrash(): void
    {
        $this->saved('api', "Old text.\n");
        $this->saved('api-rest');
        $this->saved('api-errors');

        $this->writer->apply($this->writer->merge('api', ['api-rest', 'api-errors'], $this->skillText('api', "New text.\n")));

        self::assertStringContainsString('New text.', (string) file_get_contents("{$this->skills}/api/SKILL.md"));
        $kept = glob("{$this->config}/trash/*/skills/api/SKILL.md") ?: [];
        self::assertCount(1, $kept);
        self::assertStringContainsString('Old text.', (string) file_get_contents($kept[0]));
    }

    public function testAFailedJoinLeavesTheSkillsAsTheyWere(): void
    {
        $this->saved('api-rest');
        $this->saved('api-errors');
        file_put_contents("{$this->config}/trash", 'not a folder');

        $message = $this->refusal(fn () => $this->writer->apply($this->writer->merge('api', ['api-rest', 'api-errors'], $this->skillText('api', "Joined.\n"))));

        self::assertStringContainsString('nothing was joined', $message);
        self::assertDirectoryExists("{$this->skills}/api-rest");
        self::assertDirectoryExists("{$this->skills}/api-errors");
    }

    public function testRestoreBringsBackTheNewestArchivedCopyWithoutOverwriting(): void
    {
        $this->saved('api', "Version one.\n");
        $this->writer->apply($this->writer->archive('api'));
        $this->saved('api', "Version two.\n");
        $this->writer->apply($this->writer->archive('api'));

        $message = $this->writer->apply($this->writer->restore('api'));

        self::assertStringContainsString('Version two.', (string) file_get_contents("{$this->skills}/api/SKILL.md"));
        self::assertStringStartsWith("restored {$this->skills}/api/SKILL.md from ", $message);
        self::assertCount(1, glob("{$this->config}/trash/*/skills/api") ?: [], 'the older copy stays in the trash');
        self::assertSame(['action' => 'restore', 'name' => 'api', 'scope' => 'phycode'], $this->lastLog());
    }

    public function testRestoreNeverOverwritesASkillOfThatName(): void
    {
        $this->saved('api', "Archived.\n");
        $this->writer->apply($this->writer->archive('api'));
        $this->saved('api', "Current.\n");

        $message = $this->refusal(fn () => $this->writer->restore('api'));

        self::assertSame('a skill named api already exists; restore never overwrites it', $message);
        self::assertStringContainsString('Current.', (string) file_get_contents("{$this->skills}/api/SKILL.md"));
        self::assertCount(1, glob("{$this->config}/trash/*/skills/api") ?: [], 'the archive stays where it is');
    }

    public function testRestoreWithoutAnArchivedCopyIsRefused(): void
    {
        self::assertSame('no archived copy of api in the trash', $this->refusal(fn () => $this->writer->restore('api')));
    }

    public function testAnArchivedSkillIsRestoredOnceAndThenIsGone(): void
    {
        $this->saved('api');
        $this->writer->apply($this->writer->archive('api'));
        $this->writer->apply($this->writer->restore('api'));

        self::assertSame([], glob("{$this->config}/trash/*/skills/api") ?: [], 'the copy left the trash');
        self::assertSame('a skill named api already exists; restore never overwrites it', $this->refusal(fn () => $this->writer->restore('api')));
    }

    private function lastLog(): array
    {
        $lines = file("{$this->skills}/.changes.jsonl", FILE_IGNORE_NEW_LINES) ?: [];
        $entry = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        unset($entry['ts']);

        return $entry;
    }
}
