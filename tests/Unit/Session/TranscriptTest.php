<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Session;

use Phycode\Session\Transcript;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use PHPUnit\Framework\TestCase;

final class TranscriptTest extends TestCase
{
    public function testSessionFolderInsidePhycodeIgnoresItselfInGit(): void
    {
        $project = Kit::folder('transcript');

        $transcript = Transcript::open($project . '/.phycode/sessions');

        self::assertSame("*\n", file_get_contents($project . '/.phycode/.gitignore'));
        self::assertFileExists($transcript->path);
    }

    public function testAnExistingIgnoreFileIsKept(): void
    {
        $project = Kit::folder('transcript');
        Kit::write($project . '/.phycode/.gitignore', "custom\n");

        Transcript::open($project . '/.phycode/sessions');

        self::assertSame("custom\n", file_get_contents($project . '/.phycode/.gitignore'));
    }

    public function testAPhycodeLinkOutsideTheProjectWritesNothingAndTheSessionCarriesOn(): void
    {
        $project = Kit::folder('transcript-link');
        $outside = Kit::folder('transcript-outside');
        symlink($outside, $project . '/.phycode');

        $ui = new RecordingUi();
        $transcript = Transcript::open($project . '/.phycode/sessions', $ui);
        $transcript->event('tool_call', ['tool' => 'read'], 'build', 0);
        $transcript->event('tool_result', ['tool' => 'read', 'ok' => true], 'build', 0);

        self::assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
        self::assertSame('memory', $transcript->path);
        self::assertStringContainsString('"type":"tool_result"', $transcript->contents());
        self::assertCount(1, $this->notices($ui), 'the refusal is reported once per session');
        self::assertStringContainsString('.phycode/sessions', $this->notices($ui)[0]);
    }

    public function testADanglingPhycodeLinkCreatesNothingOutsideTheProject(): void
    {
        $project = Kit::folder('transcript-dangling');
        $outside = Kit::folder('transcript-outside');
        symlink($outside . '/missing', $project . '/.phycode');

        Transcript::open($project . '/.phycode/sessions', new RecordingUi());

        self::assertFileDoesNotExist($outside . '/missing');
    }

    public function testASessionsFolderLinkedOutsideTheProjectIsRefused(): void
    {
        $project = Kit::folder('transcript-sessions');
        $outside = Kit::folder('transcript-outside');
        mkdir($project . '/.phycode', 0700);
        symlink($outside, $project . '/.phycode/sessions');

        $ui = new RecordingUi();
        Transcript::open($project . '/.phycode/sessions', $ui);

        self::assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
        self::assertCount(1, $this->notices($ui));
    }

    public function testADanglingIgnoreLinkIsNotWrittenThrough(): void
    {
        $project = Kit::folder('transcript-ignore');
        $outside = Kit::folder('transcript-outside');
        mkdir($project . '/.phycode', 0700);
        symlink($outside . '/ignore-target', $project . '/.phycode/.gitignore');

        Transcript::open($project . '/.phycode/sessions');

        self::assertFileDoesNotExist($outside . '/ignore-target');
    }

    public function testWithoutAUiTheRefusalGoesToThePhpErrorLog(): void
    {
        $project = Kit::folder('transcript-log');
        symlink(Kit::folder('transcript-outside'), $project . '/.phycode');
        $log = Kit::folder('transcript-log-file') . '/error.log';

        ini_set('error_log', $log);
        try {
            Transcript::open($project . '/.phycode/sessions');
        } finally {
            ini_restore('error_log');
        }

        self::assertSame(1, substr_count((string) file_get_contents($log), 'transcripts are not written'));
    }

    public function testAPhycodeLinkInsideTheProjectIsRefusedToo(): void
    {
        $project = Kit::folder('transcript-inside');
        Kit::write($project . '/data/readme.txt', "x\n");
        symlink($project . '/data', $project . '/.phycode');

        $ui = new RecordingUi();
        $transcript = Transcript::open($project . '/.phycode/sessions', $ui);

        self::assertSame('memory', $transcript->path, 'the real path of .phycode must be the .phycode of the project');
        self::assertFileDoesNotExist($project . '/data/.gitignore');
        self::assertCount(1, $this->notices($ui));
        self::assertStringStartsWith('transcripts are not written: .phycode is a symbolic link to ', $this->notices($ui)[0]);
    }

    public function testAPhycodeLinkToGitIsRefusedAndGitIsNotWritten(): void
    {
        $project = Kit::folder('transcript-git');
        Kit::write($project . '/.git/config', "[core]\n");
        symlink($project . '/.git', $project . '/.phycode');

        $ui = new RecordingUi();
        $transcript = Transcript::open($project . '/.phycode/sessions', $ui);
        $transcript->event('tool_call', ['tool' => 'read'], 'build', 0);

        self::assertSame('memory', $transcript->path);
        self::assertSame(['config'], array_values(array_diff(scandir($project . '/.git') ?: [], ['.', '..'])), 'nothing is written into .git');
        self::assertCount(1, $this->notices($ui));
    }

    public function testASessionsLinkToGitIsRefusedAndGitIsNotWritten(): void
    {
        $project = Kit::folder('transcript-sessions-git');
        Kit::write($project . '/.git/config', "[core]\n");
        mkdir($project . '/.phycode', 0700);
        symlink($project . '/.git', $project . '/.phycode/sessions');

        $ui = new RecordingUi();
        $transcript = Transcript::open($project . '/.phycode/sessions', $ui);

        self::assertSame('memory', $transcript->path);
        self::assertSame(['config'], array_values(array_diff(scandir($project . '/.git') ?: [], ['.', '..'])), 'nothing is written into .git');
        self::assertCount(1, $this->notices($ui));
        self::assertStringContainsString('.phycode/sessions is a symbolic link', $this->notices($ui)[0]);
    }

    private function notices(RecordingUi $ui): array
    {
        return array_values(array_map(
            static fn (array $event): string => (string) $event[2],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'notice'),
        ));
    }
}
