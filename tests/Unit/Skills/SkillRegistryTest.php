<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Skills;

use Phycode\Gentle\GentleAi;
use Phycode\Skills\Skill;
use Phycode\Skills\SkillRegistry;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Gentle\FakeGentleAi;
use PHPUnit\Framework\TestCase;

final class SkillRegistryTest extends TestCase
{
    private const SKILLS = '[{"name":"go-testing","scope":"user","description":"Go tests.","path":"/p/go-testing/SKILL.md"},{"name":"sdd-init","scope":"project","description":"","path":"/work/skills/sdd-init/SKILL.md","future":true}]';

    private array $environment = [];

    protected function setUp(): void
    {
        $this->environment['GENTLE_AI_HOME'] = getenv('GENTLE_AI_HOME');
        putenv('GENTLE_AI_HOME');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testSkillsAreTheRowsTheRegistryLists(): void
    {
        $bin = FakeGentleAi::install(['skills' => self::SKILLS]);

        $skills = (new SkillRegistry(new GentleAi(), $bin))->skills($reason);

        self::assertNull($reason);
        self::assertEquals([
            new Skill('go-testing', 'Go tests.', '/p/go-testing/SKILL.md'),
            new Skill('sdd-init', '', '/work/skills/sdd-init/SKILL.md'),
        ], $skills);
    }

    public function testTheProjectFolderIsTheOneTheRegistryIsAskedAbout(): void
    {
        $bin = FakeGentleAi::install(['skills' => '[]']);
        $project = Kit::folder('project');

        (new SkillRegistry(new GentleAi(), $bin, $project))->skills();

        self::assertSame(["1|skill-registry list --json --cwd {$project}"], FakeGentleAi::calls($bin));
    }

    public function testWithoutGentleAiThereAreNoSkillsAndTheInstallHintIsTheReason(): void
    {
        $registry = new SkillRegistry(new GentleAi(), '/nonexistent/gentle-ai');

        self::assertSame([], $registry->skills($reason));
        self::assertSame(GentleAi::NOT_INSTALLED, $reason);
    }

    public function testAFailedListingIsEmptyAndSaysWhy(): void
    {
        $bin = FakeGentleAi::install(['skillsExit' => 2, 'stderr' => 'registry locked']);

        self::assertSame([], (new SkillRegistry(new GentleAi(), $bin))->skills($reason));
        self::assertSame('gentle-ai skill-registry list exited with code 2: registry locked', $reason);
    }

    public function testTheStateFileIsTheOneGentleAiReports(): void
    {
        putenv('GENTLE_AI_HOME=/srv/skills-state/');

        self::assertSame('/srv/skills-state/state.json', (new SkillRegistry(new GentleAi()))->stateFile());
    }
}
