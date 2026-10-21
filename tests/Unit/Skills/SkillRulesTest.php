<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Skills;

use Phycode\Skills\SkillException;
use Phycode\Skills\SkillRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SkillRulesTest extends TestCase
{
    public static function validNames(): iterable
    {
        yield 'one letter' => ['a'];
        yield 'words with hyphens' => ['go-testing'];
        yield 'letters and digits' => ['api-v2'];
        yield 'digits only' => ['2024'];
        yield 'the longest name' => [str_repeat('a', 64)];
    }

    #[DataProvider('validNames')]
    public function testAValidNameIsAccepted(string $name): void
    {
        SkillRules::checkName($name);

        $this->expectNotToPerformAssertions();
    }

    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'capital letters' => ['Go-testing'];
        yield 'underscore' => ['go_testing'];
        yield 'dot' => ['go.testing'];
        yield 'space' => ['go testing'];
        yield 'leading hyphen' => ['-go'];
        yield 'trailing hyphen' => ['go-'];
        yield 'double hyphen' => ['go--testing'];
        yield 'one character too long' => [str_repeat('a', 65)];
        yield 'a parent folder' => ['../secret'];
        yield 'a slash' => ['a/b'];
        yield 'a newline at the end' => ["go\n"];
        yield 'the registry name' => ['skill-registry'];
        yield 'an sdd skill' => ['sdd-init'];
    }

    #[DataProvider('invalidNames')]
    public function testAnInvalidNameIsRefused(string $name): void
    {
        $this->expectException(SkillException::class);

        SkillRules::checkName($name);
    }

    public function testAReservedNameSaysWhy(): void
    {
        $this->expectException(SkillException::class);
        $this->expectExceptionMessage("sdd-init is a reserved name: gentle-ai's registry leaves out skills with that name");

        SkillRules::checkName('sdd-init');
    }

    public function testADescriptionOfUpToOneThousandTwentyFourCharactersIsAccepted(): void
    {
        SkillRules::checkDescription('x');
        SkillRules::checkDescription(str_repeat('é', 1024));

        $this->expectNotToPerformAssertions();
    }

    public static function badDescriptions(): iterable
    {
        yield 'empty' => [''];
        yield 'one character too long' => [str_repeat('a', 1025)];
        yield 'two lines' => ["first\nsecond"];
        yield 'a tab' => ["a\tb"];
    }

    #[DataProvider('badDescriptions')]
    public function testADescriptionThatIsNotOneLineOfTheRightLengthIsRefused(string $description): void
    {
        $this->expectException(SkillException::class);
        $this->expectExceptionMessage('the description must be one line of 1 to 1024 characters');

        SkillRules::checkDescription($description);
    }

    private static function skill(string $name = 'go-testing', string $description = 'Write Go tests.', string $body = "Use table tests.\n"): string
    {
        return "---\nname: {$name}\ndescription: {$description}\n---\n{$body}";
    }

    public function testAWellFormedSkillFileIsAccepted(): void
    {
        SkillRules::checkSkillFile(self::skill(), 'go-testing');
        SkillRules::checkSkillFile("---\r\nname: go-testing\r\ndescription: Write Go tests.\r\n---\r\nUse table tests.\r\n", 'go-testing');

        $this->expectNotToPerformAssertions();
    }

    public static function badSkillFiles(): iterable
    {
        yield 'no frontmatter' => ["# Title\nBody.\n", 'SKILL.md must start with its frontmatter'];
        yield 'frontmatter that never ends' => ["---\nname: go-testing\ndescription: x\nBody.\n", 'the frontmatter starts with --- but never ends'];
        yield 'invalid YAML' => ["---\nname: go-testing\ndescription: [unclosed\n---\nBody.\n", 'invalid YAML in the frontmatter'];
        yield 'no name' => ["---\ndescription: Write Go tests.\n---\nBody.\n", 'the frontmatter name must be go-testing'];
        yield 'the name of another folder' => [self::skill('other'), 'the frontmatter name must be go-testing'];
        yield 'no description' => ["---\nname: go-testing\n---\nBody.\n", 'the frontmatter needs a description, as text'];
        yield 'a description that is a number' => ["---\nname: go-testing\ndescription: 42\n---\nBody.\n", 'the frontmatter needs a description, as text'];
        yield 'a description of 1025 characters' => [self::skill(description: str_repeat('a', 1025)), 'the description must be one line'];
        yield 'an empty body' => [self::skill(body: "   \n"), 'the body of SKILL.md is empty'];
        yield 'larger than 16 KB' => [self::skill(body: str_repeat('a', 16385)), 'SKILL.md is larger than 16 KB'];
    }

    #[DataProvider('badSkillFiles')]
    public function testAnInvalidSkillFileIsRefusedWithTheReason(string $text, string $message): void
    {
        $this->expectException(SkillException::class);
        $this->expectExceptionMessage($message);

        SkillRules::checkSkillFile($text, 'go-testing');
    }

    public static function validPaths(): iterable
    {
        yield 'a reference' => ['references/api.md'];
        yield 'a nested template' => ['templates/report/summary.txt'];
        yield 'a script' => ['scripts/run.sh'];
        yield 'an asset' => ['assets/logo.png'];
    }

    #[DataProvider('validPaths')]
    public function testAFileUnderASupportFolderIsAccepted(string $path): void
    {
        SkillRules::checkFilePath($path);

        $this->expectNotToPerformAssertions();
    }

    public static function invalidPaths(): iterable
    {
        yield 'the skill file itself' => ['SKILL.md'];
        yield 'a folder that is not a support folder' => ['docs/api.md'];
        yield 'a support folder alone' => ['references'];
        yield 'a trailing slash' => ['references/'];
        yield 'an empty part' => ['references//api.md'];
        yield 'a dot part' => ['references/./api.md'];
        yield 'a parent part' => ['references/../SKILL.md'];
        yield 'an absolute path' => ['/etc/passwd'];
        yield 'a backslash' => ['references\\api.md'];
        yield 'a NUL byte' => ["references/a\0.md"];
        yield 'a control character' => ["references/a\x07.md"];
        yield 'an empty path' => [''];
        yield 'a path that is too long' => ['references/' . str_repeat('a', 510)];
    }

    #[DataProvider('invalidPaths')]
    public function testAPathOutsideTheSupportFoldersIsRefused(string $path): void
    {
        $this->expectException(SkillException::class);
        $this->expectExceptionMessage('file_path must be a file under references/, templates/, scripts/ or assets/');

        SkillRules::checkFilePath($path);
    }

    public function testAFileOfOneMegabyteIsTheLargestAccepted(): void
    {
        SkillRules::checkFileSize(str_repeat('a', 1048576));

        $this->expectNotToPerformAssertions();
    }

    public function testAFileLargerThanOneMegabyteIsRefused(): void
    {
        $this->expectException(SkillException::class);

        SkillRules::checkFileSize(str_repeat('a', 1048577));
    }
}
