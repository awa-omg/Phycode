<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Checkpoint\CheckpointStore;
use Phycode\Cli\ChatSession;
use Phycode\Security\Decision;
use Phycode\Security\Workspace;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class CheckpointCommandsTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
        ],
    ];

    private SessionHarness $harness;
    private RecordingUi $ui;
    private ChatSession $session;
    private CheckpointStore $store;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
        $this->ui = new RecordingUi();
        $this->session = $this->harness->session($this->harness->kernel(new RequestSpy(['local' => 'qwen']), $this->ui));
        $this->store = new CheckpointStore(new Workspace($this->harness->cwd));
        $this->put('src/a.txt', "A0\n");
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testDiffWithoutAChangedTurnSaysSo(): void
    {
        self::assertTrue($this->session->handle('/diff'));

        self::assertSame(['info: there is no turn with changes to show'], $this->notices());
        self::assertSame([], $this->panels('Diff'));
    }

    public function testDiffIgnoresATurnThatChangedNoFile(): void
    {
        $this->store->begin('turn-1');

        $this->session->handle('/diff');

        self::assertSame(['info: there is no turn with changes to show'], $this->notices());
    }

    public function testDiffShowsTheDiffOfTheLastAppliedTurnOnly(): void
    {
        $this->twoTurns();

        $this->session->handle('/diff');

        $diff = $this->panels('Diff')[0];
        self::assertStringStartsWith('turn 2: 1 file', $diff);
        self::assertStringContainsString("src/a.txt\n@@ -1,1 +1,1 @@\n-A1\n+A2", $diff);
        self::assertStringNotContainsString('A0', $diff);
    }

    public function testDiffNamesABinaryFileAndAFileWhoseContentDidNotChange(): void
    {
        $this->put('src/image.bin', "\0\1binary\n");
        $this->store->begin('turn-1');
        $this->store->record('src/image.bin', "\0\1binary\n");
        $this->put('src/image.bin', "\0\2changed\n");
        $this->store->record('src/a.txt', "A0\n");

        $this->session->handle('/diff');

        $diff = $this->panels('Diff')[0];
        self::assertStringContainsString('src/image.bin: binary file, not shown', $diff);
        self::assertStringContainsString('src/a.txt: no change in content', $diff);
    }

    public function testUndoListsTheFilesFirstAndPutsThemBackOnAYes(): void
    {
        $this->twoTurns();
        $this->ui->decisions = [Decision::AllowOnce];

        $this->session->handle('/undo');

        self::assertSame('src/a.txt: restored', $this->panels('Undo')[0]);
        $types = array_column($this->ui->events, 0);
        self::assertLessThan(array_search('confirm', $types, true), array_search('panel', $types, true), 'the files are listed before the question');
        self::assertSame("A1\n", $this->content('src/a.txt'));
        self::assertSame("N1\n", $this->content('src/new.txt'), 'turn 1 is not touched by undoing turn 2');
        self::assertSame(['info: undid turn 2'], $this->notices());
    }

    public function testUndoTakesBackTheLastTurnWithChangesWhenALaterTurnChangedNoFile(): void
    {
        $this->twoTurns();
        $this->store->begin('turn-3');
        $this->ui->decisions = [Decision::AllowOnce];

        $this->session->handle('/undo');

        self::assertSame("A1\n", $this->content('src/a.txt'));
        self::assertSame(['info: undid turn 2'], $this->notices());
    }

    public function testUndoChangesNothingWithoutAYes(): void
    {
        $this->twoTurns();
        $this->ui->decisions = [Decision::Deny];

        $this->session->handle('/undo');

        self::assertSame("A2\n", $this->content('src/a.txt'));
        self::assertSame(['info: nothing was changed'], $this->notices());
    }

    public function testUndoChangesNothingWithoutATerminal(): void
    {
        $this->twoTurns();
        $this->ui->terminal = false;
        $this->ui->decisions = [Decision::AllowOnce];

        $this->session->handle('/undo');

        self::assertSame("A2\n", $this->content('src/a.txt'));
        self::assertSame([], $this->ui->requests, 'nobody can answer, so the question is not asked');
        self::assertSame(['info: nothing was changed'], $this->notices());
    }

    public function testUndoAndRedoSayWhenThereIsNoTurnToTakeBack(): void
    {
        $this->session->handle('/undo');
        $this->session->handle('/redo');

        self::assertSame(['info: there is no turn to undo', 'info: there is no turn to redo'], $this->notices());
    }

    public function testRedoAppliesAgainTheTurnThatUndoPutBack(): void
    {
        $this->twoTurns();
        $this->ui->decisions = [Decision::AllowOnce, Decision::AllowOnce];

        $this->session->handle('/undo');
        $this->session->handle('/redo');

        self::assertSame("A2\n", $this->content('src/a.txt'));
        self::assertSame('src/a.txt: restored', $this->panels('Redo')[0], 'the redo lists the file it writes again');

        $this->session->handle('/redo');
        self::assertSame(['info: undid turn 2', 'info: redid turn 2', 'info: there is no turn to redo'], $this->notices());
    }

    public function testRewindWithoutANumberListsTheTurns(): void
    {
        $this->session->handle('/rewind');
        $this->twoTurns();

        $this->session->handle('/rewind');

        self::assertSame(
            ['there are no turns yet', "usage: /rewind TURN, with a number from this list\n1  applied  src/a.txt, src/new.txt\n2  applied  src/a.txt"],
            $this->panels('Turns'),
        );
    }

    public function testRewindRejectsATurnThatDoesNotExistAndAWordThatIsNotANumber(): void
    {
        $this->session->handle('/rewind 1');
        $this->twoTurns();

        $this->session->handle('/rewind 3');
        $this->session->handle('/rewind two');
        $this->session->handle('/rewind 0');

        self::assertSame([
            'error: there is no turn to rewind to',
            'error: there is no turn 3; the turns are 1 to 2',
            'error: usage: /rewind TURN, with the number of a turn from /rewind',
            'error: there is no turn 0; the turns are 1 to 2',
        ], $this->notices());
    }

    public function testRewindPutsBackTheFilesBeforeATurnAndTheLaterOnes(): void
    {
        $this->twoTurns();
        $this->ui->decisions = [Decision::AllowOnce];

        $this->session->handle('/rewind 1');

        self::assertSame('rewind to before turn 1, which undoes 2 turns', $this->ui->requests[0]->summary);
        self::assertSame("src/a.txt: restored\nsrc/new.txt: removed", $this->panels('Rewind')[0]);
        self::assertSame("A0\n", $this->content('src/a.txt'));
        self::assertNull($this->content('src/new.txt'));
        self::assertSame(
            [['id' => 'turn-1', 'undone' => true], ['id' => 'turn-2', 'undone' => true]],
            $this->store->turns(),
        );
        self::assertSame(['info: rewound to before turn 1: 2 turns undone'], $this->notices());
    }

    public function testRewindToATurnThatIsUndoneChangesNothing(): void
    {
        $this->twoTurns();
        $this->ui->decisions = [Decision::AllowOnce];
        $this->session->handle('/undo');

        $this->session->handle('/rewind 2');

        self::assertCount(1, $this->ui->requests, 'no question is asked for a turn that is undone');
        self::assertSame("A1\n", $this->content('src/a.txt'));
        self::assertSame(['info: undid turn 2', 'info: turn 2 is already undone, so there is nothing to rewind'], $this->notices());
    }

    public function testAFileChangeIsRefusedWhileATurnRuns(): void
    {
        $this->twoTurns();
        $session = null;
        $spy = new RequestSpy(['local' => 'qwen'], replies: [
            static function () use (&$session): void {
                $session?->handle('/undo');
            },
            ScriptedClient::text('done'),
        ]);
        $session = $this->harness->session($this->harness->kernel($spy, $this->ui));

        $session->handle('a question');

        self::assertContains('error: a turn is running; try again when it finishes', $this->notices());
        self::assertSame("A2\n", $this->content('src/a.txt'));
        self::assertSame([], $this->ui->requests);
    }

    private function twoTurns(): void
    {
        $this->store->begin('turn-1');
        $this->store->record('src/a.txt', "A0\n");
        $this->put('src/a.txt', "A1\n");
        $this->store->record('src/new.txt', null);
        $this->put('src/new.txt', "N1\n");

        $this->store->begin('turn-2');
        $this->store->record('src/a.txt', "A1\n");
        $this->put('src/a.txt', "A2\n");
    }

    private function put(string $relative, string $content): void
    {
        Kit::write($this->harness->cwd . '/' . $relative, $content);
    }

    private function content(string $relative): ?string
    {
        $file = $this->harness->cwd . '/' . $relative;

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    private function panels(string $title): array
    {
        $texts = [];
        foreach ($this->ui->events as $event) {
            if ($event[0] === 'panel' && $event[1] === $title) {
                $texts[] = $event[2];
            }
        }

        return $texts;
    }

    private function notices(): array
    {
        $notices = [];
        foreach ($this->ui->events as $event) {
            if ($event[0] === 'notice') {
                $notices[] = $event[1] . ': ' . $event[2];
            }
        }

        return $notices;
    }
}
