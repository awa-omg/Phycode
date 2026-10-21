<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Exec\ProcessRunner;
use Phycode\Security\PathException;
use Phycode\Support\Files;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\DeleteTool;
use Phycode\Tools\RunCodeTool;
use Phycode\Tools\ToolContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuntimeFolderTest extends TestCase
{
    private string $root;
    private ToolContext $context;

    protected function setUp(): void
    {
        $this->root = Kit::folder('runtime');
        Kit::write($this->root . '/src/a.txt', "keep\n");
        Kit::write($this->root . '/.git/config', "[core]\n");
        $this->context = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());
    }

    public static function trashLinks(): iterable
    {
        yield '.phycode is a link to .git' => ['.phycode', '.git'];
        yield '.phycode is a link to a project folder' => ['.phycode', 'src'];
        yield '.phycode/trash is a link to .git' => ['.phycode/trash', '../.git'];
    }

    public static function tmpLinks(): iterable
    {
        yield '.phycode is a link to .git' => ['.phycode', '.git'];
        yield '.phycode is a link to a project folder' => ['.phycode', 'src'];
        yield '.phycode/tmp is a link to .git' => ['.phycode/tmp', '../.git'];
    }

    #[DataProvider('trashLinks')]
    public function testDeleteRefusesATrashThatIsALinkAndLeavesTheFileAndTheTargetAlone(string $link, string $target): void
    {
        $this->link($link, $target);

        foreach (['validate', 'execute'] as $step) {
            try {
                (new DeleteTool())->{$step}(['path' => 'src/a.txt'], $this->context);
            } catch (PathException $error) {
                self::assertStringContainsString('symbolic link', $error->getMessage(), "delete {$step}");
                continue;
            }
            self::fail("delete {$step} did not refuse the link");
        }
        self::assertSame("keep\n", file_get_contents($this->root . '/src/a.txt'));
        self::assertSame(['config'], $this->entries('.git'), 'nothing is moved into .git');
    }

    #[DataProvider('tmpLinks')]
    public function testRunCodeRefusesItsTemporaryFolderWhenItIsALink(string $link, string $target): void
    {
        $this->link($link, $target);
        $arguments = ['language' => 'python', 'code' => 'print(1)'];

        try {
            (new RunCodeTool())->validate($arguments, $this->context);
            self::fail('run_code did not refuse before the approval prompt');
        } catch (PathException $error) {
            self::assertStringContainsString('symbolic link', $error->getMessage());
        }

        if (ProcessRunner::which('python3') === null) {
            self::markTestSkipped('python3 is not installed, so run_code cannot reach its folder check');
        }
        try {
            (new RunCodeTool())->execute($arguments, $this->context);
            self::fail('run_code did not refuse the link');
        } catch (PathException $error) {
            self::assertStringContainsString('symbolic link', $error->getMessage());
        }
        self::assertSame(['config'], $this->entries('.git'), 'no snippet is written into .git');
    }

    public function testRunCodeRunsFromARealTemporaryFolder(): void
    {
        if (ProcessRunner::which('python3') === null) {
            self::markTestSkipped('python3 is not installed');
        }

        $result = (new RunCodeTool())->execute(['language' => 'python', 'code' => 'print("phycode-ok")'], $this->context);

        self::assertTrue($result->ok, $result->content);
        self::assertStringContainsString('phycode-ok', $result->content);
        self::assertSame([], glob($this->root . '/.phycode/tmp/run-*') ?: [], 'the snippet is removed after the run');
    }

    public function testADeleteThroughARealTrashFolderStillWorks(): void
    {
        $result = (new DeleteTool())->execute(['path' => 'src/a.txt'], $this->context);

        self::assertTrue($result->ok, $result->content);
        self::assertCount(1, glob($this->root . '/.phycode/trash/*/src/a.txt') ?: []);
    }

    public function testTheRuntimeFolderIsTheProjectFolderWhenItIsReal(): void
    {
        self::assertSame($this->root . '/.phycode/tmp', Files::runtimeFolder($this->root, 'tmp'));
    }

    public function testTheRuntimeFolderIsRefusedWhenItsParentIsALink(): void
    {
        $this->link('.phycode', '.git');

        $this->expectException(PathException::class);
        $this->expectExceptionMessage('.phycode is a symbolic link to .git');
        Files::runtimeFolder($this->root, 'trash');
    }

    private function link(string $link, string $target): void
    {
        if (str_contains($link, '/')) {
            mkdir(dirname($this->root . '/' . $link), 0700, true);
        }
        symlink($target, $this->root . '/' . $link);
    }

    private function entries(string $relative): array
    {
        return array_values(array_diff(scandir($this->root . '/' . $relative) ?: [], ['.', '..']));
    }
}
