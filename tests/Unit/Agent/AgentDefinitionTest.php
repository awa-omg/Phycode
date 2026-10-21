<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Agent;

use Phycode\Agent\AgentFactory;
use Phycode\Agent\AgentRegistry;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentDefinitionTest extends TestCase
{
    public function testClaudeStyleFieldsAreMapped(): void
    {
        $file = Kit::folder('agents') . '/code-reviewer.md';
        Kit::write($file, "---\nname: code-reviewer\ndescription: Reviews code\ntools: Read, Grep, Glob, Bash\ndisallowedTools: Bash\nmodel: sonnet\npermissionMode: plan\nmaxTurns: 5\n---\nBody text.\n");

        $notices = [];
        $agent = AgentFactory::fromFile($file, 'project', $notices);

        self::assertNotNull($agent);
        self::assertSame(['read', 'grep', 'list', 'bash'], $agent->tools);
        self::assertSame(['bash'], $agent->excludedTools);
        self::assertSame(['write', 'exec'], $agent->deniedCategories);
        self::assertNull($agent->profile, 'a project agent cannot choose the profile, so model: sonnet is dropped');
        self::assertSame(["{$file}: profile 'sonnet' is ignored: a project agent cannot choose the profile that receives its conversation"], $notices);
        self::assertSame(5, $agent->maxTurns);
        self::assertSame('subagent', $agent->mode);
        self::assertSame('Body text.', $agent->prompt);
        self::assertSame([], $agent->warnings);
    }

    public static function projectSources(): iterable
    {
        yield 'project' => ['project'];
        yield 'project-claude' => ['project-claude'];
    }

    #[DataProvider('projectSources')]
    public function testAProjectAgentCannotChooseAProfile(string $source): void
    {
        $file = Kit::folder('agents') . '/remote.md';
        Kit::write($file, "---\nname: remote\ndescription: Asks for a cloud profile\nprofile: cloud\n---\nBody.\n");
        $notices = [];

        $agent = AgentFactory::fromFile($file, $source, $notices);

        self::assertNotNull($agent);
        self::assertNull($agent->profile);
        self::assertCount(1, $notices, 'the ignored setting is reported once');
        self::assertStringContainsString("profile 'cloud' is ignored", $notices[0]);
    }

    public static function sourcesThatMayChooseAProfile(): iterable
    {
        yield 'user' => ['user'];
        yield 'user-claude' => ['user-claude'];
        yield 'builtin' => ['builtin'];
    }

    #[DataProvider('sourcesThatMayChooseAProfile')]
    public function testAUserOrBuiltinAgentKeepsItsProfile(string $source): void
    {
        $file = Kit::folder('agents') . '/cloud.md';
        Kit::write($file, "---\nname: cloud\ndescription: Uses the cloud profile\nprofile: cloud\n---\nBody.\n");
        $notices = [];

        $agent = AgentFactory::fromFile($file, $source, $notices);

        self::assertNotNull($agent);
        self::assertSame('cloud', $agent->profile);
        self::assertSame([], $notices);
    }

    public function testOpenCodeMapsAndUnknownFieldsBecomeWarnings(): void
    {
        $file = Kit::folder('agents') . '/docs-writer.md';
        Kit::write($file, "---\nname: docs-writer\ndescription: Writes docs\nmode: subagent\npermission:\n  edit: deny\n  bash: deny\nsteps: 4\ntemperature: 0.2\nhidden: true\ntools:\n  read: true\n  write: false\n  bash: true\n---\nWrite clearly.\n");

        $agent = AgentFactory::fromFile($file, 'user');

        self::assertNotNull($agent);
        self::assertSame(['read', 'bash'], $agent->tools);
        self::assertSame(['write', 'exec'], $agent->deniedCategories);
        self::assertSame(4, $agent->maxTurns);
        self::assertSame(0.2, $agent->temperature);
        self::assertContains("field 'hidden' is not supported and was ignored", $agent->warnings);
    }

    public function testUnknownToolsInDisallowedToolsOnlyWarn(): void
    {
        $file = Kit::folder('agents') . '/quiet.md';
        Kit::write($file, "---\nname: quiet\ndescription: Keeps out web access\ndisallowedTools: WebFetch\n---\nBody.\n");

        $agent = AgentFactory::fromFile($file, 'project');

        self::assertNotNull($agent);
        self::assertSame([], $agent->excludedTools);
        self::assertContains("tool 'WebFetch' does not exist in phycode and was ignored", $agent->warnings);
    }

    public function testDisabledAgentsAreSkipped(): void
    {
        $file = Kit::folder('agents') . '/off.md';
        Kit::write($file, "---\nname: off\ndescription: Disabled\ndisable: true\n---\nNo.\n");

        self::assertNull(AgentFactory::fromFile($file, 'user'));
    }

    public static function invalidDocuments(): iterable
    {
        yield 'bad name' => ["---\nname: Bad_Name\ndescription: x\n---\nx\n"];
        yield 'missing description' => ["---\nname: fine\n---\nx\n"];
        yield 'no tool exists' => ["---\nname: t\ndescription: x\ntools: [Teleport]\n---\nx\n"];
        yield 'unterminated frontmatter' => ["---\nname: t\ndescription: x\n"];
        yield 'bad temperature' => ["---\nname: t\ndescription: x\ntemperature: 9\n---\nx\n"];
        yield 'bad mode' => ["---\nname: t\ndescription: x\nmode: boss\n---\nx\n"];
    }

    #[DataProvider('invalidDocuments')]
    public function testInvalidDocumentsAreRejectedWithAMessage(string $document): void
    {
        $file = Kit::folder('agents') . '/invalid.md';
        Kit::write($file, $document);

        $this->expectException(PhycodeException::class);
        AgentFactory::fromFile($file, 'project');
    }

    public function testNearestFolderWinsWhenWalkingUpToTheRoot(): void
    {
        $root = Kit::folder('nested');
        Kit::write($root . '/.claude/agents/shared.md', "---\nname: shared\ndescription: root version\n---\nR.\n");
        Kit::write($root . '/sub/.phycode/agents/shared.md', "---\nname: shared\ndescription: nested version\n---\nN.\n");

        $registry = AgentRegistry::load($root . '/sub', $root, '/nonexistent-config', '/nonexistent-home', '/nonexistent-builtin');

        self::assertSame('nested version', $registry->get('shared')?->description);
    }

    public function testSubagentsAndPrimariesAreSeparated(): void
    {
        $registry = AgentRegistry::load(Kit::folder('empty'), Kit::folder('empty2'), '/nonexistent-config', '/nonexistent-home', Kit::builtinAgents());

        self::assertArrayHasKey('build', $registry->primaries());
        self::assertArrayNotHasKey('build', $registry->subagents());
        self::assertArrayHasKey('explore', $registry->subagents());
        self::assertArrayHasKey('plan', $registry->primaries());
    }
}
