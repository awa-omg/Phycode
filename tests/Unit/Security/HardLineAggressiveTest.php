<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\HardLine;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HardLineAggressiveTest extends TestCase
{
    private string $root;
    private string|false $previousHome;

    protected function setUp(): void
    {
        $this->root = (string) realpath(Kit::folder('hard-line-aggressive'));
        foreach (['homes/me', 'homes/neighbour/docs', 'homes/other', 'project/build', 'project/src', 'project/many'] as $folder) {
            mkdir($this->root . '/' . $folder, 0755, true);
        }
        symlink($this->root . '/homes/neighbour', $this->root . '/project/shortcut');
        $this->previousHome = getenv('HOME');
        putenv('HOME=' . $this->root . '/homes/me');
    }

    protected function tearDown(): void
    {
        putenv($this->previousHome === false ? 'HOME' : 'HOME=' . $this->previousHome);
    }

    public static function destructiveGlobs(): iterable
    {
        yield 'every home with a trailing slash' => ['rm -rf %T/homes/*/'];
        yield 'every home' => ['rm -rf %T/homes/*'];
        yield 'the contents of every folder that matches h*' => ['rm -rf %T/h*/*'];
        yield 'a neighbour by prefix' => ['rm -rf %T/homes/n*'];
        yield 'a neighbour through a globbed parent' => ['rm -rf %T/h*s/neighbour'];
        yield 'single-character wildcards' => ['rm -rf %T/h?mes/n?ighbour/'];
        yield 'bracket classes' => ['rm -rf %T/[h]omes/[n]eighbour'];
        yield 'a brace list of homes' => ['rm -rf %T/homes/{neighbour,other}'];
        yield 'the dot files of the homes folder' => ['rm -rf %T/homes/.*'];
        yield 'a recursive chmod of every home' => ['chmod -R 777 %T/homes/*'];
        yield 'a recursive chown of a neighbour by glob' => ['chown -R me %T/homes/n*'];
    }

    #[DataProvider('destructiveGlobs')]
    public function testAGlobIsExpandedAndEveryMatchIsJudged(string $command): void
    {
        self::assertNotNull(HardLine::reason('bash: ' . $this->fill($command), $this->root . '/project'), $command);
    }

    public static function destructive(): iterable
    {
        yield 'find with -exec rm over a neighbour' => ['find %T/homes/neighbour -exec rm -rf {} +'];
        yield 'find with -execdir rm over every home' => ['find %T/homes -execdir rm -rf {} \;'];
        yield 'find with a path to rm and a symlink option' => ['find -L %T/homes/neighbour -exec /bin/rm {} \;'];
        yield 'find with -delete after a test' => ['find %T/homes/neighbour -name "*.txt" -delete'];
        yield 'rsync that deletes the extra files of a home' => ['rsync -a --delete %T/project/build/ %T/homes/neighbour/'];
        yield 'rsync that moves the files out of a home' => ['rsync -a --remove-source-files %T/homes/neighbour/ elsewhere:/backup'];
        yield 'rm after cd to the homes folder' => ['cd %T/homes && rm -rf *'];
        yield 'rm of the dot after cd to a neighbour' => ['cd %T/homes/neighbour; rm -rf .'];
        yield 'rm of the parent after cd into the project' => ['cd %T/project && rm -rf ../homes'];
        yield 'rm through a link made in the same command' => ['ln -s %T/homes/neighbour link && rm -rf link/'];
        yield 'rm through a link that already exists in the project' => ['rm -rf shortcut/'];
        yield 'rm of the contents behind a link that exists' => ['rm -rf shortcut/*'];
        yield 'shred of the files of a home' => ['shred -uz %T/homes/neighbour/*'];
        yield 'git clean of a home' => ['git clean -fdx %T/homes/neighbour'];
        yield 'git -C clean of a home' => ['git -C %T/homes/neighbour clean -fd'];
        yield 'a variable set before' => ['D=%T/homes/neighbour; rm -rf "$D"'];
        yield 'a variable with braces' => ['D=%T/homes/neighbour; rm -rf ${D}/'];
        yield 'an exported variable' => ['export D=%T/homes/neighbour && rm -rf $D'];
        yield 'the root in a variable' => ['x=/; rm -rf "$x"'];
        yield 'HOME with a guard' => ['rm -rf "${HOME:?}"'];
        yield 'HOME with a default' => ['rm -rf "${HOME:-/}"'];
        yield 'a trap that deletes' => ['trap "rm -rf /" EXIT'];
        yield 'a trap after dashes' => ["trap -- 'rm -rf ~' EXIT; true"];
        yield 'an alias that deletes' => ['alias wipe="rm -rf /"'];
        yield 'ssh-agent in front' => ['ssh-agent rm -rf /'];
        yield 'parallel in front' => ['parallel rm -rf ::: /'];
        yield 'sudo with an option in front' => ['sudo -u root -- rm -rf /'];
        yield 'doas in front' => ['doas rm -rf /'];
        yield 'a function that deletes' => ['f() { rm -rf /; }; f'];
        yield 'a loop over the root' => ['for d in / ; do rm -rf $d; done'];
        yield 'a subshell' => ['(rm -rf /)'];
        yield 'nohup in the background' => ['nohup rm -rf / &'];
        yield 'shred of the root contents' => ['shred -u /*'];
    }

    #[DataProvider('destructive')]
    public function testADestructiveCommandIsRefused(string $command): void
    {
        self::assertNotNull(HardLine::reason('bash: ' . $this->fill($command), $this->root . '/project'), $command);
    }

    public function testACommandNestedTooDeepToReadIsRefused(): void
    {
        $command = 'echo hello';
        for ($level = 0; $level < 9; $level++) {
            $command = 'bash -c ' . escapeshellarg($command);
        }

        self::assertNotNull(HardLine::reason('bash: ' . $command, $this->root . '/project'));
    }

    public function testAShallowNestingIsRead(): void
    {
        $command = 'echo hello';
        for ($level = 0; $level < 3; $level++) {
            $command = 'bash -c ' . escapeshellarg($command);
        }

        self::assertNull(HardLine::reason('bash: ' . $command, $this->root . '/project'));
    }

    public static function ordinary(): iterable
    {
        yield 'a glob in the project' => ['rm -rf %T/project/b*'];
        yield 'a glob that matches only files of the project' => ['rm -rf %T/project/s*/*.o'];
        yield 'cd into the project and delete a folder' => ['cd %T/project && rm -rf build'];
        yield 'a relative glob in the project' => ['rm -rf build/*'];
        yield 'find that deletes in the project' => ['find . -name "*.pyc" -exec rm {} +'];
        yield 'find -delete in a subfolder' => ['find build -name "*.o" -delete'];
        yield 'find without a delete' => ['find %T/homes -name "*.txt"'];
        yield 'rsync -a --delete inside the project' => ['rsync -a --delete src/ build/'];
        yield 'rsync without --delete into a home' => ['rsync -a src/ %T/homes/neighbour/'];
        yield 'git clean in the project' => ['git clean -fdx'];
        yield 'git clean without force' => ['git clean -n %T/homes/neighbour'];
        yield 'a variable that names a project folder' => ['x=build; rm -rf "$x"'];
        yield 'a trap that deletes a project folder' => ['trap "rm -rf %T/project/build" EXIT'];
        yield 'a link to a project folder' => ['ln -s %T/project/build link && rm -rf link/'];
        yield 'shred of one file' => ['shred -u %T/project/secret.txt'];
        yield 'a project of its own folder below a top-level one' => ['rm -rf /srv/phycode-test-project'];
        yield 'a cache folder of an application below /opt' => ['rm -rf /opt/phycode-test-app/cache'];
        yield 'a temporary folder made by the build' => ['rm -rf /tmp/phycode-test-build'];
        yield 'a mention of rm in an alias that is only printed' => ['alias'];
        yield 'an unset variable that is only read' => ['echo "$UNSET_PHYCODE_VARIABLE"'];
    }

    #[DataProvider('ordinary')]
    public function testAnOrdinaryCommandIsNotOnTheLine(string $command): void
    {
        self::assertNull(HardLine::reason('bash: ' . $this->fill($command), $this->root . '/project'), $command);
    }

    public function testAGlobWithManyMatchesIsNotAFalsePositive(): void
    {
        for ($at = 0; $at < 4200; $at++) {
            touch($this->root . '/project/many/object' . $at . '.o');
        }

        self::assertNull(HardLine::reason('bash: rm -rf ' . $this->root . '/project/many/o*.o', $this->root . '/project'));
        self::assertNotNull(HardLine::reason('bash: rm -rf ' . $this->root . '/homes/n*', $this->root . '/project'));
    }

    public function testAFolderInsideAHomeIsNotTheHome(): void
    {
        self::assertNull(HardLine::reason('bash: rm -rf ' . $this->root . '/homes/neighbour/docs', $this->root . '/project'));
        self::assertNull(HardLine::reason('bash: rm -rf ' . $this->root . '/homes/*/docs', $this->root . '/project'));
        self::assertNotNull(HardLine::reason('bash: rm -rf ' . $this->root . '/homes/neighbour', $this->root . '/project'));
    }

    public function testAGlobThatMatchesNothingIsJudgedByItsOwnText(): void
    {
        self::assertNull(HardLine::reason('bash: rm -rf ' . $this->root . '/project/nothing-*', $this->root . '/project'));
        self::assertNotNull(HardLine::reason('bash: rm -rf /*', $this->root . '/project'));
    }

    public function testWithoutAProjectFolderARelativeTargetIsNotGuessed(): void
    {
        self::assertNull(HardLine::reason('bash: rm -rf build'));
        self::assertNotNull(HardLine::reason('bash: rm -rf /'));
    }

    private function fill(string $command): string
    {
        return str_replace('%T', $this->root, $command);
    }
}
