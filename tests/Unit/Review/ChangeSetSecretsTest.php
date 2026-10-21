<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Review;

use Phycode\Exec\ProcessRunner;
use Phycode\Review\ChangeSet;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class ChangeSetSecretsTest extends TestCase
{
    public function testAnUntrackedSecretFileIsNotInTheTextButANormalOneIs(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n"]);
        Kit::write($root . '/.env', "API_TOKEN=env-secret-value\n");
        Kit::write($root . '/.env.example', "API_TOKEN=placeholder\n");
        Kit::write($root . '/certs/key.pem', "-----BEGIN PRIVATE KEY-----\npem-secret-value\n");
        Kit::write($root . '/src/new.php', "<?php\n// normal new file\n");

        $set = ChangeSet::read(new ProcessRunner(), $root, null);

        self::assertStringNotContainsString('env-secret-value', $set->text);
        self::assertStringNotContainsString('pem-secret-value', $set->text);
        self::assertStringContainsString('+API_TOKEN=placeholder', $set->text, 'a template is not a secret file');
        self::assertStringContainsString('+// normal new file', $set->text);
        self::assertStringContainsString('2 changed files were withheld from the review: files with these names usually hold secrets', $set->text);
        self::assertSame(2, $set->withheld);
        self::assertSame(['.env', '.env.example', 'certs/key.pem', 'src/new.php'], $set->files, 'the names are still listed');
    }

    public function testAChangedTrackedSecretFileIsWithheldAndTheOtherChangesAreNot(): void
    {
        $root = ReviewRepo::create(['.env' => "API_TOKEN=committed-old\n", 'src/a.php' => "<?php\n"]);
        Kit::write($root . '/.env', "API_TOKEN=changed-new\n");
        Kit::write($root . '/src/a.php', "<?php\n// changed\n");

        $set = ChangeSet::read(new ProcessRunner(), $root, null);

        self::assertStringNotContainsString('changed-new', $set->text);
        self::assertStringNotContainsString('committed-old', $set->text);
        self::assertStringContainsString('+// changed', $set->text);
        self::assertSame(1, $set->withheld);
    }

    public function testAChangeSetWithOnlySecretFilesIsEmptyAndSaysSo(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n"]);
        Kit::write($root . '/.env', "API_TOKEN=only-secret\n");

        $set = ChangeSet::read(new ProcessRunner(), $root, null);

        self::assertTrue($set->isEmpty());
        self::assertStringNotContainsString('only-secret', $set->text);
        self::assertSame(['.env'], $set->files);
        self::assertSame('1 file, 0 bytes, 1 withheld (usually secrets)', $set->summary());
    }

    public function testTheNoteIsKeptWhenTheChangeSetIsCut(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n"]);
        Kit::write($root . '/.env', "API_TOKEN=cut-secret\n");
        Kit::write($root . '/src/a.php', "<?php\n" . str_repeat("echo 'a line of the change';\n", 6000));

        $set = ChangeSet::read(new ProcessRunner(), $root, null);

        self::assertTrue($set->cut);
        self::assertStringStartsWith('[... 1 changed file was withheld', $set->text);
        self::assertStringNotContainsString('cut-secret', $set->text);
    }
}
