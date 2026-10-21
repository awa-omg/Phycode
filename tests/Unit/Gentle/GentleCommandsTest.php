<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Gentle;

use Phycode\Cli\ChatSession;
use Phycode\Cli\Kernel;
use Phycode\Gentle\GentleAi;
use Phycode\Gentle\Persona;
use Phycode\Llm\ClientProvider;
use Phycode\Rpc\JsonLines;
use Phycode\Rpc\RpcUi;
use Phycode\Security\Decision;
use Phycode\Session\Transcript;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Ui\Ui;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GentleCommandsTest extends TestCase
{
    private const SKILLS = '[{"name":"go-testing","scope":"user","description":"Go tests.","path":"/p/go-testing/SKILL.md"},{"name":"api","scope":"project","description":"REST APIs","path":"/p/api/SKILL.md"}]';
    private const TWO_SWITCHES = 'GENTLE_AI_NO_SELF_UPDATE=1 GENTLE_AI_TELEMETRY=0';

    private array $environment = [];
    private string $home;
    private string $project;
    private string $configFile;

    protected function setUp(): void
    {
        foreach (['PATH', 'HOME', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'GENTLE_AI_HOME', 'SHELL', 'ZDOTDIR', 'PHYCODE_BASE_URL', 'PHYCODE_MODEL', 'PHYCODE_PROFILE'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        $this->home = Kit::folder('gentle-home');
        $configDir = Kit::folder('gentle-config');
        $this->configFile = $configDir . '/phycode/config.json';
        $this->project = Kit::folder('gentle-project');
        putenv('HOME=' . $this->home);
        putenv('XDG_CONFIG_HOME=' . $configDir);
        putenv('XDG_CACHE_HOME=' . Kit::folder('gentle-cache'));
        putenv('SHELL=/bin/zsh');
        putenv('ZDOTDIR');
        foreach (['GENTLE_AI_HOME', 'PHYCODE_BASE_URL', 'PHYCODE_MODEL', 'PHYCODE_PROFILE'] as $name) {
            putenv($name);
        }
        $this->writeConfig([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testTheCommandsAreRegisteredAtTheirWords(): void
    {
        $kernel = new Kernel($this->project, false, new RecordingUi(), null);
        $names = array_column($kernel->commands->commands(), 'name');

        foreach (['/gentle [status|install|sync|upgrade|uninstall|refresh|tui]', '/mcp preset [engram|context7|codegraph]', '/persona [gentleman|neutral|off]', '/shell [status|install|uninstall]', '/odd FEATURE'] as $name) {
            self::assertContains($name, $names);
        }
        self::assertContains('/gentle', $this->words(new ChatSession($kernel, 'local', Kit::agent(), Transcript::memory())));
    }

    public function testGentleShowsThePanelAndAsksNothing(): void
    {
        $bin = $this->fake(['skills' => self::SKILLS]);
        $ui = new RecordingUi();
        $session = $this->session($ui);
        $ui->events = [];

        self::assertTrue($session->handle('/gentle'));

        $panel = $this->events($ui, 'panel')[0];
        self::assertSame('gentle', $panel[1]);
        self::assertStringStartsWith(
            "phycode review.mode: enabled (review.mode is not set in {$this->configFile}, so the default applies)\n"
            . "phycode persona: off (gentle_ai.persona is not set in {$this->configFile})\n"
            . "gentle-ai: v4.2.0 at {$bin} (pinned v4.2.0)\n",
            (string) $panel[2],
        );
        self::assertStringContainsString('skills: 2 (from skill-registry list)', (string) $panel[2]);
        self::assertSame([], $this->events($ui, 'confirm'));
        self::assertSame([['skill-registry', 'list', '--json', '--cwd', $this->project], ['version'], ['skill-registry', 'list', '--json', '--cwd', $this->project]], FakeGentleAi::argv($bin));
    }

    public function testPhycodesOwnSwitchesComeFirstAndGentleAisAreLabelledApart(): void
    {
        $bin = FakeGentleAi::install(['skills' => self::SKILLS]);
        $this->writeConfig(['review' => ['mode' => 'disabled'], 'gentle_ai' => ['bin' => $bin, 'persona' => 'gentleman']]);
        Kit::write($this->home . '/.gentle-ai/state.json', '{"installed_agents":["claude-code"],"persona":"neutral","rdd_mode":"on"}');
        $ui = new RecordingUi();
        $session = $this->session($ui);
        $ui->events = [];

        $session->handle('/gentle');

        $panel = (string) $this->events($ui, 'panel')[0][2];
        self::assertStringStartsWith(
            "phycode review.mode: disabled (review.mode in {$this->configFile})\n"
            . "phycode persona: gentleman (gentle_ai.persona in {$this->configFile})\n"
            . "gentle-ai: v4.2.0 at {$bin} (pinned v4.2.0)\n",
            $panel,
        );
        self::assertStringContainsString("state: ok at {$this->home}/.gentle-ai/state.json (1 agent)\n", $panel);
        self::assertStringContainsString("gentle-ai rdd_mode: on (from state.json; /review does not use it)\n", $panel);
        self::assertStringContainsString("gentle-ai persona: neutral (from state.json; /persona does not change it)\n", $panel);
    }

    public function testAnInvalidReviewModeIsSaidAndDoesNotStopTheStatus(): void
    {
        $bin = FakeGentleAi::install(['skills' => self::SKILLS]);
        $this->writeConfig(['review' => ['mode' => 'maybe'], 'gentle_ai' => ['bin' => $bin]]);
        $ui = new RecordingUi();
        $session = $this->session($ui);
        $ui->events = [];

        $session->handle('/gentle');

        self::assertStringStartsWith("phycode review.mode: review.mode must be enabled or disabled in {$this->configFile}\n", (string) $this->events($ui, 'panel')[0][2]);
        self::assertStringContainsString("gentle-ai: v4.2.0 at {$bin} (pinned v4.2.0)\n", (string) $this->events($ui, 'panel')[0][2]);
    }

    public function testOnlyTheUpgradeApprovalSaysThatTheSwitchDoesNotStopIt(): void
    {
        $this->fake(['other' => 'done']);
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny, Decision::Deny];
        $session = $this->session($ui);
        $ui->events = [];

        $session->handle('/gentle upgrade');
        $session->handle('/gentle install --agent claude-code');

        $approvals = $this->events($ui, 'panel');
        self::assertSame('gentle-ai upgrade', $approvals[0][1]);
        self::assertStringContainsString("\nnote: GENTLE_AI_NO_SELF_UPDATE does not stop upgrade", (string) $approvals[0][2]);
        self::assertSame('gentle-ai install', $approvals[1][1]);
        self::assertStringNotContainsString('does not stop upgrade', (string) $approvals[1][2]);
    }

    public function testAnOlderGentleAiIsWarnedAboutAndTheCommandsStillAsk(): void
    {
        $bin = $this->fake(['version' => 'gentle-ai 4.0.0', 'skills' => self::SKILLS]);
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];
        $session = $this->session($ui);

        $session->handle('/gentle');
        $session->handle('/gentle install');

        self::assertStringContainsString('warning: the pin is v4.2.0; this binary reports 4.0.0', (string) $this->events($ui, 'panel')[0][2]);
        self::assertCount(1, $ui->requests, 'the warning does not block the question');
        self::assertNotContains('install', $this->actions($bin));
    }

    public function testInstallRunsExactlyTheApprovedArgvAndEnvironment(): void
    {
        $bin = $this->fake(['other' => 'installed']);
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];
        $ui->events = [];
        $session = $this->session($ui);

        $session->handle('/gentle install --agent claude-code --preset minimal');

        self::assertCount(1, $ui->requests);
        self::assertSame('phycode', $ui->requests[0]->tool);
        self::assertStringContainsString('run gentle-ai install', $ui->requests[0]->summary);
        $approval = $this->events($ui, 'panel')[0];
        self::assertSame('gentle-ai install', $approval[1]);
        self::assertStringStartsWith(
            "argv: {$bin} install --agent claude-code --preset minimal\nenv:  " . self::TWO_SWITCHES . "\ncwd:  {$this->project}\n",
            (string) $approval[2],
        );
        self::assertSame(['install', '--agent', 'claude-code', '--preset', 'minimal'], $this->lastCall($bin));
        self::assertSame(self::TWO_SWITCHES . ' GENTLE_AI_HOME=unset CWD=' . $this->project, $this->lastEnvironment($bin));
        self::assertSame('gentle-ai install: finished', $this->events($ui, 'panel')[1][1]);
        self::assertStringContainsString('exit code 0', (string) $this->events($ui, 'panel')[1][2]);
    }

    public static function changesThatAskFirst(): iterable
    {
        yield 'install' => ['/gentle install --agent claude-code', ['install']];
        yield 'sync' => ['/gentle sync --agent opencode', ['sync']];
        yield 'upgrade' => ['/gentle upgrade', ['upgrade']];
        yield 'uninstall' => ['/gentle uninstall --all', ['uninstall']];
        yield 'refresh' => ['/gentle refresh', ['skill-registry', 'refresh']];
    }

    #[DataProvider('changesThatAskFirst')]
    public function testWithoutAYesNoGentleAiChangeRuns(string $line, array $forbidden): void
    {
        $bin = $this->fake(['other' => 'done']);
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];
        $session = $this->session($ui);

        $session->handle($line);

        self::assertCount(1, $ui->requests, 'the change asks before it runs');
        self::assertSame([], array_values(array_filter(
            FakeGentleAi::argv($bin),
            static fn (array $call): bool => array_slice($call, 0, count($forbidden)) === $forbidden,
        )));
        self::assertSame('not approved: nothing was run.', $this->notices($ui, 'info')[0]);
        self::assertFalse(is_dir($this->project . '/.atl'));
    }

    public function testUninstallWithoutItsScopeIsRefusedBeforeAnyQuestion(): void
    {
        $bin = $this->fake();
        $ui = new RecordingUi();
        $session = $this->session($ui);

        $session->handle('/gentle uninstall');

        self::assertSame([], $ui->requests);
        self::assertStringContainsString('uninstall needs its scope', $this->notices($ui, 'error')[0]);
        self::assertNotContains('uninstall', $this->actions($bin));
    }

    public function testRefreshRunsInTheProjectFolderWithTheFlagsGiven(): void
    {
        $bin = $this->fake(['skills' => self::SKILLS, 'other' => 'refreshed']);
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];
        $session = $this->session($ui);

        $session->handle('/gentle refresh --no-gitignore');

        self::assertSame(['skill-registry', 'refresh', '--cwd', $this->project, '--no-gitignore'], $this->lastCall($bin));
        self::assertStringContainsString('writes: .gitignore is not changed (--no-gitignore)', (string) $this->events($ui, 'panel')[0][2]);
    }

    public function testTheTuiOutsideTheTerminalFrontEndOnlyPrintsTheCommand(): void
    {
        $bin = $this->fake();
        $ui = new RecordingUi();
        $session = $this->session($ui);

        $session->handle('/gentle tui');

        self::assertSame([], $ui->requests);
        self::assertSame(
            'the gentle-ai TUI needs the phycode terminal UI, which hands over the terminal. In a terminal of your own, run: env GENTLE_AI_NO_SELF_UPDATE=1 GENTLE_AI_TELEMETRY=0 ' . $bin,
            $this->notices($ui, 'info')[0],
        );
        self::assertSame([['skill-registry', 'list', '--json', '--cwd', $this->project], ['version'], ['skill-registry', 'list', '--json', '--cwd', $this->project]], FakeGentleAi::argv($bin));
    }

    public function testTheTuiThroughTheTerminalFrontEndAsksThenSendsTheExecEvent(): void
    {
        $bin = $this->fake();
        [$ui, $terminal, $output] = $this->rpc();
        fwrite($terminal, json_encode(['type' => 'confirm_reply', 'id' => 'c1', 'decision' => 'once'], JSON_THROW_ON_ERROR) . "\n");

        $this->session($ui)->handle('/gentle tui');

        $events = $this->sent($output);
        $confirm = $this->typed($events, 'confirm');
        self::assertCount(1, $confirm);
        self::assertStringContainsString('open the gentle-ai TUI', (string) $confirm[0]['summary']);
        self::assertSame([[
            'type' => 'exec',
            'title' => 'gentle-ai (TUI)',
            'argv' => ['env', 'GENTLE_AI_NO_SELF_UPDATE=1', 'GENTLE_AI_TELEMETRY=0', $bin],
            'confirm' => true,
        ]], $this->typed($events, 'exec'));
    }

    public function testTheTuiThroughTheTerminalFrontEndSendsNothingWithoutAYes(): void
    {
        $this->fake();
        [$ui, $terminal, $output] = $this->rpc();
        fwrite($terminal, json_encode(['type' => 'confirm_reply', 'id' => 'c1', 'decision' => 'deny'], JSON_THROW_ON_ERROR) . "\n");

        $this->session($ui)->handle('/gentle tui');

        self::assertSame([], $this->typed($this->sent($output), 'exec'));
    }

    public function testMcpPresetsAreListed(): void
    {
        $ui = new RecordingUi();

        $this->session($ui)->handle('/mcp preset');

        $panel = $this->events($ui, 'panel')[0];
        self::assertSame('MCP presets (gentle-ai)', $panel[1]);
        self::assertStringContainsString('context7   npx -y --package=@upstash/context7-mcp@2.2.5 -- context7-mcp', (string) $panel[2]);
        self::assertStringContainsString('codegraph  codegraph serve --mcp', (string) $panel[2]);
    }

    public function testAnMcpPresetAddsItsEntryAndKeepsTheRest(): void
    {
        $this->writeConfig(['mcp_servers' => ['docs' => ['command' => ['/usr/bin/docs-mcp'], 'enabled' => true, 'approval' => 'ask']]]);
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];

        $this->session($ui)->handle('/mcp preset context7');

        $data = $this->readConfig();
        self::assertSame(['command' => ['npx', '-y', '--package=@upstash/context7-mcp@2.2.5', '--', 'context7-mcp'], 'enabled' => true, 'approval' => 'ask'], $data['mcp_servers']['context7']);
        self::assertSame(['command' => ['/usr/bin/docs-mcp'], 'enabled' => true, 'approval' => 'ask'], $data['mcp_servers']['docs']);
        self::assertSame('local', $data['default_profile']);
        self::assertStringContainsString('added mcp_servers.context7', $this->notices($ui, 'info')[0]);
    }

    public function testAnMcpPresetWithoutAYesChangesNothing(): void
    {
        $before = file_get_contents($this->configFile);
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];

        $this->session($ui)->handle('/mcp preset engram');

        self::assertSame($before, file_get_contents($this->configFile));
        self::assertFileDoesNotExist($this->configFile . '.bak');
    }

    public function testThePersonaIsShownAndSetAfterAYes(): void
    {
        $spy = new RequestSpy(['local' => 'test-model'], [], [ScriptedClient::text('Claro.'), ScriptedClient::text('Hecho.')], ['local' => 'none']);
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];
        $session = $this->session($ui, $spy);
        $ui->events = [];

        $session->handle('/persona');
        self::assertStringStartsWith('persona: off', (string) $this->events($ui, 'panel')[0][2]);

        $session->handle('explain the layout of this project');
        $session->handle('/persona gentleman');
        self::assertSame('gentleman', $this->readConfig()['gentle_ai']['persona']);
        self::assertStringContainsString('it applies from the next reply of this conversation', $this->notices($ui, 'info')[0]);

        $session->handle('and the tests?');
        self::assertStringEndsWith(Persona::promptBlock('gentleman'), (string) $spy->sent[1]['messages'][0]['content'], 'the next request carries the persona');

        $session->handle('/persona gentleman');
        self::assertCount(1, $ui->requests, 'the same choice asks nothing');
        self::assertStringContainsString('already gentleman', $this->notices($ui, 'info')[1]);

        $session->handle('/persona nobody');
        self::assertStringContainsString('the personas are gentleman, neutral or off', $this->notices($ui, 'error')[0]);
    }

    public function testAPersonaWithoutAYesChangesNothing(): void
    {
        $before = file_get_contents($this->configFile);
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];

        $this->session($ui)->handle('/persona neutral');

        self::assertSame($before, file_get_contents($this->configFile));
    }

    public function testShellStatusReadsTheRcFileAndChangesNothing(): void
    {
        $ui = new RecordingUi();

        $this->session($ui)->handle('/shell');

        $panel = $this->events($ui, 'panel')[0];
        self::assertSame('shell', $panel[1]);
        self::assertStringContainsString("file: {$this->home}/.zshrc", (string) $panel[2]);
        self::assertStringContainsString('block: not installed', (string) $panel[2]);
        self::assertFileDoesNotExist($this->home . '/.zshrc');
    }

    public function testShellInstallWithoutAYesWritesNothing(): void
    {
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];

        $this->session($ui)->handle('/shell install');

        self::assertCount(1, $ui->requests);
        self::assertFileDoesNotExist($this->home . '/.zshrc');
    }

    public function testShellInstallAddsOneBlockAndAsksAgainOnlyWhenItWouldChange(): void
    {
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];
        $session = $this->session($ui);

        $session->handle('/shell install');
        $session->handle('/shell install');

        self::assertCount(1, $ui->requests, 'a second install finds the block and asks nothing');
        self::assertSame(
            "# >>> phycode >>>\nexport PATH='" . dirname(__DIR__, 3) . "/bin':\"\$PATH\"\n# <<< phycode <<<\n",
            file_get_contents($this->home . '/.zshrc'),
        );
        self::assertStringContainsString('nothing changed', $this->notices($ui, 'info')[1]);
    }

    public function testShellUninstallRemovesTheBlockAfterAYes(): void
    {
        Kit::write($this->home . '/.zshrc', "alias ll='ls -l'\n");
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce, Decision::AllowOnce];
        $session = $this->session($ui);
        $session->handle('/shell install');

        $session->handle('/shell uninstall');

        self::assertSame("alias ll='ls -l'\n", file_get_contents($this->home . '/.zshrc'));
    }

    public function testAnOddTaskIsCreatedOnlyAfterAYes(): void
    {
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny, Decision::AllowOnce];
        $session = $this->session($ui);

        $session->handle('/odd add-login');
        self::assertFileDoesNotExist($this->project . '/odd/tasks/add-login.md');
        self::assertDirectoryDoesNotExist($this->project . '/odd');

        $session->handle('/odd add-login');
        self::assertFileExists($this->project . '/odd/tasks/add-login.md');
        self::assertStringStartsWith("# ODD Task: add-login\n", (string) file_get_contents($this->project . '/odd/tasks/add-login.md'));
    }

    public function testAnOddTaskNameThatIsNotASlugIsRefused(): void
    {
        $ui = new RecordingUi();

        $this->session($ui)->handle('/odd Add Login');

        self::assertSame([], $ui->requests);
        self::assertStringContainsString('lowercase letters, digits and dashes', $this->notices($ui, 'error')[0]);
    }

    public function testWithoutATerminalNothingIsAskedAndNothingChanges(): void
    {
        $ui = new RecordingUi();
        $ui->terminal = false;

        $this->session($ui)->handle('/odd quiet-task');

        self::assertSame([], $ui->requests);
        self::assertSame('not approved: nothing was changed.', $this->notices($ui, 'info')[0]);
        self::assertFileDoesNotExist($this->project . '/odd/tasks/quiet-task.md');
    }

    private function writeConfig(array $extra): void
    {
        Kit::write($this->configFile, json_encode([
            'default_profile' => 'local',
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9/v1', 'model' => 'test-model', 'tool_mode' => 'none']],
            ...$extra,
        ], JSON_THROW_ON_ERROR));
    }

    private function readConfig(): array
    {
        return json_decode((string) file_get_contents($this->configFile), true, 512, JSON_THROW_ON_ERROR);
    }

    private function fake(array $behaviour = []): string
    {
        $bin = FakeGentleAi::install($behaviour);
        $this->writeConfig(['gentle_ai' => ['bin' => $bin]]);

        return $bin;
    }

    private function session(Ui $ui, ?ClientProvider $providers = null): ChatSession
    {
        return new ChatSession(new Kernel($this->project, false, $ui, null, $providers), 'local', Kit::agent(), Transcript::memory());
    }

    private function rpc(): array
    {
        [$reader, $terminal] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $output = fopen('php://temp', 'r+');
        $ui = new RpcUi(new JsonLines($reader, $output));
        $ui->start(['type' => 'ready', 'protocol' => 1]);

        return [$ui, $terminal, $output];
    }

    private function sent($output): array
    {
        rewind($output);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($output)), static fn (string $line): bool => $line !== ''));
        $events = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
        array_shift($events);

        return $events;
    }

    private function typed(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (array $event): bool => ($event['type'] ?? null) === $type));
    }

    private function events(RecordingUi $ui, string $kind): array
    {
        return array_values(array_filter($ui->events, static fn (array $event): bool => $event[0] === $kind));
    }

    private function notices(RecordingUi $ui, string $level): array
    {
        return array_values(array_map(
            static fn (array $event): string => (string) $event[2],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'notice' && $event[1] === $level),
        ));
    }

    private function actions(string $bin): array
    {
        return array_map(static fn (array $call): string => $call[0] ?? '', FakeGentleAi::argv($bin));
    }

    private function lastCall(string $bin): array
    {
        $calls = FakeGentleAi::argv($bin);

        return $calls === [] ? [] : $calls[array_key_last($calls)];
    }

    private function lastEnvironment(string $bin): string
    {
        $lines = FakeGentleAi::environment($bin);

        return $lines[array_key_last($lines)];
    }

    private function words(ChatSession $session): array
    {
        return array_map(static fn (array $command): string => explode(' ', $command['name'], 2)[0], $session->commands());
    }
}
