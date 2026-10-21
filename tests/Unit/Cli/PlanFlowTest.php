<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Session\Transcript;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class PlanFlowTest extends TestCase
{
    private const PLAN = "1. Edit src/a.php to add the flag.\n2. Run the tests.";

    private SessionHarness $harness;
    private RecordingUi $ui;
    private \Phycode\Cli\Kernel $kernel;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'native']]]);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    private function session(CountingProvider $provider): ChatSession
    {
        $this->kernel = $this->harness->kernel($provider, $this->ui);

        return $this->harness->session($this->kernel);
    }

    private function notices(): array
    {
        return array_values(array_map(
            static fn (array $event): string => $event[2],
            array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice'),
        ));
    }

    public function testThePlanCommandSwitchesToThePlanAgentAndRunsTheTask(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text(self::PLAN)]);
        $session = $this->session($provider);
        $this->ui->answers = [null];

        $session->handle('/plan add a flag to a.php');

        self::assertSame('plan', $session->primary()->name);
        self::assertContains('primary agent is now plan: it investigates and proposes a plan, and changes nothing until you approve it', $this->notices());
        $names = array_map(static fn (array $tool): string => $tool['function']['name'] ?? $tool['name'] ?? '', $provider->sent[0]['tools']);
        self::assertNotContains('write', $names, 'the plan agent cannot change files');
        self::assertNotContains('bash', $names);
        self::assertStringContainsString('stand alone', (string) $provider->sent[0]['messages'][0]['content'], 'the plan prompt says that another agent builds it');
        self::assertSame('add a flag to a.php', $provider->sent[0]['messages'][1]['content']);
    }

    public function testApprovingWithAcceptedEditsSwitchesToBuildAndChangesThePermissionMode(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text(self::PLAN), ScriptedClient::text('built it')]);
        $session = $this->session($provider);
        $this->ui->answers = ['Yes, build it and accept the edits'];

        $session->handle('/plan add a flag');

        $ask = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'ask'))[0];
        self::assertSame('The plan is ready. Build it now?', $ask[1]);
        self::assertSame(['Yes, build it and accept the edits', 'Yes, build it and ask me about each edit', 'No, keep planning'], $ask[2]);
        self::assertSame('build', $session->primary()->name);
        self::assertSame('accept_edits', $this->kernel->permissionMode);
        $names = array_map(static fn (array $tool): string => $tool['function']['name'] ?? $tool['name'] ?? '', $provider->sent[1]['tools']);
        self::assertContains('write', $names, 'the build agent can change files');
        self::assertContains('todo', $names);
        $last = $provider->sent[1]['messages'][array_key_last($provider->sent[1]['messages'])];
        self::assertStringStartsWith('The plan above was approved. Write its steps with the todo tool', $last['content']);
        self::assertContains(self::PLAN, array_column($provider->sent[1]['messages'], 'content'), 'the plan is in the conversation the builder sees');
        self::assertSame('completed', $session->lastStatus());
    }

    public function testApprovingToAskAboutEachEditKeepsThePermissionMode(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text(self::PLAN), ScriptedClient::text('built it')]);
        $session = $this->session($provider);
        $this->ui->answers = ['Yes, build it and ask me about each edit'];

        $session->handle('/plan add a flag');

        self::assertSame('build', $session->primary()->name);
        self::assertSame('ask', $this->kernel->permissionMode);
    }

    public function testKeepPlanningLeavesThePlanAgentAndNothingElseRuns(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text(self::PLAN)]);
        $session = $this->session($provider);
        $this->ui->answers = ['No, keep planning'];

        $session->handle('/plan add a flag');

        self::assertSame('plan', $session->primary()->name);
        self::assertCount(1, $provider->sent);
        self::assertSame('ask', $this->kernel->permissionMode);
    }

    public function testWordsInsteadOfAnAnswerAreFeedbackAndThePlanIsMadeAgain(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text(self::PLAN), ScriptedClient::text("1. Edit src/a.php.\n2. Add a test.\n3. Run the tests."), ScriptedClient::text('built')]);
        $session = $this->session($provider);
        $this->ui->answers = ['also add a test', 'Yes, build it and accept the edits'];

        $session->handle('/plan add a flag');

        self::assertSame('also add a test', $provider->sent[1]['messages'][array_key_last($provider->sent[1]['messages'])]['content']);
        self::assertSame('build', $session->primary()->name);
        self::assertCount(3, $provider->sent);
        self::assertCount(2, array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'ask'), 'asked again after the new plan');
    }

    public function testNothingIsAskedWhenTheTurnFailedOrNobodyCanAnswer(): void
    {
        $failing = new CountingProvider(['local' => 'qwen'], ['native'], replies: [new \Phycode\Llm\LlmException('server down')]);
        $session = $this->session($failing);
        $session->handle('/plan add a flag');
        self::assertSame([], array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'ask'));

        $this->ui->terminal = false;
        $quiet = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text(self::PLAN)]);
        $session = $this->session($quiet);
        $session->handle('/plan add a flag');
        self::assertSame([], array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'ask'));
        self::assertSame('plan', $session->primary()->name);
    }

    public function testAnOrdinaryTurnOfTheBuildAgentAsksNothing(): void
    {
        $session = $this->session(new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text('hello')]));

        $session->handle('hello');

        self::assertSame([], array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'ask'));
    }

    public function testTheTodoCommandShowsAndClearsTheListAndTheListIsSavedWithTheSession(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [
            ScriptedClient::call('todo', ['todos' => [['content' => 'Read the code', 'status' => 'completed'], ['content' => 'Fix the bug', 'status' => 'in_progress']]]),
            ScriptedClient::text('on it'),
        ]);
        $session = $this->session($provider);

        $session->handle('fix the bug');
        $session->handle('/todo');

        $panels = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'panel' && $event[1] === 'Todo'));
        self::assertSame("1. [x] Read the code\n2. [>] Fix the bug\n\n1 of 2 done", $panels[0][2]);
        $saved = json_decode((string) file_get_contents($this->harness->cwd . '/.phycode/sessions/' . $session->listSessions()[0]['id'] . '.json'), true);
        self::assertSame([['content' => 'Read the code', 'status' => 'completed'], ['content' => 'Fix the bug', 'status' => 'in_progress']], $saved['todos']);

        $session->handle('/todo clear');
        $session->handle('/todo');
        $panels = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'panel' && $event[1] === 'Todo'));
        self::assertStringStartsWith('No tasks yet.', $panels[1][2]);
        self::assertContains('the task list is empty', $this->notices());
    }

    public function testASavedListComesBackWithItsSessionAndANewSessionStartsEmpty(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [
            ScriptedClient::call('todo', ['todos' => [['content' => 'Step', 'status' => 'pending']]]),
            ScriptedClient::text('ok'),
        ]);
        $session = $this->session($provider);
        $session->handle('plan the work');
        $first = $session->listSessions()[0]['id'];

        $session->newSession();
        $session->handle('/todo');
        $empty = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'panel' && $event[1] === 'Todo'));
        self::assertStringStartsWith('No tasks yet.', $empty[0][2]);

        $session->switchSession($first);
        $session->handle('/todo');
        $back = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'panel' && $event[1] === 'Todo'));
        self::assertSame("1. [ ] Step\n\n0 of 1 done", $back[1][2]);
    }

    public function testTheCommandsAreListed(): void
    {
        $session = $this->session(new CountingProvider(['local' => 'qwen']));

        $names = array_column($session->commands(), 'name');

        self::assertContains('/plan [TASK]', $names);
        self::assertContains('/todo [clear]', $names);
        self::assertTrue($session->isCommand('/plan'));
        self::assertTrue($session->isCommand('/plan do something'));
        self::assertTrue($session->isCommand('/todo clear'));
    }
}
