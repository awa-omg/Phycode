<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Gentle\GentleAi;
use Phycode\Skills\SkillLoader;
use Phycode\Skills\SkillRegistry;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\SkillTool;
use Phycode\Tools\ToolCategory;
use PHPUnit\Framework\TestCase;

final class SkillUnloadToolTest extends TestCase
{
    private string $folder;
    private SkillTool $tool;

    protected function setUp(): void
    {
        $this->folder = Kit::folder('skill-unload');
        Kit::write("{$this->folder}/go-testing/SKILL.md", "---\nname: go-testing\ndescription: Write Go tests.\n---\nUse table tests.\n");
        $loader = new SkillLoader(new SkillRegistry(new GentleAi(), '/nonexistent/gentle-ai'), places: [['Global', $this->folder]]);
        $this->tool = new SkillTool($loader, true);
    }

    private function context(): \Phycode\Tools\ToolContext
    {
        return Kit::context($this->folder, Kit::agent(), Kit::policy(), new RecordingUi());
    }

    public function testAnUnloadReturnsTheLineAndNamesTheSkillInItsResult(): void
    {
        $result = $this->tool->execute(['name' => 'go-testing', 'unload' => true], $this->context());

        self::assertTrue($result->ok);
        self::assertSame('[skill go-testing unloaded to save context; load it again with skill(name) if needed]', $result->content);
        self::assertSame(['unload' => 'go-testing'], $result->meta);
    }

    public function testAnUnloadWithNoEarlierLoadIsNotAnError(): void
    {
        $result = $this->tool->execute(['name' => 'never-loaded', 'unload' => true], $this->context());

        self::assertTrue($result->ok);
        self::assertStringStartsWith('[skill never-loaded unloaded', $result->content);
    }

    public function testALoadStillReturnsTheFileAsItIsAndHasNoUnloadMark(): void
    {
        $result = $this->tool->execute(['name' => 'go-testing'], $this->context());

        self::assertSame("---\nname: go-testing\ndescription: Write Go tests.\n---\nUse table tests.\n", $result->content);
        self::assertSame([], $result->meta);
    }

    public function testAnUnloadWithANameThatIsNotAnIdentifierIsRefused(): void
    {
        $result = $this->tool->execute(['name' => '../go-testing', 'unload' => true], $this->context());

        self::assertFalse($result->ok);
        self::assertStringContainsString('cannot be empty or contain a slash', $result->content);
    }

    public function testTheSchemaOffersUnloadAndTheSummaryShowsIt(): void
    {
        $parameters = $this->tool->parameters();

        self::assertSame(['type' => 'boolean', 'description' => 'true: remove the earlier load of this skill from the conversation, to save context'], $parameters['properties']['unload']);
        self::assertSame(['name'], $parameters['required']);
        self::assertSame('skill: go-testing (unload)', $this->tool->summarize(['name' => 'go-testing', 'unload' => true]));
        self::assertSame(ToolCategory::Read, $this->tool->category());
    }
}
