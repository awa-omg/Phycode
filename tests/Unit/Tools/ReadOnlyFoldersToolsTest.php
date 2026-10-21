<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Exec\ProcessRunner;
use Phycode\Security\PathException;
use Phycode\Security\Workspace;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\BashTool;
use Phycode\Tools\DeleteTool;
use Phycode\Tools\EditTool;
use Phycode\Tools\GitReadTool;
use Phycode\Tools\GrepTool;
use Phycode\Tools\ListTool;
use Phycode\Tools\MoveTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\RunCodeTool;
use Phycode\Tools\Tool;
use Phycode\Tools\ToolContext;
use Phycode\Tools\WriteTool;
use PHPUnit\Framework\TestCase;

final class ReadOnlyFoldersToolsTest extends TestCase
{
    private string $root;
    private string $extra;
    private ToolContext $context;

    protected function setUp(): void
    {
        $this->root = Kit::folder('ro-tools');
        $this->extra = Kit::folder('ro-tools-extra');
        Kit::write($this->root . '/src/app.php', "<?php\n");
        Kit::write($this->extra . '/notes.txt', "alpha\nbeta needle\n");
        Kit::write($this->extra . '/sub/deep.txt', "gamma\n");
        $this->context = $this->contextWith([$this->extra]);
    }

    public function testReadReadsAFileInTheReadOnlyFolder(): void
    {
        $result = (new ReadTool())->execute(['path' => $this->extra . '/notes.txt'], $this->context);

        self::assertTrue($result->ok);
        self::assertStringContainsString('beta needle', $result->content);
    }

    public function testReadRefusesAFileOutsideBothFolders(): void
    {
        $this->expectException(PathException::class);

        (new ReadTool())->execute(['path' => dirname($this->extra) . '/elsewhere.txt'], $this->context);
    }

    public function testListShowsTheReadOnlyFolderByItsFullPathAndTheProjectByItsRelativeOne(): void
    {
        $real = realpath($this->extra);

        $inside = (new ListTool())->execute(['path' => $this->extra, 'depth' => 2], $this->context);
        $project = (new ListTool())->execute(['depth' => 1], $this->context);

        self::assertTrue($inside->ok);
        self::assertStringContainsString($real . '/notes.txt', $inside->content);
        self::assertStringContainsString($real . '/sub/', $inside->content);
        self::assertStringContainsString($real . '/sub/deep.txt', $inside->content);
        self::assertStringContainsString('src/', $project->content);
        self::assertStringNotContainsString('notes.txt', $project->content, 'the project listing does not reach into the read-only folder');
    }

    public function testGrepSearchesTheReadOnlyFolderByItsFullPath(): void
    {
        $result = (new GrepTool())->execute(['pattern' => 'needle', 'path' => $this->extra], $this->context);

        self::assertTrue($result->ok);
        self::assertStringContainsString(realpath($this->extra) . '/notes.txt:2:', $result->content);
    }

    public function testGrepWithoutRipgrepSearchesTheReadOnlyFolderToo(): void
    {
        $path = getenv('PATH');
        putenv('PATH=');
        try {
            $result = (new GrepTool())->execute(['pattern' => 'needle', 'path' => $this->extra], $this->context);
        } finally {
            putenv('PATH=' . ($path === false ? '' : $path));
        }

        self::assertTrue($result->ok);
        self::assertStringContainsString(realpath($this->extra) . '/notes.txt:2:', $result->content);
    }

    public function testEveryChangeToTheReadOnlyFolderIsRefusedInValidateAndExecute(): void
    {
        $file = $this->extra . '/notes.txt';

        $this->assertRefused(new WriteTool(), ['path' => $file, 'content' => 'x']);
        $this->assertRefused(new WriteTool(), ['path' => $this->extra . '/new.txt', 'content' => 'x']);
        $this->assertRefused(new EditTool(), ['path' => $file, 'old_string' => 'alpha', 'new_string' => 'x']);
        $this->assertRefused(new DeleteTool(), ['path' => $file]);
        $this->assertRefused(new MoveTool(), ['from' => $file, 'to' => 'moved.txt']);
        $this->assertRefused(new MoveTool(), ['from' => 'src/app.php', 'to' => $this->extra . '/app.php']);

        self::assertSame("alpha\nbeta needle\n", file_get_contents($file));
        self::assertFileDoesNotExist($this->extra . '/new.txt');
        self::assertFileExists($this->root . '/src/app.php');
    }

    public function testBashRunCodeAndGitRefuseACommandThatNamesTheReadOnlyFolder(): void
    {
        $file = $this->extra . '/notes.txt';

        $this->assertRefusedBeforeApproval(new BashTool(), ['command' => "cat {$file}"]);
        $this->assertRefusedBeforeApproval(new RunCodeTool(), ['language' => 'python', 'code' => "print(open('{$file}').read())"]);
        $this->assertRefusedBeforeApproval(new GitReadTool($this->root), ['args' => ['log', '--', $file]]);
    }

    public function testAProjectCommandIsNotRefused(): void
    {
        (new BashTool())->validate(['command' => 'ls src'], $this->context);
        (new RunCodeTool())->validate(['language' => 'python', 'code' => 'print(1)'], $this->context);

        self::assertTrue(true);
    }

    public function testASubagentInheritsTheReadOnlyFolders(): void
    {
        $child = $this->context->forAgent(Kit::agent('explore'), Kit::policy('yolo'), new Cancellation(), 'test', new ProcessRunner());

        $result = (new ReadTool())->execute(['path' => $this->extra . '/sub/deep.txt'], $child);

        self::assertTrue($result->ok);
        self::assertStringContainsString('gamma', $result->content);
    }

    private function contextWith(array $readOnly): ToolContext
    {
        $base = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());

        return new ToolContext(
            new Workspace($this->root, $readOnly),
            $base->permissions,
            $base->processes,
            $base->ui,
            $base->transcript,
            $base->limits,
            $base->agent,
            $base->policy,
            $base->cancel,
            $base->profile,
            $base->delegator,
            $base->changes,
            $base->sandbox,
            $base->checkpoints,
        );
    }

    private function assertRefused(Tool $tool, array $arguments): void
    {
        foreach (['validate', 'execute'] as $step) {
            try {
                $tool->{$step}($arguments, $this->context);
            } catch (PathException $error) {
                self::assertStringContainsString('read-only folder', $error->getMessage(), $tool->name() . ' ' . $step);
                continue;
            }

            self::fail($tool->name() . " {$step} did not refuse the call");
        }
    }

    private function assertRefusedBeforeApproval(Tool $tool, array $arguments): void
    {
        try {
            $tool->validate($arguments, $this->context);
        } catch (PhycodeException $error) {
            self::assertStringContainsString('read-only folder', $error->getMessage(), $tool->name());

            return;
        }

        self::fail($tool->name() . ' did not refuse a command that names the read-only folder');
    }
}
