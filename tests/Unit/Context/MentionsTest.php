<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Context;

use Phycode\Context\Mentions;
use Phycode\Security\Workspace;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MentionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('mentions'));
        Kit::write($this->root . '/src/Foo.php', "<?php\necho 1;\n");
        Kit::write($this->root . '/src/Bar.php', "<?php\necho 2;\n");
        Kit::write($this->root . '/notes/my file.txt', "spaced\n");
        Kit::write($this->root . '/.env', "TOKEN=abc\n");
        Kit::write($this->root . '/src/.env.local', "TOKEN=abc\n");
    }

    private function attach(string $text): array
    {
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('ask'), new RecordingUi());

        return (new Mentions())->attach($text, $context);
    }

    public static function texts(): iterable
    {
        yield 'one' => ['look at @src/Foo.php', ['src/Foo.php']];
        yield 'punctuation after' => ['see @src/Foo.php, and @src/Bar.php.', ['src/Foo.php', 'src/Bar.php']];
        yield 'in parentheses' => ['(@src/Foo.php)', ['src/Foo.php']];
        yield 'quoted with a space' => ['read @"notes/my file.txt" please', ['notes/my file.txt']];
        yield 'twice' => ['@src/Foo.php and again @src/Foo.php', ['src/Foo.php']];
        yield 'an email is not a mention' => ['write to me@example.com', []];
        yield 'a double at is not a mention' => ['@@src/Foo.php', []];
        yield 'a handle' => ['thanks @john', ['john']];
        yield 'nothing' => ['no mentions here', []];
    }

    #[DataProvider('texts')]
    public function testTheTokensAreThePathsAfterAnAtSign(string $text, array $tokens): void
    {
        self::assertSame($tokens, Mentions::tokens($text));
    }

    public function testAFileIsAppendedWithNumberedLinesAndTheTextIsKept(): void
    {
        $result = $this->attach('explain @src/Foo.php');

        self::assertSame(['src/Foo.php'], $result['attached']);
        self::assertSame([], $result['notes']);
        self::assertSame("explain @src/Foo.php\n\nContents of src/Foo.php, mentioned by the user with @:\n     1  <?php\n     2  echo 1;", $result['text']);
    }

    public function testAFolderIsListedWithItsSubfoldersMarkedAndSecretsLeftOut(): void
    {
        mkdir($this->root . '/src/deep');

        $result = $this->attach('what is in @src');

        self::assertSame(['src'], $result['attached']);
        self::assertStringContainsString("Entries of src/, mentioned by the user with @:\nBar.php\nFoo.php\ndeep/", $result['text']);
        self::assertStringNotContainsString('.env.local', $result['text']);
    }

    public function testASecretFileIsNeverAttachedAndTheUserIsTold(): void
    {
        $result = $this->attach('use @.env and @src/Foo.php');

        self::assertSame(['src/Foo.php'], $result['attached']);
        self::assertStringNotContainsString('TOKEN=abc', $result['text']);
        self::assertSame(["@.env was not attached: it usually holds secrets or phycode's own files"], $result['notes']);
    }

    public function testAPathOutsideTheProjectIsNotAttachedAndAHandleIsIgnoredSilently(): void
    {
        symlink('/etc', $this->root . '/etc-link');

        $result = $this->attach('@/etc/passwd @etc-link/hostname @john');

        self::assertSame([], $result['attached']);
        self::assertSame('@/etc/passwd @etc-link/hostname @john', $result['text']);
        self::assertCount(2, $result['notes']);
        self::assertStringContainsString('@/etc/passwd was not attached', $result['notes'][0]);
        self::assertStringContainsString('through a symbolic link', $result['notes'][1]);
    }

    public function testABinaryFileAndAnImageAreSkippedWithAReason(): void
    {
        file_put_contents($this->root . '/blob.bin', "\0\1\2\3binary");

        $result = $this->attach('@blob.bin');

        self::assertSame([], $result['attached']);
        self::assertSame(['@blob.bin was not attached: it is not a text file the model can read; /image shows an image'], $result['notes']);
    }

    public function testOnlyTheFirstMentionsAreAttachedAndTheTotalSizeIsBounded(): void
    {
        $names = [];
        for ($i = 0; $i < Mentions::MAX_MENTIONS + 2; $i++) {
            Kit::write($this->root . "/f{$i}.txt", "line {$i}\n");
            $names[] = "@f{$i}.txt";
        }

        $result = $this->attach(implode(' ', $names));

        self::assertCount(Mentions::MAX_MENTIONS, $result['attached']);
        self::assertSame(['only the first 6 mentions are attached; the model can read the others'], $result['notes']);

        for ($i = 0; $i < 3; $i++) {
            file_put_contents($this->root . "/big{$i}.txt", str_repeat(str_repeat('x', 199) . "\n", 200));
        }
        $big = $this->attach('@big0.txt @big1.txt @big2.txt');
        self::assertCount(1, $big['attached']);
        self::assertCount(2, $big['notes']);
        self::assertStringContainsString('already fill the 60 KB limit', $big['notes'][0]);
        self::assertLessThan(Mentions::MAX_CHARS + 200, strlen($big['text']));
    }

    public function testExistingTellsAPathFromAnythingElse(): void
    {
        $workspace = new Workspace($this->root);

        self::assertSame($this->root . '/src/Foo.php', Mentions::existing('src/Foo.php', $workspace));
        self::assertNull(Mentions::existing('explore', $workspace));
        self::assertNull(Mentions::existing('../outside', $workspace));
    }
}
