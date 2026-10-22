<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Security\Decision;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class GoalSessionTest extends TestCase
{
    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'native']]]);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    private function session(array $replies): array
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: $replies);

        return [$this->harness->session($this->harness->kernel($provider, $this->ui)), $provider];
    }

    private function notices(): array
    {
        return array_values(array_map(static fn (array $event): string => $event[1] . ': ' . $event[2], array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice')));
    }

    public function testTheTurnsGoOnUntilTheCommandExitsWithZero(): void
    {
        $this->ui->decisions = [Decision::AllowOnce];
        [$session, $provider] = $this->session([
            ScriptedClient::text('I looked at it.'),
            ScriptedClient::call('write', ['path' => 'done.txt', 'content' => "x\n"]),
            ScriptedClient::text('Wrote done.txt.'),
        ]);

        $session->handle('/goal --check "test -f done.txt" done.txt exists');

        $notices = $this->notices();
        self::assertContains('info: goal not met after turn 1 of 8: test -f done.txt exits with 1', $notices);
        self::assertContains('info: goal met after 2 turns: test -f done.txt exits with 0', $notices);
        self::assertFileExists($this->harness->cwd . '/done.txt');
        self::assertCount(3, $provider->sent);
        $contents = array_column($provider->sent[1]['messages'], 'content');
        self::assertContains("Goal: done.txt exists\n\nWork on it until it is met. The goal counts as met when this command exits with 0: test -f done.txt. Run it yourself to check.", $contents);
        self::assertStringStartsWith('The goal is not met yet: test -f done.txt exits with 1', (string) end($contents));
    }

    public function testTheOutputOfAFailingCheckGoesBackToTheModel(): void
    {
        [$session, $provider] = $this->session([ScriptedClient::text('Done?'), ScriptedClient::text('Still looking.')]);

        $session->handle('/goal --check "echo boom >&2; exit 3" --max 2 it works');

        $contents = array_column($provider->sent[1]['messages'], 'content');
        self::assertStringContainsString("exits with 3:\nboom", (string) end($contents));
        self::assertContains('warn: goal not met after 2 turns; /goal with the same words, or --max with more turns, goes on', $this->notices());
        self::assertCount(2, $provider->sent);
    }

    public function testWithoutACommandTheModelJudgesTheReportOfEachTurn(): void
    {
        [$session, $provider] = $this->session([
            ScriptedClient::text('I think it is fine.'),
            ScriptedClient::text('{"met": false, "reason": "no test was run"}'),
            ScriptedClient::text('I ran phpunit: OK (12 tests).'),
            ScriptedClient::text('{"met": true, "reason": "phpunit passed"}'),
        ]);

        $session->handle('/goal the suite is green');

        self::assertContains('info: goal not met after turn 1 of 8: no test was run', $this->notices());
        self::assertContains('info: goal met after 2 turns: phpunit passed', $this->notices());
        self::assertCount(4, $provider->sent);
        self::assertStringContainsString('Goal: the suite is green', (string) $provider->sent[1]['messages'][1]['content']);
        self::assertStringContainsString('I think it is fine.', (string) $provider->sent[1]['messages'][1]['content']);
        self::assertSame([], $provider->sent[1]['tools'] ?? [], 'the judge has no tools');
        self::assertStringContainsString('The goal is not met yet: no test was run', (string) end($provider->sent[2]['messages'])['content']);
    }

    public function testAGoalThatIsMetInTheFirstTurnStopsThere(): void
    {
        [$session, $provider] = $this->session([ScriptedClient::text('Done.')]);

        $session->handle('/goal --check "true" nothing to do');

        self::assertContains('info: goal met after 1 turn: true exits with 0', $this->notices());
        self::assertCount(1, $provider->sent);
    }

    public function testADangerousCommandIsRefusedAndNoTurnRuns(): void
    {
        [$session, $provider] = $this->session([ScriptedClient::text('never')]);

        $session->handle('/goal --check "rm -rf /" clean up');
        $session->handle('/goal');

        self::assertSame([], $provider->sent);
        self::assertStringContainsString('is never run, in any mode', implode("\n", $this->notices()));
        self::assertContains('error: usage: /goal [--check COMMAND] [--max N] CONDITION, such as /goal --check "vendor/bin/phpunit" the tests pass', $this->notices());
    }

    public function testATurnThatEndsBadlyPausesTheGoalInsteadOfLoopingOn(): void
    {
        [$session, $provider] = $this->session([new \Phycode\Llm\LlmException('connection to the model server failed: refused')]);

        $session->handle('/goal --check "false" it works');

        self::assertCount(1, $provider->sent);
        self::assertStringContainsString('goal paused after turn 1', implode("\n", $this->notices()));
    }
}
