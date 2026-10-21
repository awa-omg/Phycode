<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Gentle;

use Phycode\Gentle\Approval;
use Phycode\Gentle\OddTask;
use Phycode\Security\Decision;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OddTaskTest extends TestCase
{
    private string $root;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->root = Kit::folder('odd-project');
        $this->ui = new RecordingUi();
    }

    public static function features(): iterable
    {
        yield 'a slug' => ['add-login', true];
        yield 'digits first' => ['2fa', true];
        yield 'upper case' => ['Add-Login', false];
        yield 'a space' => ['add login', false];
        yield 'a path' => ['../escape', false];
        yield 'empty' => ['', false];
        yield 'too long' => [str_repeat('a', 64), false];
    }

    #[DataProvider('features')]
    public function testOnlySlugsAreFeatureNames(string $feature, bool $valid): void
    {
        if ($valid) {
            self::assertSame($this->root . "/odd/tasks/{$feature}.md", OddTask::path($this->root, $feature));

            return;
        }
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('lowercase letters, digits and dashes');

        OddTask::path($this->root, $feature);
    }

    public function testTheTemplateFollowsTheSectionOrderOfTheConvention(): void
    {
        $template = OddTask::template('add-login');

        self::assertStringStartsWith("# ODD Task: add-login\n", $template);
        self::assertStringContainsString('Branch: feat/add-login  Strategy: ask-on-risk  Runner: (the test command)', $template);
        $specs = (int) strpos($template, '## Specs');
        $tasks = (int) strpos($template, '## Tasks');
        $log = (int) strpos($template, '## Log');
        self::assertTrue($specs > 0 && $specs < $tasks && $tasks < $log, 'the sections are Specs, Tasks and Log, in that order');
        self::assertStringContainsString('L1 (verbatim request): /odd add-login', $template);
        self::assertStringEndsWith("\n", $template);
    }

    public function testTheFileIsCreatedAfterAYesWithTheTemplate(): void
    {
        $this->ui->decisions = [Decision::AllowOnce];

        $message = OddTask::create($this->root, 'add-login', $this->approval());

        self::assertSame('created odd/tasks/add-login.md. Fill in the objective, the specs and the runner before the first write.', $message);
        self::assertSame(OddTask::template('add-login'), file_get_contents($this->root . '/odd/tasks/add-login.md'));
        self::assertSame(0644, fileperms($this->root . '/odd/tasks/add-login.md') & 0777);
        $detail = (string) $this->ui->events[0][2];
        self::assertStringContainsString("create {$this->root}/odd/tasks/add-login.md (the folder odd/tasks is made first) with this content:", $detail);
        self::assertStringContainsString('# ODD Task: add-login', $detail);
    }

    public function testWithoutAYesNothingIsCreated(): void
    {
        $this->ui->decisions = [Decision::Deny];

        self::assertSame('not approved: nothing was changed.', OddTask::create($this->root, 'add-login', $this->approval()));
        self::assertDirectoryDoesNotExist($this->root . '/odd');
    }

    public function testAnExistingTaskIsNeverOverwrittenAndIsNotAskedFor(): void
    {
        Kit::write($this->root . '/odd/tasks/add-login.md', "my own notes\n");

        try {
            OddTask::create($this->root, 'add-login', $this->approval());
            self::fail('an existing task must not be overwritten');
        } catch (PhycodeException $error) {
            self::assertSame('odd/tasks/add-login.md exists already; nothing changed', $error->getMessage());
        }
        self::assertSame("my own notes\n", file_get_contents($this->root . '/odd/tasks/add-login.md'));
        self::assertSame([], $this->ui->requests);
    }

    public function testAFileWhereTheFolderShouldBeIsRefused(): void
    {
        Kit::write($this->root . '/odd', 'not a folder');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('odd is a link or not a folder; nothing changed');

        OddTask::create($this->root, 'add-login', $this->approval());
    }

    public function testASymbolicLinkFolderIsRefused(): void
    {
        $elsewhere = Kit::folder('odd-elsewhere');
        symlink($elsewhere, $this->root . '/odd');

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('odd is a link or not a folder; nothing changed');

        OddTask::create($this->root, 'add-login', $this->approval());
    }

    private function approval(): Approval
    {
        return new Approval($this->ui, fn (string $title, string $text) => $this->ui->panel($title, $text));
    }
}
