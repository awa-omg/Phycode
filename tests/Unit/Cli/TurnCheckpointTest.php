<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Checkpoint\CheckpointStore;
use Phycode\Security\Decision;
use Phycode\Security\Workspace;
use Phycode\Tests\Support\FakeModel;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RpcProcess;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TurnCheckpointTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
        ],
    ];

    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
        $this->ui = new RecordingUi();
        Kit::write($this->harness->cwd . '/src/a.txt', "A0\n");
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testAWriteInATurnIsKeptByThatTurnAndCanBeDiffedUndoneRedoneAndRewound(): void
    {
        $this->ui->decisions = [Decision::AllowOnce, Decision::AllowOnce, Decision::AllowOnce, Decision::AllowOnce];
        $kernel = $this->harness->kernel(new ToolSpy('native', [
            ScriptedClient::call('write', ['path' => 'src/a.txt', 'content' => "A1\n"]),
            ScriptedClient::text('done'),
        ]), $this->ui);
        $session = $this->harness->session($kernel);

        $session->handle('change a');

        $turns = $kernel->checkpoints->turns();
        self::assertCount(1, $turns);
        self::assertSame([['path' => 'src/a.txt', 'before' => "A0\n", 'after' => "A1\n"]], $kernel->checkpoints->turnContents($turns[0]['id']));

        $session->handle('/diff');
        self::assertStringContainsString("src/a.txt\n@@ -1,1 +1,1 @@\n-A0\n+A1", $this->panels('Diff')[0]);

        $session->handle('/undo');
        self::assertSame("A0\n", $this->content('src/a.txt'));

        $session->handle('/redo');
        self::assertSame("A1\n", $this->content('src/a.txt'));

        $session->handle('/rewind 1');
        self::assertSame("A0\n", $this->content('src/a.txt'));
        self::assertContains('info: rewound to before turn 1: 1 turn undone', $this->notices());
    }

    public function testATurnThatCannotOpenItsCheckpointRefusesItsFileChanges(): void
    {
        Kit::write($this->harness->cwd . '/.phycode/checkpoints/index.json', 'not an index');
        $this->ui->decisions = [Decision::AllowOnce];
        $spy = new ToolSpy('native', [
            ScriptedClient::call('write', ['path' => 'src/a.txt', 'content' => "A1\n"]),
            ScriptedClient::text('done'),
        ]);
        $session = $this->harness->session($this->harness->kernel($spy, $this->ui));

        $session->handle('change a');

        self::assertSame('completed', $session->lastStatus());
        self::assertSame("A0\n", $this->content('src/a.txt'));
        $results = array_values(array_filter($spy->sent[1]['messages'], static fn (array $message): bool => ($message['role'] ?? null) === 'tool'));
        self::assertStringContainsString('nothing was written', $results[0]['content']);
        self::assertStringStartsWith('warn: no checkpoint for this turn, so its file changes are refused', $this->notices()[0]);
    }

    #[DataProvider('runCommands')]
    public function testARunKeepsItsWriteInATurnThatUndoCanTakeBack(array $command): void
    {
        $call = ['name' => 'write', 'arguments' => ['path' => 'src/a.txt', 'content' => "A1\n"]];
        $model = new FakeModel([
            ['content' => '<tool_call>' . json_encode($call, JSON_THROW_ON_ERROR) . '</tool_call>'],
            ['content' => 'saved'],
        ]);
        $home = Kit::folder('run-home');
        try {
            RpcProcess::configure($home, $model->url(), 'text');
            $status = $this->phycode($home, [...$command, '--cwd', $this->harness->cwd, '--yolo', 'change a.txt']);
        } finally {
            $model->stop();
        }

        self::assertSame(0, $status, (string) file_get_contents($home . '/stderr.txt'));
        self::assertSame("A1\n", $this->content('src/a.txt'));
        $store = new CheckpointStore(new Workspace($this->harness->cwd));
        $turns = $store->turns();
        self::assertCount(1, $turns);
        self::assertSame(['src/a.txt'], $store->changedFiles($turns[0]['id']));
        $store->undo($turns[0]['id']);
        self::assertSame("A0\n", $this->content('src/a.txt'));
    }

    public static function runCommands(): iterable
    {
        yield 'run' => [['run']];
        yield 'print mode' => [['-p']];
    }

    private function phycode(string $home, array $arguments): int
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/phycode', ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $home . '/stdout.txt', 'w'], 2 => ['file', $home . '/stderr.txt', 'w']],
            $pipes,
            $this->harness->cwd,
            ['PATH' => (string) getenv('PATH'), 'HOME' => $home, 'XDG_CONFIG_HOME' => $home . '/config', 'XDG_CACHE_HOME' => $home . '/cache'],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start phycode');
        }

        return proc_close($process);
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
