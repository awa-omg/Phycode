<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\GlobTool;
use Phycode\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

final class GlobToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('glob'));
        foreach (['src/A.php' => 100, 'src/Deep/B.php' => 300, 'src/Deep/BTest.php' => 200, 'tests/ATest.php' => 400, 'README.md' => 50] as $file => $time) {
            Kit::write($this->root . '/' . $file, "x\n");
            touch($this->root . '/' . $file, $time);
        }
        Kit::write($this->root . '/vendor/lib/V.php', "x\n");
        Kit::write($this->root . '/.env', "TOKEN=1\n");
    }

    private function context(): ToolContext
    {
        return Kit::context($this->root, Kit::agent(), Kit::policy('ask'), new RecordingUi());
    }

    private function find(array $args): \Phycode\Tools\ToolResult
    {
        return (new GlobTool())->execute($args, $this->context());
    }

    private function git(): void
    {
        exec('git -C ' . escapeshellarg($this->root) . ' init -q 2>&1');
    }

    public function testFilesComeNewestFirstAndSkipDependenciesAndSecrets(): void
    {
        $result = $this->find(['pattern' => '*.php']);

        self::assertSame("tests/ATest.php\nsrc/Deep/B.php\nsrc/Deep/BTest.php\nsrc/A.php", $result->content);
        self::assertSame(['count' => 4], $result->meta);
        self::assertSame('no file matches', $this->find(['pattern' => '*.env'])->content);
    }

    public function testAPatternWithASlashIsRelativeToTheFolderGiven(): void
    {
        self::assertSame("src/Deep/B.php\nsrc/Deep/BTest.php\nsrc/A.php", $this->find(['pattern' => '**/*.php', 'path' => 'src'])->content);
        self::assertSame("src/Deep/B.php\nsrc/Deep/BTest.php", $this->find(['pattern' => 'Deep/*.php', 'path' => 'src'])->content);
        self::assertSame("src/Deep/BTest.php", $this->find(['pattern' => '**/*Test.php', 'path' => 'src'])->content);
    }

    public function testInsideAGitRepositoryTheIgnoredFilesAreLeftOut(): void
    {
        file_put_contents($this->root . '/.gitignore', "src/Deep/\nvendor/\n");
        $this->git();

        self::assertSame("tests/ATest.php\nsrc/A.php", $this->find(['pattern' => '*.php'])->content);
        self::assertSame("src/A.php", $this->find(['pattern' => '*.php', 'path' => 'src'])->content);
    }

    public function testMoreThanTheLimitIsCutAndSaysHowMany(): void
    {
        for ($i = 0; $i < 205; $i++) {
            Kit::write($this->root . "/many/f{$i}.txt", "x\n");
        }

        $result = $this->find(['pattern' => 'many/*.txt']);

        self::assertCount(201, explode("\n", $result->content));
        self::assertStringEndsWith('[... 5 more files; narrow the pattern or the folder]', $result->content);
        self::assertSame(['count' => 205], $result->meta);
    }

    public function testBadArgumentsAreRefusedWithAReason(): void
    {
        self::assertSame('pattern is empty, longer than 200 characters, or has too many ** in it', $this->find(['pattern' => ''])->content);
        self::assertSame('not a folder: README.md', $this->find(['pattern' => '*', 'path' => 'README.md'])->content);
        self::assertTrue(((new GlobTool())->forcesApproval(['path' => '.phycode/sessions'])));
        self::assertFalse((new GlobTool())->forcesApproval(['path' => 'src']));
    }

    public function testAFolderOutsideTheProjectIsRefused(): void
    {
        $this->expectException(\Phycode\Security\PathException::class);
        $this->find(['pattern' => '*', 'path' => '../']);
    }
}
