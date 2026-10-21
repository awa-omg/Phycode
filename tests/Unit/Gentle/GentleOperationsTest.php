<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Gentle;

use Phycode\Exec\ProcessRunner;
use Phycode\Gentle\GentleAi;
use Phycode\Gentle\GentleOperation;
use Phycode\Gentle\GentleOperations;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GentleOperationsTest extends TestCase
{
    private const BIN = '/opt/gentle/bin/gentle-ai';
    private const TWO_SWITCHES = ['GENTLE_AI_NO_SELF_UPDATE' => '1', 'GENTLE_AI_TELEMETRY' => '0'];

    private string|false $home = false;

    protected function setUp(): void
    {
        $this->home = getenv('GENTLE_AI_HOME');
        putenv('GENTLE_AI_HOME');
    }

    protected function tearDown(): void
    {
        putenv($this->home === false ? 'GENTLE_AI_HOME' : "GENTLE_AI_HOME={$this->home}");
    }

    public function testInstallPassesTheWordsAfterTheBinaryAndTheSwitchesAsTheEnvironment(): void
    {
        $operation = $this->build('install', ['--agent', 'claude-code', '--preset', 'minimal']);

        self::assertSame([self::BIN, 'install', '--agent', 'claude-code', '--preset', 'minimal'], $operation->argv);
        self::assertSame(self::TWO_SWITCHES, $operation->env);
        self::assertSame('/work', $operation->cwd);
        self::assertSame('gentle-ai install', $operation->title);
    }

    public function testSyncPassesItsFlagsAsTyped(): void
    {
        self::assertSame([self::BIN, 'sync', '--agent', 'opencode', '--dry-run'], $this->build('sync', ['--agent', 'opencode', '--dry-run'])->argv);
    }

    public function testUpgradeTakesNoWordsOfItsOwnAndSaysWhatItMayReplace(): void
    {
        $operation = $this->build('upgrade', []);

        self::assertSame([self::BIN, 'upgrade'], $operation->argv);
        self::assertStringContainsString('gentle-ai binary', implode("\n", $operation->writes));
        self::assertStringContainsString('no signature check', implode("\n", $operation->writes));
        self::assertContains('GENTLE_AI_NO_SELF_UPDATE does not stop upgrade', $operation->notes);
    }

    public function testUninstallNeedsAScopeBeforeAnythingIsShown(): void
    {
        foreach ([[], ['--component', 'engram'], ['--agent-name', 'x']] as $words) {
            try {
                $this->build('uninstall', $words);
                self::fail('uninstall without an agent or --all must be refused: ' . implode(' ', $words));
            } catch (PhycodeException $error) {
                self::assertStringContainsString('--agent NAME', $error->getMessage());
            }
        }
    }

    public static function uninstallScopes(): iterable
    {
        yield 'one agent' => [['--agent', 'claude-code'], ['--agent', 'claude-code', '--yes']];
        yield 'two agents as a list' => [['--agents=claude-code,opencode'], ['--agents=claude-code,opencode', '--yes']];
        yield 'all, with its own yes' => [['--all', '--yes'], ['--all', '--yes']];
        yield 'all, with the short yes' => [['--all', '-y'], ['--all', '-y']];
    }

    #[DataProvider('uninstallScopes')]
    public function testUninstallAddsOneYesSoThatTheApprovalIsTheOnlyQuestion(array $words, array $expected): void
    {
        self::assertSame([self::BIN, 'uninstall', ...$expected], $this->build('uninstall', $words)->argv);
    }

    public function testTheYesThatUninstallAddsIsSaidInTheNotes(): void
    {
        self::assertContains(
            '--yes is added, so that gentle-ai does not ask a second time: this approval is the one question',
            $this->build('uninstall', ['--all'])->notes,
        );
    }

    public function testRefreshRunsInTheProjectFolderWithItsOwnCwdFlag(): void
    {
        $operation = $this->build('refresh', []);

        self::assertSame([self::BIN, 'skill-registry', 'refresh', '--cwd', '/work'], $operation->argv);
        self::assertSame('/work', $operation->cwd);
        self::assertContains('/work/.atl/skill-registry.md and /work/.atl/.skill-registry.cache.json', $operation->writes);
        self::assertContains('/work/.gitignore gets .atl/ appended, unless it has it already', $operation->writes);
    }

    public function testRefreshWithNoGitignoreSaysThatTheGitignoreStaysAsItIs(): void
    {
        $operation = $this->build('refresh', ['--no-gitignore', '--force']);

        self::assertSame([self::BIN, 'skill-registry', 'refresh', '--cwd', '/work', '--no-gitignore', '--force'], $operation->argv);
        self::assertContains('.gitignore is not changed (--no-gitignore)', $operation->writes);
    }

    public function testRefreshTakesOnlyItsOwnFlags(): void
    {
        foreach ([['--cwd', '/'], ['/'], ['--quiet']] as $words) {
            try {
                $this->build('refresh', $words);
                self::fail('refresh must refuse ' . implode(' ', $words));
            } catch (PhycodeException $error) {
                self::assertStringContainsString('refresh takes --force and --no-gitignore', $error->getMessage());
            }
        }
    }

    public function testTheTuiTakesNoWordsAndIsTheBinaryAlone(): void
    {
        self::assertSame([self::BIN], $this->build('tui', [])->argv);
        $this->expectException(PhycodeException::class);
        $this->build('tui', ['--agent', 'x']);
    }

    public static function actionsWithTheirWords(): iterable
    {
        yield 'install' => ['install', ['--agent', 'codex']];
        yield 'sync' => ['sync', ['--dry-run']];
        yield 'upgrade' => ['upgrade', []];
        yield 'uninstall' => ['uninstall', ['--all']];
        yield 'refresh' => ['refresh', ['--force']];
        yield 'tui' => ['tui', []];
    }

    #[DataProvider('actionsWithTheirWords')]
    public function testEveryActionCarriesTheSwitchesOfEveryCallAndNothingElse(string $action, array $words): void
    {
        $operation = GentleOperations::build($action, self::BIN, '/work', $words, GentleAi::environment(), null);

        self::assertSame(self::BIN, $operation->argv[0]);
        self::assertSame(self::TWO_SWITCHES, $operation->env);
    }

    #[DataProvider('actionsWithTheirWords')]
    public function testOnlyTheUpgradeApprovalSaysThatTheSwitchDoesNotStopIt(string $action, array $words): void
    {
        $details = GentleOperations::build($action, self::BIN, '/work', $words, GentleAi::environment(), null)->details();

        if ($action === 'upgrade') {
            self::assertStringContainsString("\nnote: GENTLE_AI_NO_SELF_UPDATE does not stop upgrade", $details);
        } else {
            self::assertStringNotContainsString('does not stop upgrade', $details);
        }
    }

    public function testAnActionOutsideTheListIsRefused(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('no gentle-ai action named doctor');

        $this->build('doctor', []);
    }

    public function testTheExecEventRunsTheTuiThroughEnvWithTheSameSwitches(): void
    {
        $event = GentleOperations::execEvent($this->build('tui', []));

        self::assertSame([
            'type' => 'exec',
            'title' => 'gentle-ai (TUI)',
            'argv' => ['env', 'GENTLE_AI_NO_SELF_UPDATE=1', 'GENTLE_AI_TELEMETRY=0', self::BIN],
            'confirm' => true,
        ], $event);
    }

    public function testTheApprovalShowsTheExactArgvEnvironmentAndFolder(): void
    {
        $details = $this->build('install', ['--agent', 'claude code'])->details();

        self::assertSame(
            "argv: /opt/gentle/bin/gentle-ai install --agent 'claude code'\n"
            . "env:  GENTLE_AI_NO_SELF_UPDATE=1 GENTLE_AI_TELEMETRY=0\n"
            . "cwd:  /work\n"
            . "writes: agent config of the chosen agents and components, and state.json\n"
            . "writes: a backup of the files it replaces\n"
            . 'note: gentle-ai may run engram setup and the installers of the selected components; phycode runs none of them itself',
            $details,
        );
    }

    public function testAnOldVersionIsANoteAndTheCommandStillBuilds(): void
    {
        $operation = GentleOperations::build('sync', self::BIN, '/work', [], self::TWO_SWITCHES, 'the pin is v4.2.0; this binary reports 4.0.0');

        self::assertSame([self::BIN, 'sync'], $operation->argv);
        self::assertContains('the pin is v4.2.0; this binary reports 4.0.0. The command still runs; this is only a warning.', $operation->notes);
    }

    public function testTheRunGivesTheBinaryTheExactArgvEnvironmentAndFolder(): void
    {
        $bin = FakeGentleAi::install(['other' => 'synced']);
        $project = Kit::folder('gentle-run');
        $operation = GentleOperations::build('sync', $bin, $project, ['--agent', 'opencode', '--dry-run'], GentleAi::environment(), null);

        $result = GentleOperations::run($operation, new ProcessRunner());

        self::assertTrue($result->succeeded());
        self::assertSame("synced\n", $result->stdout);
        self::assertSame([['sync', '--agent', 'opencode', '--dry-run']], FakeGentleAi::argv($bin));
        self::assertSame(["GENTLE_AI_NO_SELF_UPDATE=1 GENTLE_AI_TELEMETRY=0 GENTLE_AI_HOME=unset CWD={$project}"], FakeGentleAi::environment($bin));
    }

    public function testTheRunOfUninstallCarriesTheYesThatWasAdded(): void
    {
        $bin = FakeGentleAi::install(['other' => 'removed']);
        $operation = GentleOperations::build('uninstall', $bin, Kit::folder('gentle-run'), ['--agent', 'codex'], GentleAi::environment(), null);

        GentleOperations::run($operation, new ProcessRunner());

        self::assertSame([['uninstall', '--agent', 'codex', '--yes']], FakeGentleAi::argv($bin));
    }

    private function build(string $action, array $words): GentleOperation
    {
        return GentleOperations::build($action, self::BIN, '/work', $words, self::TWO_SWITCHES, null);
    }
}
