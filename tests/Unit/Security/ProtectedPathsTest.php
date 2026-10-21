<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\PathException;
use Phycode\Session\Transcript;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\DeleteTool;
use Phycode\Tools\EditTool;
use Phycode\Tools\ListTool;
use Phycode\Tools\MoveTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\Tool;
use Phycode\Tools\ToolContext;
use Phycode\Tools\WriteTool;
use PHPUnit\Framework\TestCase;

final class ProtectedPathsTest extends TestCase
{
    private string $root;
    private ToolContext $context;

    protected function setUp(): void
    {
        $this->root = Kit::folder('protected');
        Kit::write($this->root . '/src/app.php', "<?php\n");
        $this->context = Kit::context($this->root, Kit::agent(), Kit::policy('ask'), new RecordingUi());
    }

    public function testSymbolicLinkToAFileOutsideTheProjectIsRefused(): void
    {
        $outside = Kit::folder('outside');
        Kit::write($outside . '/secret.txt', "secret\n");
        symlink($outside . '/secret.txt', $this->root . '/file-link');

        $reason = 'path leaves the project through a symbolic link';
        $this->assertRefused(new WriteTool(), ['path' => 'file-link', 'content' => 'x'], $reason);
        $this->assertRefused(new EditTool(), ['path' => 'file-link', 'old_string' => 'secret', 'new_string' => 'x'], $reason);
        $this->assertRefused(new DeleteTool(), ['path' => 'file-link'], $reason);
        $this->assertRefused(new MoveTool(), ['from' => 'file-link', 'to' => 'moved.txt'], $reason);
        self::assertSame("secret\n", file_get_contents($outside . '/secret.txt'));
    }

    public function testSymbolicLinkFolderInsideTheProjectThatPointsOutsideIsRefused(): void
    {
        $outside = Kit::folder('outside');
        Kit::write($outside . '/secret.txt', "secret\n");
        symlink($outside, $this->root . '/dir-link');

        $reason = 'path leaves the project through a symbolic link';
        $this->assertRefused(new WriteTool(), ['path' => 'dir-link/new.txt', 'content' => 'x'], $reason);
        $this->assertRefused(new EditTool(), ['path' => 'dir-link/secret.txt', 'old_string' => 'secret', 'new_string' => 'x'], $reason);
        $this->assertRefused(new MoveTool(), ['from' => 'src/app.php', 'to' => 'dir-link/app.php'], $reason);
        self::assertFileDoesNotExist($outside . '/new.txt');
        self::assertSame("secret\n", file_get_contents($outside . '/secret.txt'));
        self::assertFileExists($this->root . '/src/app.php');
    }

    public function testGitConfigIsRefusedAtAnyDepth(): void
    {
        Kit::write($this->root . '/.git/config', "[core]\n");
        Kit::write($this->root . '/sub/.git/config', "[core]\n");

        $this->assertRefused(new WriteTool(), ['path' => '.git/config', 'content' => 'x'], 'protected folder: .git/config');
        $this->assertRefused(new EditTool(), ['path' => 'sub/.git/config', 'old_string' => '[core]', 'new_string' => '[x]'], 'protected folder: sub/.git/config');
        $this->assertRefused(new DeleteTool(), ['path' => 'sub/.git/config'], 'protected folder: sub/.git/config');
        $this->assertRefused(new MoveTool(), ['from' => 'src/app.php', 'to' => 'sub/.git/hooks/pre-commit'], 'protected folder: sub/.git/hooks/pre-commit');
        self::assertSame("[core]\n", file_get_contents($this->root . '/.git/config'));
    }

    public function testClaudeAgentFilesAreRefused(): void
    {
        $agent = "---\nname: x\ndescription: x\n---\nx\n";
        Kit::write($this->root . '/.claude/agents/x.md', $agent);

        $this->assertRefused(new WriteTool(), ['path' => '.claude/agents/x.md', 'content' => "---\nname: x\n---\nowned\n"], 'protected folder: .claude/agents/x.md');
        $this->assertRefused(new EditTool(), ['path' => '.claude/agents/x.md', 'old_string' => 'x', 'new_string' => 'y'], 'protected folder: .claude/agents/x.md');
        $this->assertRefused(new DeleteTool(), ['path' => '.claude/agents/x.md'], 'protected folder: .claude/agents/x.md');
        $this->assertRefused(new WriteTool(), ['path' => '.Claude/agents/x.md', 'content' => 'x'], 'protected folder: .Claude/agents/x.md');
        self::assertSame($agent, file_get_contents($this->root . '/.claude/agents/x.md'));
    }

    public function testPhycodeFolderIsRefused(): void
    {
        Kit::write($this->root . '/.phycode/x', "keep\n");

        $this->assertRefused(new WriteTool(), ['path' => '.phycode/x', 'content' => 'x'], 'protected folder: .phycode/x');
        $this->assertRefused(new DeleteTool(), ['path' => '.phycode/x'], 'protected folder: .phycode/x');
        self::assertSame("keep\n", file_get_contents($this->root . '/.phycode/x'));
    }

    public function testSymbolicLinkThatReachesAProtectedFolderIsRefused(): void
    {
        Kit::write($this->root . '/.git/config', "[core]\n");
        Kit::write($this->root . '/.claude/agents/x.md', "x\n");
        symlink($this->root . '/.claude/agents', $this->root . '/agents-link');
        symlink($this->root . '/.git', $this->root . '/git-link');

        $this->assertRefused(new WriteTool(), ['path' => 'agents-link/y.md', 'content' => 'x'], 'protected folder: .claude/agents/y.md');
        $this->assertRefused(new DeleteTool(), ['path' => 'agents-link/x.md'], 'protected folder: .claude/agents/x.md');
        $this->assertRefused(new EditTool(), ['path' => 'git-link/config', 'old_string' => '[core]', 'new_string' => '[x]'], 'protected folder: .git/config');
        self::assertSame("[core]\n", file_get_contents($this->root . '/.git/config'));
    }

    public function testReadsAndListingsOfProtectedFoldersStillWork(): void
    {
        Kit::write($this->root . '/.git/config', "[core]\n");
        Kit::write($this->root . '/.claude/agents/x.md', "---\nname: x\n---\nAgent body.\n");
        Kit::write($this->root . '/.phycode/note.txt', "Remember this.\n");

        foreach (['.git/config' => '[core]', '.claude/agents/x.md' => 'Agent body.', '.phycode/note.txt' => 'Remember this.'] as $path => $text) {
            $result = (new ReadTool())->execute(['path' => $path], $this->context);
            self::assertTrue($result->ok, $path);
            self::assertStringContainsString($text, $result->content, $path);
        }

        $listing = (new ListTool())->execute(['path' => '.claude/agents'], $this->context);
        self::assertTrue($listing->ok);
        self::assertStringContainsString('x.md', $listing->content);
    }

    public function testTranscriptWritesAreUnaffectedByTheToolGuard(): void
    {
        $this->assertRefused(new WriteTool(), ['path' => '.phycode/sessions/by-hand.jsonl', 'content' => "x\n"], 'protected folder: .phycode/sessions/by-hand.jsonl');

        $transcript = Transcript::open($this->root . '/.phycode/sessions');
        $transcript->event('tool_call', ['tool' => 'write', 'arguments' => ['path' => '.git/config']], 'build', 0);

        self::assertFileExists($this->root . '/.phycode/.gitignore');
        self::assertStringContainsString('"type":"tool_call"', (string) file_get_contents($transcript->path));
    }

    public function testMovingAFolderThatHoldsAClaudeAgentIsRefusedBeforeApproval(): void
    {
        Kit::write($this->root . '/pkg/.claude/agents/x.md', "---\nname: x\n---\nx\n");

        $this->assertRefused(new MoveTool(), ['from' => 'pkg', 'to' => 'renamed'], 'protected folder: pkg/.claude');
        self::assertFileExists($this->root . '/pkg/.claude/agents/x.md');
        self::assertDirectoryDoesNotExist($this->root . '/renamed');
    }

    public function testMovingAFolderWithAProtectedFolderDeepBelowItIsRefusedWhateverItsCase(): void
    {
        Kit::write($this->root . '/pkg/a/b/.GIT/config', "[core]\n");

        $this->assertRefused(new MoveTool(), ['from' => 'pkg', 'to' => 'renamed'], 'protected folder: pkg/a/b/.GIT');
        self::assertFileExists($this->root . '/pkg/a/b/.GIT/config');
    }

    public function testAFolderWithALinkBackToItselfCanStillBeMoved(): void
    {
        Kit::write($this->root . '/pkg/file.txt', "x\n");
        symlink($this->root . '/pkg', $this->root . '/pkg/loop');

        $result = (new MoveTool())->execute(['from' => 'pkg', 'to' => 'renamed'], $this->context);

        self::assertTrue($result->ok);
        self::assertFileExists($this->root . '/renamed/file.txt');
        self::assertTrue(is_link($this->root . '/renamed/loop'));
    }

    public function testDeletingWithAPhycodeLinkOutsideTheProjectIsRefusedBecauseTheTrashWouldLeaveIt(): void
    {
        $outside = Kit::folder('trash-outside');
        symlink($outside, $this->root . '/.phycode');

        $this->assertRefused(new DeleteTool(), ['path' => 'src/app.php'], 'path leaves the project through a symbolic link: .phycode/trash');
        self::assertFileExists($this->root . '/src/app.php');
        self::assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
    }

    private function assertRefused(Tool $tool, array $arguments, string $reason): void
    {
        foreach (['validate', 'execute'] as $step) {
            try {
                $tool->{$step}($arguments, $this->context);
            } catch (PathException $error) {
                self::assertStringContainsString($reason, $error->getMessage(), $tool->name() . ' ' . $step);
                continue;
            }

            self::fail($tool->name() . " {$step} did not refuse the call");
        }
    }
}
