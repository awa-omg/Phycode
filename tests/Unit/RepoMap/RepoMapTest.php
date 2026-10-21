<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\RepoMap;

use Phycode\Exec\ProcessRunner;
use Phycode\RepoMap\RepoMap;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\RepoMapTool;
use PHPUnit\Framework\TestCase;

final class RepoMapTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath(Kit::folder('repo-map'));
        Kit::write($this->root . '/src/Loader.php', "<?php\n\nfinal class Loader\n{\n    public function load(string \$path): array\n    {\n        return [];\n    }\n}\n");
        Kit::write($this->root . '/src/Runner.php', "<?php\n\nfinal class Runner\n{\n    public function run(Loader \$loader): void\n    {\n        \$loader->load('x');\n    }\n}\n");
        Kit::write($this->root . '/src/Main.php', "<?php\n\nfinal class Main\n{\n    public function go(): void\n    {\n        (new Loader())->load('y');\n        new Runner();\n    }\n}\n");
        Kit::write($this->root . '/src/Unused.php', "<?php\n\nfinal class Unused\n{\n}\n");
        Kit::write($this->root . '/web/slug.js', "export function slugify(text) {\n  return text;\n}\n");
        Kit::write($this->root . '/web/app.js', "import { slugify } from './slug.js';\nexport function render(title) {\n  return slugify(title);\n}\n");
        Kit::write($this->root . '/vendor/lib/Dep.php', "<?php\nclass Dep {}\n");
        Kit::write($this->root . '/README.md', "# Project\n");
    }

    private function map(bool $git = false): RepoMap
    {
        if ($git) {
            exec('git -C ' . escapeshellarg($this->root) . ' init -q 2>&1');
            file_put_contents($this->root . '/.gitignore', "vendor/\n");
        }

        return new RepoMap($this->root, $git ? new ProcessRunner() : null);
    }

    public function testTheFilesThatTheRestOfTheCodeUsesComeFirstAndTheUnusedOneLast(): void
    {
        $result = $this->map()->build();

        self::assertSame(6, $result['files'], 'the vendor folder and the files without definitions are not in it');
        $order = array_values(array_filter(explode("\n", $result['text']), static fn (string $line): bool => $line !== '' && !str_starts_with($line, ' ') && !str_starts_with($line, 'Repository') && !str_starts_with($line, '...')));
        self::assertSame('src/Loader.php', $order[0], 'used by Runner and Main');
        self::assertGreaterThanOrEqual(4, array_search('src/Unused.php', $order, true), 'after the four files that something uses');
        self::assertLessThan(array_search('src/Unused.php', $order, true), array_search('web/slug.js', $order, true), 'a function used by another file beats a class that nothing uses');
        self::assertStringContainsString("src/Loader.php\n  class Loader :3\n    load(string \$path): array :5", $result['text']);
        self::assertStringContainsString("web/slug.js\n  slugify(text) :1", $result['text']);
        self::assertStringStartsWith('Repository map of the project: 6 files define', $result['text']);
        self::assertStringNotContainsString('Dep', $result['text']);
    }

    public function testWordsOfAFocusBringTheFilesThatMatchFirst(): void
    {
        $result = $this->map()->build('unused');

        self::assertStringContainsString('focused on "unused"', $result['text']);
        self::assertSame('src/Unused.php', explode("\n", $result['text'])[2]);
    }

    public function testASmallBudgetShowsTheBestFilesAndSaysHowManyAreLeftOut(): void
    {
        for ($n = 0; $n < 60; $n++) {
            Kit::write($this->root . "/many/File{$n}.php", "<?php\nfinal class File{$n}\n{\n    public function method{$n}(string \$a, string \$b, string \$c): string\n    {\n        return '';\n    }\n}\n");
        }

        $result = $this->map()->build('', '.', 500);

        self::assertGreaterThan(0, $result['files'] - $result['shown']);
        self::assertLessThan(2600, strlen($result['text']), 'about 500 tokens');
        self::assertStringContainsString('more files with definitions are not shown', $result['text']);
        self::assertStringContainsString('src/Loader.php', $result['text'], 'the best file is in it');
    }

    public function testAFolderIsMappedOnItsOwn(): void
    {
        $result = $this->map()->build('', 'web');

        self::assertSame(2, $result['files']);
        self::assertStringContainsString('Repository map of web:', $result['text']);
        self::assertStringNotContainsString('Loader', $result['text']);
    }

    public function testWithGitTheIgnoredFilesAreLeftOutAndTheUntrackedOnesAreIn(): void
    {
        Kit::write($this->root . '/vendor/lib/Extra.php', "<?php\nclass Extra {}\n");
        Kit::write($this->root . '/build/Out.php', "<?php\nclass Out {}\n");
        file_put_contents($this->root . '/.gitignore', "build/\n");
        exec('git -C ' . escapeshellarg($this->root) . ' init -q 2>&1');
        file_put_contents($this->root . '/.gitignore', "vendor/\nbuild/\n");

        $result = (new RepoMap($this->root, new ProcessRunner()))->build();

        self::assertStringNotContainsString('Dep', $result['text']);
        self::assertStringNotContainsString('Out', $result['text']);
        self::assertStringContainsString('src/Loader.php', $result['text'], 'untracked, not ignored');
    }

    public function testWithoutGitTheFoldersOfDependenciesAndBuildsAreSkipped(): void
    {
        Kit::write($this->root . '/node_modules/x/index.js', "function hidden() {}\n");
        Kit::write($this->root . '/dist/out.js', "function built() {}\n");

        $text = $this->map()->build()['text'];

        self::assertStringNotContainsString('hidden', $text);
        self::assertStringNotContainsString('built', $text);
    }

    public function testAFileThatChangedIsReadAgainAndALargeOrBinaryOneIsLeftOut(): void
    {
        $map = $this->map();
        $map->build();

        Kit::write($this->root . '/src/Unused.php', "<?php\n\nfinal class Unused\n{\n}\n\nfinal class Brandnew\n{\n}\n");
        file_put_contents($this->root . '/src/Huge.php', "<?php\nclass Huge {}\n" . str_repeat('// padding' . "\n", 60000));
        file_put_contents($this->root . '/src/Binary.php', "class Bin {}\n" . str_repeat("\0", 100));
        $text = $map->build()['text'];

        self::assertStringContainsString('class Brandnew', $text);
        self::assertStringNotContainsString('Huge', $text);
        self::assertStringNotContainsString('Bin ', $text);
    }

    public function testAProjectWithoutCodeHasNoFiles(): void
    {
        $empty = Kit::folder('repo-map-empty');
        Kit::write($empty . '/notes.txt', "hello\n");

        self::assertSame(0, (new RepoMap((string) realpath($empty)))->build()['files']);
    }

    public function testTheToolMapsTheProjectFocusesAndChecksItsArguments(): void
    {
        $tool = new RepoMapTool();
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('ask'), new RecordingUi());

        $result = $tool->execute(['query' => 'runner'], $context);
        self::assertTrue($result->ok);
        self::assertSame('src/Runner.php', explode("\n", $result->content)[2]);
        self::assertSame(6, $result->meta['files']);
        self::assertSame(\Phycode\Tools\ToolCategory::Read, $tool->category());

        self::assertStringContainsString('Repository map of web:', $tool->execute(['path' => 'web'], $context)->content);
        $empty = Kit::folder('repo-map-none');
        Kit::write($empty . '/notes.txt', "hello\n");
        $none = $tool->execute([], Kit::context((string) realpath($empty), Kit::agent(), Kit::policy('ask'), new RecordingUi()));
        self::assertStringStartsWith('No files with classes or functions were found', $none->content);

        foreach ([[['path' => 'README.md'], 'is not a folder of the project'], [['tokens' => 100], 'tokens must be a whole number from 500 to 8000'], [['tokens' => '2000'], 'tokens must be a whole number']] as [$args, $message]) {
            try {
                $tool->validate($args, $context);
                self::fail($message);
            } catch (PhycodeException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        $this->expectException(\Phycode\Security\PathException::class);
        $tool->validate(['path' => '../outside'], $context);
    }
}
