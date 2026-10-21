<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Skills;

use Phycode\Gentle\GentleAi;
use Phycode\Skills\SkillCatalog;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Gentle\FakeGentleAi;
use PHPUnit\Framework\TestCase;

final class SkillCatalogTest extends TestCase
{
    private string $project;
    private string $home;

    protected function setUp(): void
    {
        $this->project = Kit::folder('catalog-project');
        $this->home = Kit::folder('catalog-home');
    }

    public function testProjectAndGlobalSkillsAreListedByTheirFoldersWithTheirDescriptions(): void
    {
        $this->skill($this->project, '.agents/skills/go-testing', 'go-testing', 'Go tests.');
        $this->skill($this->project, '.claude/skills/api', 'api', 'REST APIs.');
        $this->skill($this->home, '.agents/skills/review', 'review', 'Reviews code.');

        $notes = [];
        $items = SkillCatalog::discover($this->project, $this->home, null, null, $notes);

        self::assertSame([], $notes);
        self::assertSame([
            ['category' => 'Project', 'title' => 'go-testing', 'detail' => 'Go tests.', 'status' => '', 'path' => "{$this->project}/.agents/skills/go-testing/SKILL.md"],
            ['category' => 'Project', 'title' => 'api', 'detail' => 'REST APIs.', 'status' => '', 'path' => "{$this->project}/.claude/skills/api/SKILL.md"],
            ['category' => 'Global', 'title' => 'review', 'detail' => 'Reviews code.', 'status' => '', 'path' => "{$this->home}/.agents/skills/review/SKILL.md"],
        ], $items);
    }

    public function testFoldersWithoutAValidSkillAreCountedAndSaidInTheNotes(): void
    {
        $this->skill($this->project, '.agents/skills/go-testing', 'go-testing', 'Go tests.');
        Kit::write("{$this->project}/.agents/skills/no-description/SKILL.md", "---\nname: no-description\n---\nBody.\n");
        Kit::write("{$this->project}/.agents/skills/broken-yaml/SKILL.md", "---\nname: broken\ndescription: Use when: the user asks\n---\n");
        Kit::write("{$this->project}/.agents/skills/no-file/notes.txt", "not a skill\n");
        Kit::write("{$this->project}/.agents/skills/stray.txt", "a file, not a folder\n");
        Kit::write("{$this->home}/.claude/skills/no-frontmatter/SKILL.md", "Just text.\n");

        $notes = [];
        $items = SkillCatalog::discover($this->project, $this->home, null, null, $notes);

        self::assertSame(['go-testing'], array_column($items, 'title'));
        self::assertSame(['skipped 4 folders without a valid SKILL.md (name and description are required)'], $notes);
    }

    public function testTheFoldersThatTheGentleAiRegistryLeavesOutAreNeitherListedNorCounted(): void
    {
        $this->skill($this->project, '.agents/skills/go-testing', 'go-testing', 'Go tests.');
        $this->skill($this->project, '.agents/skills/_shared', '_shared', 'Shared files.');
        $this->skill($this->project, '.claude/skills/skill-registry', 'skill-registry', 'The registry.');
        $this->skill($this->project, '.claude/skills/sdd-init', 'sdd-init', 'Starts a change.');
        Kit::write("{$this->project}/.agents/skills/sdd-broken/SKILL.md", "---\nname: sdd-broken\n---\n");
        $this->skill($this->project, '.agents/skills/my-sdd-helper', 'my-sdd-helper', 'Helps.');

        $notes = [];
        $items = SkillCatalog::discover($this->project, null, null, null, $notes);

        self::assertSame(['go-testing', 'my-sdd-helper'], array_column($items, 'title'));
        self::assertSame([], $notes, 'an excluded folder without a valid SKILL.md is not counted as skipped');
    }

    public function testTheSkillsLockGivesTheSourceOfEachProjectSkill(): void
    {
        $this->skill($this->project, '.agents/skills/go-testing', 'go-testing', 'Go tests.');
        $this->skill($this->project, '.claude/skills/api', 'api', 'REST APIs.');
        Kit::write("{$this->project}/skills-lock.json", json_encode([
            'version' => 1,
            'skills' => ['go-testing' => ['source' => 'owner/repo', 'sourceType' => 'github', 'computedHash' => 'abc']],
        ], JSON_THROW_ON_ERROR));

        $notes = [];
        $items = SkillCatalog::discover($this->project, null, null, null, $notes);

        self::assertSame(['owner/repo', 'REST APIs.'], array_column($items, 'detail'));
        self::assertSame([], $notes);
    }

    public function testAnUnreadableSkillsLockIsIgnored(): void
    {
        $this->skill($this->project, '.agents/skills/go-testing', 'go-testing', 'Go tests.');
        Kit::write("{$this->project}/skills-lock.json", "{ not json");

        self::assertSame(['Go tests.'], array_column(SkillCatalog::discover($this->project, null, null), 'detail'));
    }

    public function testASkillThatTwoFoldersShareIsListedOnce(): void
    {
        $this->skill($this->project, '.agents/skills/go-testing', 'go-testing', 'Go tests.');
        mkdir("{$this->project}/.claude/skills", 0700, true);
        symlink("{$this->project}/.agents/skills/go-testing", "{$this->project}/.claude/skills/go-testing");

        self::assertSame(['go-testing'], array_column(SkillCatalog::discover($this->project, null, null), 'title'));
    }

    public function testTheGentleAiRegistryIsTheGentleAiCategory(): void
    {
        $bin = FakeGentleAi::install(['skills' => json_encode([
            ['name' => 'go-testing', 'scope' => 'user', 'description' => 'Go tests.', 'path' => '/p/go-testing/SKILL.md'],
        ], JSON_THROW_ON_ERROR)]);

        $notes = [];
        $items = SkillCatalog::discover($this->project, null, new GentleAi(), $bin, $notes);

        self::assertSame([['category' => 'gentle-ai', 'title' => 'go-testing', 'detail' => 'Go tests.', 'status' => '', 'path' => '/p/go-testing/SKILL.md']], $items);
        self::assertSame([], $notes);
    }

    public function testAGentleAiSkillThatAFolderAlreadyListsIsListedOnce(): void
    {
        $this->skill($this->project, '.agents/skills/go-testing', 'go-testing', 'Go tests.');
        $bin = FakeGentleAi::install(['skills' => json_encode([
            ['name' => 'go-testing', 'scope' => 'project', 'description' => 'Go tests.', 'path' => "{$this->project}/.agents/skills/go-testing/SKILL.md"],
        ], JSON_THROW_ON_ERROR)]);

        $notes = [];
        $items = SkillCatalog::discover($this->project, null, new GentleAi(), $bin, $notes);

        self::assertSame(['Project'], array_column($items, 'category'));
        self::assertSame([], $notes);
    }

    public function testTheGentleAiRegistryLeavesOutTheSkillsThatTheRulesExclude(): void
    {
        $bin = FakeGentleAi::install(['skills' => json_encode([
            ['name' => 'sdd-foo', 'scope' => 'user', 'description' => 'Starts a change.', 'path' => '/p/sdd-foo/SKILL.md'],
            ['name' => '_shared', 'scope' => 'user', 'description' => 'Shared files.', 'path' => '/p/_shared/SKILL.md'],
            ['name' => 'go-testing', 'scope' => 'user', 'description' => 'Go tests.', 'path' => '/p/go-testing/SKILL.md'],
        ], JSON_THROW_ON_ERROR)]);

        $notes = [];
        $items = SkillCatalog::discover($this->project, null, new GentleAi(), $bin, $notes);

        self::assertSame(['go-testing'], array_column($items, 'title'));
        self::assertSame([], $notes);
    }

    public function testAFailingGentleAiRegistryIsANoteAndTheOtherSkillsStillList(): void
    {
        $this->skill($this->project, '.agents/skills/go-testing', 'go-testing', 'Go tests.');
        $bin = FakeGentleAi::install(['skillsExit' => 3]);

        $notes = [];
        $items = SkillCatalog::discover($this->project, null, new GentleAi(), $bin, $notes);

        self::assertSame(['go-testing'], array_column($items, 'title'));
        self::assertSame(['the gentle-ai skills cannot be listed: gentle-ai skill-registry list exited with code 3'], $notes);
    }

    public function testGentleAiNotInstalledIsNotANote(): void
    {
        $notes = [];
        self::assertSame([], SkillCatalog::discover($this->project, null, new GentleAi(), '/nonexistent/gentle-ai', $notes));
        self::assertSame([], $notes);
    }

    public function testTheDocumentHasTheNameDescriptionPathAndTheFirstFortyLinesOfTheBody(): void
    {
        $body = implode("\n", array_map(static fn (int $number): string => "line {$number}", range(1, 50)));
        $this->skill($this->project, '.agents/skills/long', 'long', 'A long skill.', $body . "\n");
        $path = "{$this->project}/.agents/skills/long/SKILL.md";

        $text = SkillCatalog::document($path);

        self::assertSame(
            "name: long\ndescription: A long skill.\npath: {$path}\n\nline 1\n" . implode("\n", array_map(static fn (int $n): string => "line {$n}", range(2, 40))) . "\n[... more lines not shown]",
            $text,
        );
    }

    public function testADocumentOfAShortSkillHasNoMoreLinesNotice(): void
    {
        $this->skill($this->project, '.agents/skills/short', 'short', 'Short.', "Only line.\n");

        self::assertSame(
            "name: short\ndescription: Short.\npath: {$this->project}/.agents/skills/short/SKILL.md\n\nOnly line.",
            SkillCatalog::document("{$this->project}/.agents/skills/short/SKILL.md"),
        );
    }

    public function testTheBomAndWindowsLineEndingsAreAccepted(): void
    {
        Kit::write("{$this->project}/.agents/skills/crlf/SKILL.md", "\xEF\xBB\xBF---\r\nname: crlf\r\ndescription: Windows line endings.\r\n---\r\nBody.\r\n");

        self::assertSame(['Windows line endings.'], array_column(SkillCatalog::discover($this->project, null, null), 'detail'));
    }

    public function testControlCharactersNeverReachTheDescription(): void
    {
        Kit::write("{$this->project}/.agents/skills/esc/SKILL.md", "---\nname: esc\ndescription: \"bad\\x1b[31m text\"\n---\nBody.\n");

        $detail = SkillCatalog::discover($this->project, null, null)[0]['detail'];

        self::assertStringNotContainsString("\x1b", $detail);
        self::assertSame('bad [31m text', $detail);
    }

    public function testAnInvalidOrMissingFileHasNoDocument(): void
    {
        Kit::write("{$this->project}/.agents/skills/bad/SKILL.md", "---\nname: bad\n---\n");

        self::assertNull(SkillCatalog::document("{$this->project}/.agents/skills/bad/SKILL.md"));
        self::assertNull(SkillCatalog::document("{$this->project}/.agents/skills/missing/SKILL.md"));
    }

    public function testTheTextForNoSkillsSaysHowToInstallOne(): void
    {
        self::assertStringContainsString('npx skills add <owner/repo>', SkillCatalog::none());
        self::assertStringContainsString('npx skills add <owner/repo> -g', SkillCatalog::none());
    }

    public function testTheSkillsPhycodeSavedAreListedAfterGlobalAndBeforeGentleAi(): void
    {
        $saved = Kit::folder('catalog-saved') . '/skills';
        $this->skill($this->home, '.agents/skills/review', 'review', 'Reviews code.');
        $this->skill($saved, 'go-testing', 'go-testing', 'Go tests.');
        Kit::write("{$saved}/broken/SKILL.md", "no frontmatter\n");
        $bin = FakeGentleAi::install(['skills' => json_encode([
            ['name' => 'api', 'scope' => 'user', 'description' => 'REST APIs.', 'path' => '/p/api/SKILL.md'],
        ], JSON_THROW_ON_ERROR)]);

        $notes = [];
        $items = SkillCatalog::discover($this->project, $this->home, new GentleAi(), $bin, $notes, $saved);

        self::assertSame(
            [['Global', 'review'], ['phycode', 'go-testing'], ['gentle-ai', 'api']],
            array_map(static fn (array $item): array => [$item['category'], $item['title']], $items),
        );
        self::assertSame(['Go tests.'], array_column(array_slice($items, 1, 1), 'detail'));
        self::assertSame(['skipped 1 folder without a valid SKILL.md (name and description are required)'], $notes);
    }

    public function testWithoutAUserFolderNothingIsAddedFromPhycode(): void
    {
        $saved = Kit::folder('catalog-not-listed') . '/skills';
        $this->skill($saved, 'go-testing', 'go-testing', 'Go tests.');

        self::assertSame([], SkillCatalog::discover($this->project, null, null));
    }

    public function testTheUserFolderOfPhycodeIsTheSkillsFolderOfTheConfig(): void
    {
        self::assertSame('/home/me/.config/phycode/skills', SkillCatalog::userFolder('/home/me/.config/phycode/'));
    }

    private function skill(string $root, string $folder, string $name, string $description, string $body = "Use it well.\n"): void
    {
        Kit::write("{$root}/{$folder}/SKILL.md", "---\nname: {$name}\ndescription: {$description}\n---\n{$body}");
    }
}
