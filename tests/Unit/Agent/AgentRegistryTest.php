<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Agent;

use Phycode\Agent\AgentRegistry;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentRegistryTest extends TestCase
{
    public static function builtinNames(): iterable
    {
        foreach (['build', 'plan', 'explore', 'general'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('builtinNames')]
    public function testProjectAgentCannotReplaceABuiltinAndTheNoticeSaysSo(string $name): void
    {
        $project = Kit::folder('project');
        $file = $project . "/.phycode/agents/{$name}.md";
        Kit::write($file, "---\nname: {$name}\ndescription: project version\n---\nP.\n");

        $registry = AgentRegistry::load($project, $project, '/nonexistent-config', '/nonexistent-home', Kit::builtinAgents());

        self::assertSame('builtin', $registry->get($name)?->source);
        self::assertContains("{$file}: project agent {$name} is ignored: built-in agents cannot be replaced by project files", $registry->warnings());
    }

    public function testProjectClaudeFolderCannotReplaceABuiltinEither(): void
    {
        $project = Kit::folder('project');
        $file = $project . '/.claude/agents/explore.md';
        Kit::write($file, "---\nname: explore\ndescription: claude version\n---\nC.\n");

        $registry = AgentRegistry::load($project, $project, '/nonexistent-config', '/nonexistent-home', Kit::builtinAgents());

        self::assertSame('builtin', $registry->get('explore')?->source);
        self::assertContains("{$file}: project agent explore is ignored: built-in agents cannot be replaced by project files", $registry->warnings());
    }

    public function testProjectAgentsWithOtherNamesStillLoad(): void
    {
        $project = Kit::folder('project');
        Kit::write($project . '/.phycode/agents/reviewer.md', "---\nname: reviewer\ndescription: reviews\n---\nR.\n");

        $registry = AgentRegistry::load($project, $project, '/nonexistent-config', '/nonexistent-home', Kit::builtinAgents());

        self::assertSame('project', $registry->get('reviewer')?->source);
    }

    public function testAProjectAgentFileLinkedOutsideTheProjectIsSkippedAndNamed(): void
    {
        $project = Kit::folder('project');
        $outside = Kit::folder('outside') . '/evil.md';
        Kit::write($outside, "---\nname: evil\ndescription: from outside the project\n---\nE.\n");
        mkdir($project . '/.phycode/agents', 0700, true);
        symlink($outside, $project . '/.phycode/agents/evil.md');

        $registry = AgentRegistry::load($project, $project, '/nonexistent-config', '/nonexistent-home', Kit::builtinAgents());

        self::assertNull($registry->get('evil'));
        $notice = "{$project}/.phycode/agents/evil.md: skipped: it is a symbolic link that does not lead to a file inside the project";
        self::assertContains($notice, $registry->notices(), 'the session shows the notice');
        self::assertContains($notice, $registry->warnings());
    }

    public function testAProjectAgentsFolderLinkedOutsideTheProjectSkipsEveryFileInIt(): void
    {
        $project = Kit::folder('project');
        $outside = Kit::folder('outside-agents');
        Kit::write($outside . '/evil.md', "---\nname: evil\ndescription: from outside the project\n---\nE.\n");
        mkdir($project . '/.phycode', 0700, true);
        symlink($outside, $project . '/.phycode/agents');

        $registry = AgentRegistry::load($project, $project, '/nonexistent-config', '/nonexistent-home', Kit::builtinAgents());

        self::assertNull($registry->get('evil'));
        self::assertContains("{$project}/.phycode/agents/evil.md: skipped: it is a symbolic link that does not lead to a file inside the project", $registry->notices());
    }

    public function testAProjectAgentCannotChooseAProfileButAUserAgentCan(): void
    {
        $project = Kit::folder('project');
        $config = Kit::folder('config');
        Kit::write($project . '/.phycode/agents/remote.md', "---\nname: remote\ndescription: asks for a cloud profile\nprofile: cloud\n---\nR.\n");
        Kit::write($config . '/agents/mine.md', "---\nname: mine\ndescription: a user agent\nprofile: cloud\n---\nM.\n");

        $registry = AgentRegistry::load($project, $project, $config, '/nonexistent-home', Kit::builtinAgents());

        self::assertNull($registry->get('remote')?->profile);
        self::assertSame('cloud', $registry->get('mine')?->profile);
        self::assertCount(1, $registry->notices(), 'only the project agent is reported');
        self::assertStringContainsString("{$project}/.phycode/agents/remote.md: profile 'cloud' is ignored", $registry->notices()[0]);
    }

    public function testUserAgentsStillReplaceABuiltin(): void
    {
        $project = Kit::folder('project');
        $config = Kit::folder('config');
        Kit::write($config . '/agents/explore.md', "---\nname: explore\ndescription: user version\n---\nU.\n");

        $registry = AgentRegistry::load($project, $project, $config, '/nonexistent-home', Kit::builtinAgents());

        self::assertSame('user', $registry->get('explore')?->source, 'the rule covers project files only');
    }
}
