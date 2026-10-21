<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Security\Decision;
use Phycode\Skills\SkillWriter;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\SkillManageTool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SkillManageToolTest extends TestCase
{
    private string $config;
    private string $skills;
    private RecordingUi $ui;
    private ToolContext $context;
    private SkillManageTool $tool;

    protected function setUp(): void
    {
        $this->config = Kit::folder('skill-tool-config') . '/phycode';
        $this->skills = $this->config . '/skills';
        $this->ui = new RecordingUi();
        $this->context = Kit::context(Kit::folder('skill-tool-project'), Kit::agent(), Kit::policy(), $this->ui);
        $this->tool = new SkillManageTool(new SkillWriter($this->config), true, 'ask');
    }

    private const CREATE = ['action' => 'create', 'name' => 'go-testing', 'description' => 'Write Go tests.', 'body' => "Use table tests.\n"];

    private function apply(array $args): void
    {
        $result = $this->tool->execute($args, $this->context);
        self::assertTrue($result->ok, $result->content);
    }

    public function testItIsAWriteToolThatIsOfferedOnlyWhenSkillsAreOn(): void
    {
        $policy = Kit::policy();

        self::assertSame('skill_manage', $this->tool->name());
        self::assertSame(ToolCategory::Write, $this->tool->category());
        self::assertTrue($this->tool->isAvailable($policy));
        self::assertFalse((new SkillManageTool(new SkillWriter($this->config), false))->isAvailable($policy));
    }

    public function testTheSummaryNamesTheActionTheSkillAndTheFile(): void
    {
        self::assertSame('skill_manage create go-testing', $this->tool->summarize(self::CREATE));
        self::assertSame('skill_manage write_file go-testing references/api.md', $this->tool->summarize(['action' => 'write_file', 'name' => 'go-testing', 'file_path' => 'references/api.md']));
    }

    public function testCreateShowsTheWholeSkillFileBeforeTheApprovalAndWritesNothing(): void
    {
        $preview = $this->tool->preview(self::CREATE, $this->context);

        self::assertSame(['path' => "{$this->skills}/go-testing/SKILL.md", 'added' => 6, 'removed' => 0], self::counts($preview));
        self::assertDirectoryDoesNotExist($this->config, 'a preview writes nothing');
    }

    public function testAPatchShowsTheChangeAndTheResultCarriesTheSameDiff(): void
    {
        $this->apply(self::CREATE);
        $args = ['action' => 'patch', 'name' => 'go-testing', 'old_string' => 'Use table tests.', 'new_string' => 'Use table-driven tests.'];

        $preview = $this->tool->preview($args, $this->context);
        self::assertSame(['path' => "{$this->skills}/go-testing/SKILL.md", 'added' => 1, 'removed' => 1], self::counts($preview));
        self::assertStringNotContainsString('table-driven', (string) file_get_contents("{$this->skills}/go-testing/SKILL.md"));

        $result = $this->tool->execute($args, $this->context);

        self::assertTrue($result->ok);
        self::assertSame($preview, $result->diff);
        self::assertStringContainsString('Use table-driven tests.', (string) file_get_contents("{$this->skills}/go-testing/SKILL.md"));
    }

    public function testARemovalShowsTheTextItRemovesAndNoPreviewWhenTheTextCannotBeShown(): void
    {
        $this->apply(self::CREATE);
        $this->apply(['action' => 'write_file', 'name' => 'go-testing', 'file_path' => 'scripts/run.sh', 'content' => "echo a\necho b\n"]);
        Kit::write("{$this->skills}/go-testing/assets/data.bin", "bin\0ary");

        $text = ['action' => 'remove_file', 'name' => 'go-testing', 'file_path' => 'scripts/run.sh'];
        self::assertSame(['path' => "{$this->skills}/go-testing/scripts/run.sh", 'added' => 0, 'removed' => 2], self::counts($this->tool->preview($text, $this->context)));
        self::assertNull($this->tool->preview(['action' => 'remove_file', 'name' => 'go-testing', 'file_path' => 'assets/data.bin'], $this->context));
        self::assertFileExists("{$this->skills}/go-testing/scripts/run.sh", 'a preview removes nothing');
    }

    public static function badCalls(): iterable
    {
        yield 'an unknown action' => [['action' => 'delete', 'name' => 'go-testing'], 'action must be one of create, patch, write_file, remove_file, archive'];
        yield 'no action' => [['name' => 'go-testing'], "missing required argument 'action'"];
        yield 'a name with capital letters' => [['action' => 'create', 'name' => 'Go', 'description' => 'x', 'body' => 'y'], 'a skill name is 1 to 64 characters'];
        yield 'create without a description' => [['action' => 'create', 'name' => 'go-testing', 'body' => 'y'], "missing required argument 'description'"];
        yield 'patch without old_string' => [['action' => 'patch', 'name' => 'go-testing', 'new_string' => 'y'], "missing required argument 'old_string'"];
        yield 'replace_all as text' => [['action' => 'patch', 'name' => 'go-testing', 'old_string' => 'a', 'new_string' => 'b', 'replace_all' => 'yes'], "argument 'replace_all' must be true or false"];
        yield 'a name that is not text' => [['action' => 'archive', 'name' => 5], "argument 'name' must be a string"];
    }

    #[DataProvider('badCalls')]
    public function testAnInvalidCallIsRefusedByValidateBeforeAnyApproval(array $args, string $message): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage($message);

        try {
            $this->tool->validate($args, $this->context);
        } finally {
            self::assertDirectoryDoesNotExist($this->config);
        }
    }

    public function testTheUserIsAskedWithThePreviewAndADenialWritesNothing(): void
    {
        $this->ui->decisions = [Decision::Deny];

        $reason = $this->context->permissions->check($this->tool, $this->context, $this->tool->summarize(self::CREATE), self::CREATE);

        self::assertSame('the user denied this action', $reason);
        self::assertCount(1, $this->ui->requests);
        self::assertSame('skill_manage', $this->ui->requests[0]->tool);
        self::assertSame(ToolCategory::Write, $this->ui->requests[0]->category);
        self::assertNotNull($this->ui->requests[0]->preview);
        self::assertDirectoryDoesNotExist($this->config);
    }

    public function testAnApprovedChangeRunsAndIsListedAsAChangeOfTheTurn(): void
    {
        $this->ui->decisions = [Decision::AllowOnce];
        $summary = $this->tool->summarize(self::CREATE);

        self::assertNull($this->context->permissions->check($this->tool, $this->context, $summary, self::CREATE));
        $result = $this->tool->execute(self::CREATE, $this->context);

        self::assertTrue($result->ok);
        self::assertStringStartsWith("created {$this->skills}/go-testing/SKILL.md (6 lines)", $result->content);
        self::assertSame(["{$this->skills}/go-testing/SKILL.md"], $this->context->changes->since(0));
    }

    public function testAReadOnlyAgentIsRefusedLikeAnyWriteTool(): void
    {
        $context = Kit::context(Kit::folder('skill-tool-read-only'), Kit::agent(), Kit::policy('ask', null, ['write']), $this->ui);

        self::assertSame(
            "agent 'build' is read-only and may not run skill_manage",
            $context->permissions->check($this->tool, $context, 'skill_manage create go-testing', self::CREATE),
        );
        self::assertSame([], $this->ui->requests, 'nobody is asked');
    }

    public function testUnderAutonomyAutoTheChangeRunsWithoutAskingButAStillRefusingPolicyStillRefuses(): void
    {
        $auto = new SkillManageTool(new SkillWriter($this->config), true, 'auto');
        $context = Kit::context(Kit::folder('skill-tool-auto'), Kit::agent(), Kit::policy(), $this->ui);

        self::assertTrue($auto->autoApproved());
        self::assertFalse($this->tool->autoApproved());
        self::assertNull($context->permissions->check($auto, $context, $auto->summarize(self::CREATE), self::CREATE));
        self::assertSame([], $this->ui->requests, 'nobody is asked');
        self::assertTrue($auto->execute(self::CREATE, $context)->ok);
        self::assertDirectoryExists("{$this->skills}/go-testing");

        $readonly = Kit::context(Kit::folder('skill-tool-auto-ro'), Kit::agent(), Kit::policy('ask', null, ['write']), $this->ui);
        self::assertSame(
            "agent 'build' is read-only and may not run skill_manage",
            $readonly->permissions->check($auto, $readonly, 'skill_manage create go-testing', self::CREATE),
        );
    }

    public function testAcceptEditsRunsSkillChangesWithoutAskingLikeWrite(): void
    {
        $context = Kit::context(Kit::folder('skill-tool-accept'), Kit::agent(), Kit::policy('accept_edits'), $this->ui);

        self::assertNull($context->permissions->check($this->tool, $context, 'skill_manage create go-testing', self::CREATE));
        self::assertSame([], $this->ui->requests);
    }

    private static function counts(?array $diff): array
    {
        self::assertNotNull($diff);

        return ['path' => $diff['path'], 'added' => $diff['added'], 'removed' => $diff['removed']];
    }
}
