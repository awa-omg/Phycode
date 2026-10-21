<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Security\Decision;
use Phycode\Security\Permissions;
use Phycode\Tests\Support\FakeCli;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\GhReadTool;
use Phycode\Tools\GhWriteTool;
use PHPUnit\Framework\TestCase;

final class GhToolsTest extends TestCase
{
    private ?FakeCli $cli = null;
    private string $root;
    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) getenv('PATH');
        $this->root = (string) realpath(Kit::folder('gh-project'));
        Kit::write($this->root . '/.git/HEAD', "ref: refs/heads/main\n");
    }

    protected function tearDown(): void
    {
        $this->cli?->end();
        putenv('PATH=' . $this->path);
        putenv('GH_TOKEN');
        putenv('GITHUB_TOKEN');
        putenv('AWS_SECRET_ACCESS_KEY');
    }

    private function install(string $body = 'echo "https://github.com/acme/app/pull/12"'): void
    {
        $this->cli = FakeCli::install(['gh' => $body], ['GH_PROMPT_DISABLED', 'GH_TOKEN', 'GITHUB_TOKEN', 'AWS_SECRET_ACCESS_KEY', 'NO_COLOR']);
    }

    private function context(string $mode = 'yolo', ?RecordingUi $ui = null): \Phycode\Tools\ToolContext
    {
        return Kit::context($this->root, Kit::agent(), Kit::policy($mode), $ui ?? new RecordingUi());
    }

    public function testAReadRunsGhInTheProjectWithItsPromptsOffAndReturnsItsOutput(): void
    {
        $this->install('echo \'{"title":"Fix","state":"OPEN"}\'');

        $result = (new GhReadTool($this->root))->execute(['args' => ['pr', 'view', '12', '--json', 'title,state']], $this->context());

        self::assertTrue($result->ok, $result->content);
        self::assertStringContainsString('{"title":"Fix","state":"OPEN"}', $result->content);
        self::assertSame([['pr', 'view', '12', '--json', 'title,state']], $this->cli->calls('gh'));
        self::assertSame([$this->root], $this->cli->directories());
        self::assertSame(['1'], $this->cli->values('GH_PROMPT_DISABLED'));
        self::assertSame(['1'], $this->cli->values('NO_COLOR'));
    }

    public function testOnlyTheLoginOfGhIsPassedOnAndNothingElseOfTheEnvironment(): void
    {
        putenv('GH_TOKEN=ghp_test_token_value_123');
        putenv('AWS_SECRET_ACCESS_KEY=do-not-leak');
        $this->install();

        (new GhReadTool($this->root))->execute(['args' => ['auth', 'status']], $this->context());

        self::assertSame(['ghp_test_token_value_123'], $this->cli->values('GH_TOKEN'));
        self::assertSame(['<unset>'], $this->cli->values('GITHUB_TOKEN'));
        self::assertSame(['<unset>'], $this->cli->values('AWS_SECRET_ACCESS_KEY'));
    }

    public function testAWriteRunsAndIsRecordedAsAChange(): void
    {
        $this->install();
        $context = $this->context();

        $result = (new GhWriteTool($this->root))->execute(['args' => ['pr', 'create', '--title', 'Fix the loader', '--body', 'Why and how']], $context);

        self::assertTrue($result->ok, $result->content);
        self::assertStringContainsString('https://github.com/acme/app/pull/12', $result->content);
        self::assertSame([['pr', 'create', '--title', 'Fix the loader', '--body', 'Why and how']], $this->cli->calls('gh'));
        self::assertContains('gh pr create', $context->changes->since(0));
    }

    public function testACallThatThePolicyRefusesNeverRunsGh(): void
    {
        $this->install();

        $result = (new GhReadTool($this->root))->execute(['args' => ['pr', 'view', '1', '--jq', 'env']], $this->context());

        self::assertFalse($result->ok);
        self::assertStringContainsString('gh refused:', $result->content);
        self::assertSame([], $this->cli->calls('gh'));
        $this->expectException(\Phycode\Support\PhycodeException::class);
        (new GhWriteTool($this->root))->validate(['args' => ['pr', 'merge', '1']], $this->context());
    }

    public function testWithoutGhTheCallSaysSoInsteadOfFailingStrangely(): void
    {
        putenv('PATH=' . Kit::folder('gh-empty-path'));

        $result = (new GhReadTool($this->root))->execute(['args' => ['pr', 'status']], $this->context());

        self::assertFalse($result->ok);
        self::assertStringContainsString('the GitHub CLI (gh) is not installed', $result->content);
    }

    public function testAFailureOfGhIsAnErrorWithItsMessage(): void
    {
        $this->install('echo "GraphQL: Could not resolve to a PullRequest with the number of 99." >&2; exit 1');

        $result = (new GhReadTool($this->root))->execute(['args' => ['pr', 'view', '99']], $this->context());

        self::assertFalse($result->ok);
        self::assertStringContainsString('Could not resolve to a PullRequest', $result->content);
    }

    public function testReadsRunWithoutAskingAndPublishingAsksEvenWhenEditsAreAccepted(): void
    {
        $this->install();
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];
        $permissions = new Permissions($ui);
        $read = new GhReadTool($this->root);
        $write = new GhWriteTool($this->root);
        $args = ['args' => ['pr', 'create', '--fill']];

        self::assertNull($permissions->check($read, $this->context('ask', $ui), $read->summarize(['args' => ['pr', 'list']]), ['args' => ['pr', 'list']]));
        self::assertSame([], $ui->requests);
        self::assertNull($permissions->check($write, $this->context('accept_edits', $ui), $write->summarize($args), $args));
        self::assertCount(1, $ui->requests);
        self::assertSame('gh pr create --fill', $ui->requests[0]->summary);
    }

    public function testThePlanModeAllowsReadsAndRefusesPublishing(): void
    {
        $permissions = new Permissions(new RecordingUi());
        $read = new GhReadTool($this->root);
        $write = new GhWriteTool($this->root);

        self::assertNull($permissions->check($read, $this->context('plan'), 'gh pr list'));
        self::assertStringContainsString('plan mode allows only read tools', (string) $permissions->check($write, $this->context('plan'), 'gh pr create --fill'));
    }

    public function testTheToolsAreOfferedInAGitRepositoryOnly(): void
    {
        $policy = new RunPolicy(null, [], [], 'ask', 0);
        $bare = Kit::folder('gh-no-git');

        self::assertTrue((new GhReadTool($this->root))->isAvailable($policy));
        self::assertFalse((new GhReadTool($bare))->isAvailable($policy));
        self::assertFalse((new GhWriteTool($bare))->isAvailable($policy));
    }

    public function testTheSummaryIsTheCommandInOneLine(): void
    {
        $tool = new GhWriteTool($this->root);

        self::assertSame('gh pr create --title Fix --body Why', $tool->summarize(['args' => ['pr', 'create', '--title', 'Fix', '--body', 'Why']]));
        self::assertSame(\Phycode\Tools\ToolCategory::Exec, $tool->category());
        self::assertSame(\Phycode\Tools\ToolCategory::Read, (new GhReadTool($this->root))->category());
    }
}
