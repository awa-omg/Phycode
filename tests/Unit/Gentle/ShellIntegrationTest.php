<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Gentle;

use Phycode\Gentle\Approval;
use Phycode\Gentle\ShellIntegration;
use Phycode\Security\Decision;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Ui\ConsoleUi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShellIntegrationTest extends TestCase
{
    private const BIN = '/opt/phycode/bin';

    private array $environment = [];
    private string $home;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        foreach (['HOME', 'SHELL', 'ZDOTDIR'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        $this->home = Kit::folder('shell-home');
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testTheCommandLineShowsTheStatusAndExitsZero(): void
    {
        $this->useLoginShell('/bin/zsh');

        self::assertSame(0, ShellIntegration::cli(['status'], $this->ui));
        self::assertSame(["shell: zsh\nfile: {$this->home}/.zshrc\nblock: not installed\nbin: " . ShellIntegration::defaultBinDirectory()], $this->outputs());
    }

    public function testTheCommandLinePrintsTheStatusWithOneLineBreakPerLine(): void
    {
        $this->useLoginShell('/bin/zsh');
        $out = fopen('php://memory', 'w+');
        $console = new ConsoleUi($out, fopen('php://memory', 'w+'), fopen('php://memory', 'r'), false, false, false, false);

        self::assertSame(0, ShellIntegration::cli(['status'], $console));

        rewind($out);
        self::assertSame("shell: zsh\nfile: {$this->home}/.zshrc\nblock: not installed\nbin: " . ShellIntegration::defaultBinDirectory() . "\n", stream_get_contents($out));
    }

    public function testTheCommandLineInstallPrintsEachLineOnce(): void
    {
        $this->useLoginShell('/bin/zsh');
        $in = fopen('php://memory', 'w+');
        fwrite($in, "y\n");
        rewind($in);
        $out = fopen('php://memory', 'w+');
        $console = new ConsoleUi($out, fopen('php://memory', 'w+'), $in, false, false, false, true);
        $block = ShellIntegration::block(ShellIntegration::defaultBinDirectory());

        self::assertSame(0, ShellIntegration::cli(['install'], $console));

        rewind($out);
        self::assertSame(
            "/shell install\nfile: {$this->home}/.zshrc\n+ " . str_replace("\n", "\n+ ", rtrim($block, "\n"))
            . "\nadded the phycode PATH block to {$this->home}/.zshrc. Open a new shell, or run: source {$this->home}/.zshrc\n",
            stream_get_contents($out),
        );
    }

    public function testTheCommandLineInstallsAfterAYesAndUninstallsAfterAnother(): void
    {
        $this->useLoginShell('/bin/zsh');
        $this->ui->decisions = [Decision::AllowOnce, Decision::AllowOnce];

        self::assertSame(0, ShellIntegration::cli(['install'], $this->ui));
        self::assertStringContainsString('# >>> phycode >>>', $this->rcContent());
        self::assertSame(0, ShellIntegration::cli(['uninstall'], $this->ui));
        self::assertSame('', $this->rcContent());
    }

    public function testTheCommandLineRefusesAUsageMistakeWithExitOne(): void
    {
        $this->useLoginShell('/bin/zsh');

        self::assertSame(1, ShellIntegration::cli(['install', 'now'], $this->ui));
        self::assertSame(1, ShellIntegration::cli(['reset'], $this->ui));
        $usage = 'usage: phycode shell status, phycode shell install or phycode shell uninstall';
        self::assertSame([$usage, $usage], array_map(static fn (array $event): string => (string) $event[2], array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice'))));
        self::assertFileDoesNotExist($this->home . '/.zshrc');
    }

    public function testTheCommandLineReportsADamagedBlockWithExitOne(): void
    {
        $this->useLoginShell('/bin/zsh');
        $this->rc("# >>> phycode >>>\n");

        self::assertSame(1, ShellIntegration::cli(['install'], $this->ui));
        self::assertStringContainsString('the phycode block in the shell file is damaged', (string) $this->ui->events[array_key_last($this->ui->events)][2]);
        self::assertSame("# >>> phycode >>>\n", $this->rcContent());
    }

    public function testTheBlockIsThreeLinesBetweenTwoMarkers(): void
    {
        self::assertSame(
            "# >>> phycode >>>\nexport PATH='/opt/phycode/bin':\"\$PATH\"\n# <<< phycode <<<\n",
            ShellIntegration::block(self::BIN),
        );
    }

    public function testAFolderWithAQuoteIsQuotedForTheShell(): void
    {
        self::assertStringContainsString("export PATH='/opt/it'\\''s here/bin':\"\$PATH\"", ShellIntegration::block("/opt/it's here/bin"));
    }

    public function testALineBreakInTheFolderIsRefused(): void
    {
        $this->expectException(PhycodeException::class);

        ShellIntegration::block("/opt/a\nb/bin");
    }

    public static function rcFiles(): iterable
    {
        yield 'zsh' => ['/bin/zsh', null, '%HOME%/.zshrc'];
        yield 'zsh with ZDOTDIR' => ['/usr/bin/zsh', '/srv/zdot', '/srv/zdot/.zshrc'];
        yield 'zsh with a ZDOTDIR that is not absolute' => ['/bin/zsh', 'zdot', '%HOME%/.zshrc'];
        yield 'bash' => ['/usr/bin/bash', null, '%HOME%/.bashrc'];
    }

    #[DataProvider('rcFiles')]
    public function testTheLoginShellChoosesTheRcFile(?string $shell, ?string $zdotdir, string $expected): void
    {
        self::assertSame(str_replace('%HOME%', $this->home, $expected), (new ShellIntegration($this->home, self::BIN, $shell, $zdotdir))->file());
    }

    public function testAnotherShellIsRefusedWithTheLineToAddByHand(): void
    {
        try {
            (new ShellIntegration($this->home, self::BIN, '/usr/bin/fish'))->file();
            self::fail('fish is not supported');
        } catch (PhycodeException $error) {
            self::assertSame(
                'phycode shell supports zsh and bash, not fish. Add this line to your profile yourself: export PATH=\'/opt/phycode/bin\':"$PATH"',
                $error->getMessage(),
            );
        }
    }

    public function testAnUnknownLoginShellIsRefused(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('the login shell is not known');

        (new ShellIntegration($this->home, self::BIN, null))->file();
    }

    public function testInstallWithoutAYesWritesNothing(): void
    {
        $this->ui->decisions = [Decision::Deny];

        $message = $this->shell()->install($this->approval());

        self::assertSame('not approved: nothing was changed.', $message);
        self::assertFileDoesNotExist($this->home . '/.zshrc');
        self::assertCount(1, $this->ui->requests);
    }

    public function testInstallKeepsEveryOtherLineAndAddsTheBlockOnce(): void
    {
        $original = "alias ll='ls -l'\nexport EDITOR=vi\n\n# my own comment\n";
        $this->rc($original);
        $this->ui->decisions = [Decision::AllowOnce];
        $shell = $this->shell();

        $first = $shell->install($this->approval());
        $second = $shell->install($this->approval());

        self::assertSame("added the phycode PATH block to {$this->home}/.zshrc. Open a new shell, or run: source {$this->home}/.zshrc", $first);
        self::assertSame($original . ShellIntegration::block(self::BIN), $this->rcContent());
        self::assertSame("{$this->home}/.zshrc has the phycode block for " . self::BIN . ' already; nothing changed.', $second);
        self::assertCount(1, $this->ui->requests, 'the second install finds the block and asks nothing');
    }

    public function testInstallAddsALineBreakToAFileThatEndsWithoutOne(): void
    {
        $this->rc("export A=1");
        $this->ui->decisions = [Decision::AllowOnce];

        $this->shell()->install($this->approval());

        self::assertSame("export A=1\n" . ShellIntegration::block(self::BIN), $this->rcContent());
        self::assertStringContainsString("note: a line break is added after the last line of {$this->home}/.zshrc", $this->detail());
    }

    public function testInstallReplacesAnOldBlockInPlaceAndKeepsTheLinesAroundIt(): void
    {
        $this->rc("before\n" . ShellIntegration::block('/old/phycode/bin') . "after\n");
        $this->ui->decisions = [Decision::AllowOnce];

        $this->shell()->install($this->approval());

        self::assertSame("before\n" . ShellIntegration::block(self::BIN) . "after\n", $this->rcContent());
        self::assertStringContainsString("- export PATH='/old/phycode/bin':\"\$PATH\"", $this->detail());
        self::assertStringContainsString("+ export PATH='/opt/phycode/bin':\"\$PATH\"", $this->detail());
    }

    public function testUninstallRemovesExactlyTheBlock(): void
    {
        $original = "alias ll='ls -l'\n\nexport X=1\n";
        $this->rc($original . ShellIntegration::block(self::BIN));
        $this->ui->decisions = [Decision::AllowOnce];

        $message = $this->shell()->uninstall($this->approval());

        self::assertSame("removed the phycode PATH block from {$this->home}/.zshrc.", $message);
        self::assertSame($original, $this->rcContent());
    }

    public function testUninstallWithoutAYesKeepsTheBlock(): void
    {
        $content = "x\n" . ShellIntegration::block(self::BIN);
        $this->rc($content);
        $this->ui->decisions = [Decision::Deny];

        self::assertSame('not approved: nothing was changed.', $this->shell()->uninstall($this->approval()));
        self::assertSame($content, $this->rcContent());
    }

    public function testUninstallWithoutABlockAsksNothing(): void
    {
        $this->rc("alias x=y\n");

        self::assertSame("{$this->home}/.zshrc has no phycode block; nothing to remove.", $this->shell()->uninstall($this->approval()));
        self::assertSame([], $this->ui->requests);
    }

    public static function damagedBlocks(): iterable
    {
        yield 'a start without an end' => ["# >>> phycode >>>\nexport PATH='/x':\"\$PATH\"\n"];
        yield 'an end without a start' => ["export PATH='/x':\"\$PATH\"\n# <<< phycode <<<\n"];
        yield 'two blocks' => [ShellIntegration::block('/a') . ShellIntegration::block('/b')];
        yield 'the end before the start' => ["# <<< phycode <<<\n# >>> phycode >>>\n"];
    }

    #[DataProvider('damagedBlocks')]
    public function testADamagedBlockIsRefusedAndNothingIsChanged(string $content): void
    {
        $this->rc($content);
        $this->ui->decisions = [Decision::AllowOnce];
        $shell = $this->shell();

        foreach (['install', 'uninstall'] as $action) {
            try {
                $shell->{$action}($this->approval());
                self::fail("{$action} must refuse a damaged block");
            } catch (PhycodeException $error) {
                self::assertStringContainsString('the phycode block in the shell file is damaged', $error->getMessage());
            }
        }
        self::assertSame($content, $this->rcContent());
        self::assertSame([], $this->ui->requests);
    }

    public function testASymbolicLinkRcFileIsRefused(): void
    {
        $target = Kit::folder('dotfiles') . '/zshrc';
        Kit::write($target, "export X=1\n");
        symlink($target, $this->home . '/.zshrc');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage("cannot change {$this->home}/.zshrc: it is a symbolic link");

        $this->shell()->install($this->approval());
    }

    public function testTheApprovalShowsTheLinesThatChange(): void
    {
        $this->ui->decisions = [Decision::AllowOnce];

        $this->shell()->install($this->approval());

        self::assertSame("file: {$this->home}/.zshrc\n+ # >>> phycode >>>\n+ export PATH='/opt/phycode/bin':\"\$PATH\"\n+ # <<< phycode <<<", $this->detail());
        self::assertSame("add the phycode PATH block to {$this->home}/.zshrc", $this->ui->requests[0]->summary);
    }

    public function testTheStatusNamesTheFileAndThePlaceOfTheBlockAndChangesNothing(): void
    {
        self::assertStringContainsString('block: not installed', $this->shell()->status());
        self::assertFileDoesNotExist($this->home . '/.zshrc');

        $this->rc(ShellIntegration::block(self::BIN));
        self::assertStringContainsString('block: installed for this bin folder', $this->shell()->status());

        $this->rc(ShellIntegration::block('/elsewhere/bin'));
        self::assertStringContainsString('block: installed for another folder; /shell install replaces it', $this->shell()->status());
    }

    public function testTheStatusOfAnUnsupportedShellSaysWhatToDo(): void
    {
        $status = (new ShellIntegration($this->home, self::BIN, '/usr/bin/fish'))->status();

        self::assertStringStartsWith("shell: fish\nphycode shell supports zsh and bash, not fish.", $status);
    }

    public function testBlocksAreRemovedExactlyAndAddedAtTheEnd(): void
    {
        $block = ShellIntegration::block(self::BIN);

        foreach (['', "a\n", "a\nb\n\n", "a\n\n# >>> comment\n"] as $content) {
            self::assertSame($content, ShellIntegration::withoutBlock(ShellIntegration::withBlock($content, $block)));
        }
        self::assertNull(ShellIntegration::withBlock($block, $block));
        self::assertNull(ShellIntegration::withoutBlock("plain\n"));
    }

    public function testTheDefaultFolderIsTheBinOfThisCheckout(): void
    {
        self::assertSame(dirname(__DIR__, 3) . '/bin', ShellIntegration::defaultBinDirectory());
    }

    private function shell(): ShellIntegration
    {
        return new ShellIntegration($this->home, self::BIN, '/bin/zsh');
    }

    private function approval(): Approval
    {
        return new Approval($this->ui, fn (string $title, string $text) => $this->ui->panel($title, $text));
    }

    private function rc(string $content): void
    {
        file_put_contents($this->home . '/.zshrc', $content);
    }

    private function useLoginShell(string $shell): void
    {
        putenv("HOME={$this->home}");
        putenv("SHELL={$shell}");
        putenv('ZDOTDIR');
    }

    private function outputs(): array
    {
        return array_values(array_map(
            static fn (array $event): string => (string) $event[1],
            array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'output'),
        ));
    }

    private function rcContent(): string
    {
        return (string) file_get_contents($this->home . '/.zshrc');
    }

    private function detail(): string
    {
        $panels = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'panel'));

        return (string) $panels[array_key_last($panels)][2];
    }
}
