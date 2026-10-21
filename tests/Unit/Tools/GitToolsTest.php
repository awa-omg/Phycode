<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Exec\ProcessRunner;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\GitReadTool;
use Phycode\Tools\GitRemoteTool;
use Phycode\Tools\GitWriteTool;
use Phycode\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

final class GitToolsTest extends TestCase
{
    private string $root;
    private string $previousHome;
    private RecordingUi $ui;
    private ToolContext $context;

    protected function setUp(): void
    {
        $this->previousHome = (string) getenv('HOME');
        $home = Kit::folder('git-home');
        Kit::write($home . '/.gitconfig', "[user]\n\tname = Phycode Test\n\temail = test@example.invalid\n[init]\n\tdefaultBranch = main\n");
        putenv('HOME=' . $home);

        $this->root = Kit::folder('git-project');
        $this->git(['init', '-q']);
        $this->ui = new RecordingUi();
        $this->context = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), $this->ui);
    }

    protected function tearDown(): void
    {
        putenv('HOME=' . $this->previousHome);
    }

    public function testStatusWorksInsideARepository(): void
    {
        Kit::write($this->root . '/notes.txt', "hi\n");

        $result = (new GitReadTool($this->root))->execute(['args' => ['status', '--short']], $this->context);

        self::assertTrue($result->ok, $result->content);
        self::assertStringContainsString('notes.txt', $result->content);
    }

    public function testReadToolRefusesCommandsThatChangeConfiguration(): void
    {
        $result = (new GitReadTool($this->root))->execute(['args' => ['config', '--list']], $this->context);

        self::assertFalse($result->ok);
        self::assertStringContainsString('config', $result->content);
    }

    public function testCommitRecordsTheChangeAndTheLogShowsIt(): void
    {
        $this->commitFile('a.txt', 'first commit');

        self::assertSame('first commit', trim($this->git(['log', '-1', '--format=%s'])));
        self::assertSame(['git add', 'git commit'], $this->context->changes->since(0));
    }

    public function testCommitWithoutAMessageIsRefused(): void
    {
        Kit::write($this->root . '/a.txt', "one\n");
        (new GitWriteTool($this->root))->execute(['args' => ['add', 'a.txt']], $this->context);

        $result = (new GitWriteTool($this->root))->execute(['args' => ['commit']], $this->context);

        self::assertFalse($result->ok);
        self::assertStringContainsString('needs -m or --message', $result->content);
        self::assertSame(['git add'], $this->context->changes->since(0));
    }

    public function testBranchesCanBeCreatedAndSafelyDeletedButNotForced(): void
    {
        $this->commitFile('a.txt', 'first commit');
        $write = new GitWriteTool($this->root);

        self::assertTrue($write->execute(['args' => ['branch', 'feature']], $this->context)->ok);
        self::assertTrue($write->execute(['args' => ['branch', '-d', 'feature']], $this->context)->ok);
        $forced = $write->execute(['args' => ['branch', '-D', 'feature']], $this->context);

        self::assertFalse($forced->ok);
        self::assertStringContainsString('refused in every git tool', $forced->content);
    }

    public function testPushToALocalRepositoryWorksAndForcingIsRefused(): void
    {
        $this->commitFile('a.txt', 'first commit');
        $bare = Kit::folder('git-remote') . '/remote.git';
        $this->command(['git', 'init', '--bare', '-q', $bare], sys_get_temp_dir());
        $this->git(['remote', 'add', 'origin', $bare]);
        $remote = new GitRemoteTool($this->root);

        $forced = $remote->execute(['args' => ['push', '--force', 'origin', 'main']], $this->context);
        self::assertFalse($forced->ok);

        $pushed = $remote->execute(['args' => ['push', '-u', 'origin', 'main']], $this->context);
        self::assertTrue($pushed->ok, $pushed->content);
        self::assertSame('first commit', trim($this->command(['git', '--git-dir=' . $bare, 'log', '-1', '--format=%s', 'main'], $bare)));
    }

    public function testRefusedCommandsLeaveNoChangeRecorded(): void
    {
        $this->commitFile('a.txt', 'first commit');
        $before = $this->context->changes->since(0);

        (new GitRemoteTool($this->root))->execute(['args' => ['push', 'origin', ':main']], $this->context);

        self::assertSame($before, $this->context->changes->since(0));
    }

    public function testARefusedCommandIsRejectedBeforeItIsOfferedForApproval(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('git refused');

        (new GitRemoteTool($this->root))->validate(['args' => ['push', '--force', 'origin', 'main']], $this->context);
    }

    public function testGitToolsAreOnlyAvailableInsideARepository(): void
    {
        $plain = Kit::folder('plain');

        self::assertFalse((new GitReadTool($plain))->isAvailable(Kit::policy()));
        self::assertTrue((new GitReadTool($this->root))->isAvailable(Kit::policy()));
    }

    private function commitFile(string $name, string $message): void
    {
        Kit::write($this->root . '/' . $name, $message . "\n");
        $write = new GitWriteTool($this->root);
        self::assertTrue($write->execute(['args' => ['add', $name]], $this->context)->ok);
        self::assertTrue($write->execute(['args' => ['commit', '-m', $message]], $this->context)->ok);
    }

    private function git(array $args): string
    {
        return $this->command(['git', '-C', $this->root, ...$args], $this->root);
    }

    private function command(array $argv, string $cwd): string
    {
        $result = (new ProcessRunner())->run($argv, $cwd, 30, 65536);
        self::assertTrue($result->succeeded(), implode(' ', $argv) . ': ' . $result->stderr);

        return $result->stdout;
    }
}
