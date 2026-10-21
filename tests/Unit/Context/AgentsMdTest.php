<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Context;

use Phycode\Context\AgentsMd;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class AgentsMdTest extends TestCase
{
    private const KEY = 'sk-test-fakekey-7f3a9c2e51b8d4';

    public function testAProjectFileIsLoaded(): void
    {
        $project = Kit::folder('agents-md');
        Kit::write($project . '/AGENTS.md', "Use tabs.\n");
        $notices = [];

        self::assertSame('Use tabs.', AgentsMd::load($project, $project, Kit::folder('agents-md-config'), 10000, $notices));
        self::assertSame([], $notices);
    }

    public function testALinkToAFileOutsideTheProjectIsSkippedAndNamedAndItsTextIsNotLoaded(): void
    {
        $project = Kit::folder('agents-md-link');
        $outside = Kit::folder('agents-md-outside') . '/key.md';
        Kit::write($outside, 'api_key = ' . self::KEY . "\n");
        symlink($outside, $project . '/AGENTS.md');
        $notices = [];

        $text = AgentsMd::load($project, $project, Kit::folder('agents-md-config'), 10000, $notices);

        self::assertNull($text, 'the key must not reach the system prompt');
        self::assertCount(1, $notices);
        self::assertStringContainsString($project . '/AGENTS.md: skipped', $notices[0]);
    }

    public function testALinkToAFileInsideTheProjectIsLoaded(): void
    {
        $project = Kit::folder('agents-md-inside');
        Kit::write($project . '/docs/rules.md', "Write tests.\n");
        symlink($project . '/docs/rules.md', $project . '/AGENTS.md');
        $notices = [];

        self::assertSame('Write tests.', AgentsMd::load($project, $project, Kit::folder('agents-md-config'), 10000, $notices));
        self::assertSame([], $notices);
    }

    public function testTheGlobalFileIsReadAndAProjectLinkOutsideIsStillSkipped(): void
    {
        $config = Kit::folder('agents-md-global');
        Kit::write($config . '/AGENTS.md', "Be brief.\n");
        $project = Kit::folder('agents-md-global-project');
        $outside = Kit::folder('agents-md-global-outside') . '/key.md';
        Kit::write($outside, self::KEY . "\n");
        symlink($outside, $project . '/AGENTS.md');
        $notices = [];

        self::assertSame('Be brief.', AgentsMd::load($project, $project, $config, 10000, $notices));
        self::assertCount(1, $notices);
    }

    public function testTheGlobalFileComesFirstThenTheFoldersFromTheRootDownToTheWorkingFolder(): void
    {
        $config = Kit::folder('agents-md-levels-config');
        Kit::write($config . '/AGENTS.md', "Global.\n");
        $root = Kit::folder('agents-md-levels');
        Kit::write($root . '/AGENTS.md', "Root.\n");
        Kit::write($root . '/pkg/AGENTS.md', "Package.\n");
        Kit::write($root . '/pkg/src/AGENTS.md', "Source.\n");
        $notices = [];

        self::assertSame("Global.\n\nRoot.\n\nPackage.\n\nSource.", AgentsMd::load($root . '/pkg/src', $root, $config, 10000, $notices));
        self::assertSame([], $notices);
    }

    public function testClaudeMdIsReadInAFolderWithoutAgentsMdAndAgentsMdWinsOverIt(): void
    {
        $config = Kit::folder('agents-md-claude-config');
        Kit::write($config . '/CLAUDE.md', "Global claude.\n");
        $root = Kit::folder('agents-md-claude');
        Kit::write($root . '/CLAUDE.md', "Root claude.\n");
        Kit::write($root . '/pkg/CLAUDE.md', "Package claude.\n");
        Kit::write($root . '/pkg/AGENTS.md', "Package agents.\n");
        mkdir($root . '/pkg/src', 0700);
        $notices = [];

        self::assertSame(
            "Global claude.\n\nRoot claude.\n\nPackage agents.",
            AgentsMd::load($root . '/pkg/src', $root, $config, 10000, $notices),
        );
        self::assertSame([], $notices);
    }

    public function testOverTheByteLimitTheFarthestLevelGoesFirstAndTheClosestIsKept(): void
    {
        $config = Kit::folder('agents-md-limit-config');
        Kit::write($config . '/AGENTS.md', "Global.\n");
        $root = Kit::folder('agents-md-limit');
        Kit::write($root . '/AGENTS.md', "Root.\n");
        Kit::write($root . '/pkg/AGENTS.md', "Package.\n");
        $notices = [];

        self::assertSame("Root.\n\nPackage.", AgentsMd::load($root . '/pkg', $root, $config, 15, $notices));
        self::assertCount(1, $notices, 'the global file was left out, and the notice says so');
    }

    public function testALinkOutsideInAParentFolderIsSkippedAndTheOtherLevelsStillLoad(): void
    {
        $root = Kit::folder('agents-md-parent-link');
        Kit::write($root . '/AGENTS.md', "Use tabs.\n");
        mkdir($root . '/pkg', 0700);
        $outside = Kit::folder('agents-md-parent-outside') . '/key.md';
        Kit::write($outside, 'api_key = ' . self::KEY . "\n");
        symlink($outside, $root . '/pkg/AGENTS.md');
        $notices = [];

        $text = AgentsMd::load($root . '/pkg', $root, Kit::folder('agents-md-parent-config'), 10000, $notices);

        self::assertSame('Use tabs.', $text);
        self::assertCount(1, $notices);
        self::assertStringContainsString($root . '/pkg/AGENTS.md: skipped', $notices[0]);
    }
}
