<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Skills;

use Phycode\Gentle\GentleAi;
use Phycode\Skills\SkillCatalog;
use Phycode\Skills\SkillLoader;
use Phycode\Skills\SkillRegistry;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Gentle\FakeGentleAi;
use PHPUnit\Framework\TestCase;

final class SkillLoaderPlacesTest extends TestCase
{
    private string $project;
    private string $home;
    private string $saved;

    protected function setUp(): void
    {
        $this->project = Kit::folder('loader-project');
        $this->home = Kit::folder('loader-home');
        $this->saved = Kit::folder('loader-saved') . '/skills';
    }

    private function write(string $root, string $folder, string $name, string $body): void
    {
        Kit::write("{$root}/{$folder}/SKILL.md", "---\nname: {$name}\ndescription: The {$name} skill.\n---\n{$body}\n");
    }

    private function loader(?string $bin = null): SkillLoader
    {
        $registry = new SkillRegistry(new GentleAi(), $bin ?? FakeGentleAi::install(['skills' => '[]']), $this->project);

        return new SkillLoader($registry, places: SkillCatalog::places($this->project, $this->home, $this->saved));
    }

    public function testTheHomeFolderSkillIsLoadedButASkillOfTheProjectIsNot(): void
    {
        $this->write($this->project, '.agents/skills/project-only', 'project-only', 'From the project.');
        $this->write($this->home, '.claude/skills/home-only', 'home-only', 'From the home folder.');

        self::assertStringContainsString('From the home folder.', $this->loader()->load('home-only'));
        $this->expectExceptionMessage('no skill named project-only');
        $this->loader()->load('project-only');
    }

    public function testTheHomeFolderWinsOverTheSavedSkillsAndTheProjectIsLeftOut(): void
    {
        $this->write($this->project, '.agents/skills/same', 'same', 'Project.');
        $this->write($this->home, '.agents/skills/same', 'same', 'Home.');
        $this->write($this->saved, 'same', 'same', 'Saved.');
        $this->write($this->home, '.agents/skills/saved-only-shadowed', 'saved-only-shadowed', 'Home.');
        $this->write($this->saved, 'saved-only-shadowed', 'saved-only-shadowed', 'Saved.');

        self::assertStringContainsString('Home.', $this->loader()->load('same'));
        self::assertStringContainsString('Home.', $this->loader()->load('saved-only-shadowed'));
    }

    public function testARegistrySkillThatLivesInTheProjectIsNotLoaded(): void
    {
        $pdf = $this->project . '/skills/pdf/SKILL.md';
        Kit::write($pdf, "---\nname: pdf\ndescription: PDFs.\n---\nFrom the repository.\n");
        $bin = FakeGentleAi::install(['skills' => json_encode([['name' => 'pdf', 'scope' => 'project', 'description' => 'PDFs.', 'path' => $pdf]], JSON_THROW_ON_ERROR)]);
        $registry = new SkillRegistry(new GentleAi(), $bin, $this->project);
        $loader = new SkillLoader($registry, places: [], root: $this->project, home: $this->home);

        $this->expectExceptionMessage('no skill named pdf');
        $loader->load('pdf');
    }

    public function testTheRegistryIsAskedOnlyWhenNoFolderHasTheName(): void
    {
        $this->write($this->saved, 'api-review', 'api-review', 'Saved.');
        $pdf = Kit::folder('loader-gentle') . '/pdf/SKILL.md';
        Kit::write($pdf, "---\nname: pdf\ndescription: PDFs.\n---\nFrom gentle-ai.\n");
        $bin = FakeGentleAi::install(['skills' => json_encode([['name' => 'pdf', 'scope' => 'user', 'description' => 'PDFs.', 'path' => $pdf]], JSON_THROW_ON_ERROR)]);

        self::assertStringContainsString('Saved.', $this->loader($bin)->load('api-review'));
        self::assertSame([], FakeGentleAi::calls($bin), 'a skill in a folder is read without asking gentle-ai');

        self::assertStringContainsString('From gentle-ai.', $this->loader($bin)->load('pdf'));
        self::assertCount(1, FakeGentleAi::calls($bin), 'a name no folder has is looked up in the registry');
    }

    public function testAFolderSkillWithoutFrontmatterIsNotLoadedAsOne(): void
    {
        Kit::write("{$this->project}/.agents/skills/broken/SKILL.md", "Just text.\n");

        $this->expectExceptionMessage('no skill named broken');

        $this->loader()->load('broken');
    }
}
