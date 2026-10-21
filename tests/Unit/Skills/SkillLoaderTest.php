<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Skills;

use Phycode\Gentle\GentleAi;
use Phycode\Skills\SkillCatalog;
use Phycode\Skills\SkillLoader;
use Phycode\Skills\SkillRegistry;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Gentle\FakeGentleAi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SkillLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Kit::folder('skills');
    }

    public function testLoadReturnsTheSkillFile(): void
    {
        $content = "---\nname: go-testing\n---\nWrite Go tests.\n";
        $loader = new SkillLoader($this->registry(['go-testing' => $this->skill('go-testing', $content)]));

        self::assertSame($content, $loader->load('go-testing'));
    }

    public function testTheFileIsOnlyRead(): void
    {
        $path = $this->skill('api', 'REST rules');
        chmod($path, 0444);
        $before = [md5_file($path), fileperms($path), filemtime($path)];

        $loader = new SkillLoader($this->registry(['api' => $path]));

        self::assertSame('REST rules', $loader->load('api'));
        self::assertSame($before, [md5_file($path), fileperms($path), filemtime($path)]);
    }

    public function testALongFileIsCutToTheLimitAndTheCutIsSaid(): void
    {
        $path = $this->skill('big', str_repeat('a', 70000));

        $text = (new SkillLoader($this->registry(['big' => $path])))->load('big');

        self::assertLessThanOrEqual(SkillLoader::MAX_BYTES, strlen($text));
        self::assertStringStartsWith(str_repeat('a', 65000), $text);
        self::assertStringContainsString('[... truncated, ', $text);
    }

    public function testAFileOfExactlyTheLimitIsReturnedWhole(): void
    {
        $content = str_repeat('b', SkillLoader::MAX_BYTES);
        $loader = new SkillLoader($this->registry(['exact' => $this->skill('exact', $content)]));

        self::assertSame($content, $loader->load('exact'));
    }

    public function testTheCutNeverLeavesABrokenCharacter(): void
    {
        $path = $this->skill('accents', 'a' . str_repeat('é', 40000));

        $text = (new SkillLoader($this->registry(['accents' => $path])))->load('accents');

        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
        self::assertStringStartsWith('a', $text);
    }

    public static function badNames(): iterable
    {
        yield 'parent folder' => ['../secret'];
        yield 'a slash' => ['a/b'];
        yield 'a backslash' => ['a\\b'];
        yield 'a dot' => ['go.testing'];
        yield 'the current folder' => ['.'];
        yield 'empty' => [''];
        yield 'a NUL byte' => ["api\0x"];
    }

    #[DataProvider('badNames')]
    public function testANameWithAPathPieceOrADotIsRefused(string $name): void
    {
        $bin = FakeGentleAi::install(['skills' => '[]']);
        $loader = new SkillLoader(new SkillRegistry(new GentleAi(), $bin));

        self::assertFails('a skill name cannot be empty or contain a slash, a backslash, a dot or a NUL byte', fn () => $loader->load($name));
        self::assertSame([], FakeGentleAi::calls($bin), 'nothing is listed for a refused name');
    }

    public function testANameThatIsNotListedIsAnError(): void
    {
        $loader = new SkillLoader($this->registry(['api' => $this->skill('api', 'x')]));

        self::assertFails('no skill named go-testing', fn () => $loader->load('go-testing'));
    }

    public function testTheReasonWhyNothingIsListedIsGiven(): void
    {
        $loader = new SkillLoader(new SkillRegistry(new GentleAi(), '/nonexistent/gentle-ai'));

        self::assertFails('no skill named go-testing (' . GentleAi::NOT_INSTALLED . ')', fn () => $loader->load('go-testing'));
    }

    public function testASkillPhycodeSavedIsReadEvenWithoutGentleAiAndBeforeTheRegistryOfTheSameName(): void
    {
        $listed = $this->skill('listed/go-testing', "---\nname: go-testing\ndescription: From gentle-ai.\n---\nfrom the registry\n");
        $saved = $this->root . '/saved';
        Kit::write("{$saved}/go-testing/SKILL.md", "---\nname: go-testing\ndescription: Saved by phycode.\n---\nsaved by phycode\n");

        self::assertStringContainsString('saved by phycode', (new SkillLoader($this->registry(['go-testing' => $listed]), places: [[SkillCatalog::PHYCODE, $saved]]))->load('go-testing'));
        self::assertStringContainsString(
            'saved by phycode',
            (new SkillLoader(new SkillRegistry(new GentleAi(), '/nonexistent/gentle-ai'), places: [[SkillCatalog::PHYCODE, $saved]]))->load('go-testing'),
        );
    }

    public function testAFolderWithoutAValidSkillFileIsNotASkillForTheLoader(): void
    {
        $saved = $this->root . '/invalid';
        Kit::write("{$saved}/go-testing/SKILL.md", "no frontmatter\n");
        $listed = $this->skill('listed/go-testing', "---\nname: go-testing\ndescription: From gentle-ai.\n---\nfrom the registry\n");

        self::assertStringContainsString('from the registry', (new SkillLoader($this->registry(['go-testing' => $listed]), places: [[SkillCatalog::PHYCODE, $saved]]))->load('go-testing'));
    }

    public function testAListedFileThatIsGoneIsAnError(): void
    {
        $path = $this->skill('api', 'x');
        $loader = new SkillLoader($this->registry(['api' => $path]));
        unlink($path);

        self::assertFails("cannot read the skill file {$path}", fn () => $loader->load('api'));
    }

    private function registry(array $paths): SkillRegistry
    {
        $rows = [];
        foreach ($paths as $name => $path) {
            $rows[] = ['name' => $name, 'scope' => 'user', 'description' => "The {$name} skill.", 'path' => $path];
        }
        $bin = FakeGentleAi::install(['skills' => json_encode($rows, JSON_THROW_ON_ERROR)]);

        return new SkillRegistry(new GentleAi(), $bin);
    }

    private function skill(string $name, string $content): string
    {
        $path = "{$this->root}/{$name}/SKILL.md";
        Kit::write($path, $content);

        return $path;
    }

    private static function assertFails(string $message, callable $call): void
    {
        try {
            $call();
        } catch (PhycodeException $error) {
            self::assertSame($message, $error->getMessage());

            return;
        }
        self::fail("expected a PhycodeException: {$message}");
    }
}
