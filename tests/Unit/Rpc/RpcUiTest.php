<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Diff\TextDiff;
use Phycode\Rpc\JsonLines;
use Phycode\Rpc\RpcUi;
use Phycode\Security\ConfirmRequest;
use Phycode\Security\Decision;
use Phycode\Support\Cancellation;
use Phycode\Tools\ToolCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RpcUiTest extends TestCase
{
    private $output;
    private $terminal;
    private JsonLines $channel;
    private RpcUi $ui;
    private int $cancellations = 0;

    protected function tearDown(): void
    {
        Cancellation::setPoll(null);
    }

    public static function replies(): iterable
    {
        yield 'once' => ['once', Decision::AllowOnce];
        yield 'session' => ['session', Decision::AllowSession];
        yield 'deny' => ['deny', Decision::Deny];
        yield 'a value that is not a decision' => ['maybe', Decision::Deny];
    }

    public function testEveryEventIsOneLineWithItsFields(): void
    {
        $this->connect();

        $this->ui->text("first\nsecond");
        $this->ui->output("a\nb");
        $this->ui->notice('warn', 'careful');
        $this->ui->notice('info', 'fyi');

        self::assertSame([
            ['type' => 'text', 'agent' => 'build', 'chunk' => "first\nsecond"],
            ['type' => 'output', 'text' => "a\nb"],
            ['type' => 'notice', 'level' => 'warn', 'message' => 'careful'],
            ['type' => 'notice', 'level' => 'info', 'message' => 'fyi'],
        ], $this->sent());
        rewind($this->output);
        $raw = (string) stream_get_contents($this->output);
        self::assertSame(5, substr_count($raw, "\n"), 'the ready event plus four events, each on its own line');
        self::assertStringNotContainsString("first\nsecond", $raw);
    }

    public function testATurnIsFramedByTurnStartAndTurnEnd(): void
    {
        $this->connect();

        $this->ui->beginTurn();
        $this->ui->text('hi');
        $this->ui->endTurn('completed', null, ['src/a.php'], self::usage(), 'main');

        self::assertSame([
            ['type' => 'turn_start'],
            ['type' => 'text', 'agent' => 'build', 'chunk' => 'hi'],
            [
                'type' => 'turn_end', 'status' => 'completed', 'reason' => null, 'changes' => ['src/a.php'],
                'usage' => self::usage(), 'branch' => 'main',
            ],
        ], $this->sent());
    }

    public function testATextEventNamesTheCurrentPrimaryAgent(): void
    {
        $this->connect();
        $this->ui->attach(static fn (): string => 'plan', static fn (): null => null);

        $this->ui->text('x');

        self::assertSame([['type' => 'text', 'agent' => 'plan', 'chunk' => 'x']], $this->sent());
    }

    public function testAToolEndPairsWithTheNewestOpenCallOfTheSameNameAtItsDepth(): void
    {
        $this->connect();

        $this->ui->toolStart('build', 0, 'read', 'a.php');
        $this->ui->toolStart('build', 0, 'read', 'b.php');
        $this->ui->toolEnd('build', 0, 'read', true, 'second');
        $this->ui->toolEnd('build', 0, 'read', false, 'first');

        self::assertSame([
            ['type' => 'tool_start', 'id' => 't1', 'agent' => 'build', 'depth' => 0, 'tool' => 'read', 'summary' => 'a.php'],
            ['type' => 'tool_start', 'id' => 't2', 'agent' => 'build', 'depth' => 0, 'tool' => 'read', 'summary' => 'b.php'],
            ['type' => 'tool_end', 'id' => 't2', 'ok' => true, 'summary' => 'second', 'diff' => null],
            ['type' => 'tool_end', 'id' => 't1', 'ok' => false, 'summary' => 'first', 'diff' => null],
        ], $this->sent());
    }

    public function testToolCallsOfASubagentPairWithinTheirOwnDepth(): void
    {
        $this->connect();

        $this->ui->toolStart('build', 0, 'task', 'explore the config');
        $this->ui->toolStart('explore', 1, 'grep', 'config');
        $this->ui->toolStart('build', 0, 'grep', 'other');
        $this->ui->toolEnd('explore', 1, 'grep', true, 'three matches');
        $this->ui->toolEnd('build', 0, 'grep', true, 'none');
        $this->ui->toolEnd('build', 0, 'task', true, 'report');

        self::assertSame([
            ['type' => 'tool_start', 'id' => 't1', 'agent' => 'build', 'depth' => 0, 'tool' => 'task', 'summary' => 'explore the config'],
            ['type' => 'tool_start', 'id' => 't2', 'agent' => 'explore', 'depth' => 1, 'tool' => 'grep', 'summary' => 'config'],
            ['type' => 'tool_start', 'id' => 't3', 'agent' => 'build', 'depth' => 0, 'tool' => 'grep', 'summary' => 'other'],
            ['type' => 'tool_end', 'id' => 't2', 'ok' => true, 'summary' => 'three matches', 'diff' => null],
            ['type' => 'tool_end', 'id' => 't3', 'ok' => true, 'summary' => 'none', 'diff' => null],
            ['type' => 'tool_end', 'id' => 't1', 'ok' => true, 'summary' => 'report', 'diff' => null],
        ], $this->sent());
    }

    public function testNestedSubagentsPairTheirEndsAndTheirEventsNameTheParent(): void
    {
        $this->connect();

        $this->ui->subagentStart('build', 'explore', 'find the config');
        $this->ui->subagentStart('explore', 'general', 'write the notes');
        $this->ui->notice('info', 'inner');
        $this->ui->subagentEnd('explore', 'general', 'completed', '2 turns, 1 tool call');
        $this->ui->notice('info', 'outer');
        $this->ui->subagentEnd('build', 'explore', 'budget', 'turn budget reached');
        $this->ui->notice('info', 'after');

        self::assertSame([
            ['type' => 'subagent_start', 'id' => 's1', 'parent' => 'build', 'agent' => 'explore', 'task' => 'find the config'],
            ['type' => 'subagent_start', 'id' => 's2', 'parent' => 'explore', 'agent' => 'general', 'task' => 'write the notes'],
            ['type' => 'notice', 'level' => 'info', 'message' => 'inner', 'parent' => 'explore'],
            ['type' => 'subagent_end', 'id' => 's2', 'agent' => 'general', 'status' => 'completed', 'summary' => '2 turns, 1 tool call'],
            ['type' => 'notice', 'level' => 'info', 'message' => 'outer', 'parent' => 'build'],
            ['type' => 'subagent_end', 'id' => 's1', 'agent' => 'explore', 'status' => 'budget', 'summary' => 'turn budget reached'],
            ['type' => 'notice', 'level' => 'info', 'message' => 'after'],
        ], $this->sent());
    }

    public function testOnlyEventsInsideASubagentNameItsParent(): void
    {
        $this->connect();

        $this->ui->toolStart('build', 0, 'task', 'explore the config');
        $this->ui->subagentStart('build', 'explore', 'find the config');
        $this->ui->toolStart('explore', 1, 'grep', 'config');
        $this->ui->toolEnd('explore', 1, 'grep', true, 'three matches');
        $this->ui->subagentEnd('build', 'explore', 'completed', 'done');
        $this->ui->toolEnd('build', 0, 'task', true, 'report');
        $this->ui->notice('info', 'back');

        self::assertSame([
            ['type' => 'tool_start', 'id' => 't1', 'agent' => 'build', 'depth' => 0, 'tool' => 'task', 'summary' => 'explore the config'],
            ['type' => 'subagent_start', 'id' => 's1', 'parent' => 'build', 'agent' => 'explore', 'task' => 'find the config'],
            ['type' => 'tool_start', 'id' => 't2', 'agent' => 'explore', 'depth' => 1, 'tool' => 'grep', 'summary' => 'config', 'parent' => 'build'],
            ['type' => 'tool_end', 'id' => 't2', 'ok' => true, 'summary' => 'three matches', 'diff' => null, 'parent' => 'build'],
            ['type' => 'subagent_end', 'id' => 's1', 'agent' => 'explore', 'status' => 'completed', 'summary' => 'done'],
            ['type' => 'tool_end', 'id' => 't1', 'ok' => true, 'summary' => 'report', 'diff' => null],
            ['type' => 'notice', 'level' => 'info', 'message' => 'back'],
        ], $this->sent());
    }

    public function testToolAndSubagentIdsAreNotReused(): void
    {
        $this->connect();

        $this->ui->toolStart('build', 0, 'read', 'a');
        $this->ui->toolEnd('build', 0, 'read', true, 'a');
        $this->ui->subagentStart('build', 'explore', 'task one');
        $this->ui->subagentEnd('build', 'explore', 'completed', 'done');
        $this->ui->toolStart('build', 0, 'read', 'b');
        $this->ui->toolEnd('build', 0, 'read', true, 'b');

        $ids = array_column($this->sent(), 'id');
        self::assertSame(['t1', 't1', 's1', 's1', 't2', 't2'], $ids);
    }

    public function testAToolEndWithoutAStartStillGetsAnId(): void
    {
        $this->connect();

        $this->ui->toolEnd('build', 0, 'read', false, 'denied');

        self::assertSame([['type' => 'tool_end', 'id' => 't1', 'ok' => false, 'summary' => 'denied', 'diff' => null]], $this->sent());
    }

    public function testLongSummariesAreClippedToOneLine(): void
    {
        $this->connect();

        $this->ui->toolStart('build', 0, 'bash', str_repeat("word\n", 100));

        $events = $this->sent();
        self::assertSame(200, mb_strlen((string) $events[0]['summary']));
        self::assertStringNotContainsString("\n", (string) $events[0]['summary']);
    }

    #[DataProvider('replies')]
    public function testConfirmationWaitsForTheReplyWithItsId(string $reply, Decision $expected): void
    {
        $this->connect();
        $this->command('confirm_reply', ['id' => 'c1', 'decision' => $reply]);
        $this->ui->beginTurn();

        self::assertSame($expected, $this->ui->confirm($this->request('write', 'notes.txt (4 bytes)')));
        self::assertSame([
            ['type' => 'turn_start'],
            ['type' => 'confirm', 'id' => 'c1', 'tool' => 'write', 'category' => 'write', 'agent' => 'build', 'depth' => 0, 'summary' => 'notes.txt (4 bytes)', 'preview' => null],
        ], $this->sent());
        self::assertSame(0, $this->cancellations);
    }

    public function testEachConfirmationGetsItsOwnId(): void
    {
        $this->connect();
        $this->command('confirm_reply', ['id' => 'c1', 'decision' => 'once']);
        $this->command('confirm_reply', ['id' => 'c2', 'decision' => 'deny']);
        $this->ui->beginTurn();

        self::assertSame(Decision::AllowOnce, $this->ui->confirm($this->request('write', 'x')));
        self::assertSame(Decision::Deny, $this->ui->confirm($this->request('write', 'y')));
        self::assertSame(['c1', 'c2'], array_column(array_values(array_filter($this->sent(), static fn (array $event): bool => ($event['type'] ?? '') === 'confirm')), 'id'));
    }

    public function testRepliesWithUnknownIdsAreIgnored(): void
    {
        $this->connect();
        $this->command('confirm_reply', ['id' => 'c9', 'decision' => 'session']);
        $this->command('confirm_reply', ['id' => 'c0', 'decision' => 'session']);
        $this->command('confirm_reply', ['id' => 'c1', 'decision' => 'once']);
        $this->ui->beginTurn();

        self::assertSame(Decision::AllowOnce, $this->ui->confirm($this->request('write', 'a')));
        self::assertSame(0, $this->cancellations);
    }

    public function testInterruptWhileWaitingDeniesAndCancelsTheTurn(): void
    {
        $this->connect();
        $this->command('interrupt');
        $this->ui->beginTurn();

        self::assertSame(Decision::Deny, $this->ui->confirm($this->request('bash', 'rm -rf build')));
        self::assertSame(1, $this->cancellations);
        self::assertFalse($this->ui->quitRequested());
    }

    public function testEndOfInputWhileWaitingDeniesAndCancelsTheTurn(): void
    {
        $this->connect();
        $this->ui->beginTurn();
        fclose($this->terminal);

        self::assertSame(Decision::Deny, $this->ui->confirm($this->request('bash', 'ls')));
        self::assertSame(1, $this->cancellations);
    }

    public function testQuitWhileWaitingDeniesCancelsAndRemembersTheQuit(): void
    {
        $this->connect();
        $this->command('quit');
        $this->ui->beginTurn();

        self::assertSame(Decision::Deny, $this->ui->confirm($this->request('bash', 'ls')));
        self::assertSame(1, $this->cancellations);
        self::assertTrue($this->ui->quitRequested());
    }

    public function testAConfirmationAfterCancellationIsDeniedWithoutAsking(): void
    {
        $this->connect();
        $this->ui->beginTurn();
        $this->ui->interrupt();

        self::assertSame(Decision::Deny, $this->ui->confirm($this->request('write', 'a')));
        self::assertSame([['type' => 'turn_start']], $this->sent());
    }

    public function testInputDuringATurnIsRefusedAndNotQueued(): void
    {
        $this->connect();
        $this->command('input', ['text' => 'second question']);

        $this->ui->beginTurn();
        $this->ui->text('answer');
        $this->ui->endTurn('completed', null, [], self::usage(), null);

        self::assertSame([
            ['type' => 'turn_start'],
            ['type' => 'notice', 'level' => 'warn', 'message' => 'a turn is already running'],
            ['type' => 'text', 'agent' => 'build', 'chunk' => 'answer'],
            ['type' => 'turn_end', 'status' => 'completed', 'reason' => null, 'changes' => [], 'usage' => self::usage(), 'branch' => null],
        ], $this->sent());
        self::assertNull($this->channel->next(0.0), 'the refused line is consumed, not kept for later');
        self::assertSame(0, $this->cancellations);
    }

    public function testInterruptDuringATurnCancelsIt(): void
    {
        $this->connect();
        $this->command('interrupt');

        $this->ui->beginTurn();
        $this->ui->output('partial');

        self::assertSame(1, $this->cancellations);
        self::assertSame([['type' => 'turn_start'], ['type' => 'output', 'text' => 'partial']], $this->sent());
    }

    public function testAnInterruptIsReadWhileTheTurnWaitsWithoutAnyEvent(): void
    {
        $this->connect();
        $cancel = new Cancellation();
        $this->ui->attach(static fn (): string => 'build', static fn () => $cancel->cancel('interrupted by the user'));
        $this->command('interrupt');

        $this->ui->beginTurn();

        self::assertTrue($cancel->isCancelled());
        self::assertSame([['type' => 'turn_start']], $this->sent());
    }

    public function testEndOfInputDuringATurnCancelsIt(): void
    {
        $this->connect();
        fclose($this->terminal);

        $this->ui->beginTurn();
        $this->ui->notice('info', 'working');

        self::assertSame(1, $this->cancellations);
        self::assertFalse($this->ui->quitRequested());
    }

    public function testQuitDuringATurnIsRememberedUntilTheTurnEnds(): void
    {
        $this->connect();
        $this->command('quit');

        $this->ui->beginTurn();
        $this->ui->notice('info', 'working');

        self::assertTrue($this->ui->quitRequested());
        self::assertSame(1, $this->cancellations);
    }

    public function testCommandsAreNotReadOutsideATurn(): void
    {
        $this->connect();
        $this->command('interrupt');
        $this->command('input', ['text' => 'later']);

        $this->ui->output('help text');

        self::assertSame(0, $this->cancellations);
        self::assertSame([['type' => 'output', 'text' => 'help text']], $this->sent());
        self::assertSame(['type' => 'interrupt'], $this->channel->next(0.0));
    }

    public function testModelCommandsReadDuringATurnAreRefusedWithoutEffect(): void
    {
        $this->connect();
        $this->command('list_models', ['refresh' => true]);
        $this->command('set_model', ['profile' => 'local', 'model' => 'other', 'thinking' => 'on', 'save' => true]);
        $this->command('favorite', ['profile' => 'local', 'model' => 'other', 'on' => true]);

        $this->ui->beginTurn();
        $this->ui->text('answer');
        $this->ui->endTurn('completed', null, [], self::usage(), null);

        $refusal = ['type' => 'notice', 'level' => 'warn', 'message' => 'a turn is running; try again when it finishes'];
        self::assertSame([
            ['type' => 'turn_start'],
            $refusal,
            $refusal,
            $refusal,
            ['type' => 'text', 'agent' => 'build', 'chunk' => 'answer'],
            ['type' => 'turn_end', 'status' => 'completed', 'reason' => null, 'changes' => [], 'usage' => self::usage(), 'branch' => null],
        ], $this->sent());
        self::assertSame(0, $this->cancellations);
    }

    public function testNoticesRaisedBeforeTheReadyEventAreSentAfterIt(): void
    {
        $output = fopen('php://temp', 'r+');
        $ui = new RpcUi(new JsonLines(fopen('php://temp', 'r+'), $output));

        $ui->notice('warn', 'profile test cannot call tools');
        rewind($output);
        self::assertSame('', stream_get_contents($output));

        $ui->start(['type' => 'ready', 'protocol' => 1]);
        rewind($output);
        self::assertSame(
            [
                ['type' => 'ready', 'protocol' => 1],
                ['type' => 'notice', 'level' => 'warn', 'message' => 'profile test cannot call tools'],
            ],
            array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) stream_get_contents($output))))),
        );
    }

    public function testApprovalsAreAnsweredByTheUi(): void
    {
        $this->connect();

        self::assertTrue($this->ui->interactive());
    }

    public function testAPanelIsOneEventOfKindTextOrList(): void
    {
        $this->connect();
        $item = ['category' => 'Project', 'title' => 'go-testing', 'detail' => 'Go tests.', 'status' => '', 'path' => '/p/go-testing/SKILL.md'];

        $this->ui->panel('Status', "version  0.1.0\nprofile  local");
        $this->ui->listPanel('Skills', [$item], '1 folder skipped');

        self::assertSame([
            ['type' => 'panel', 'title' => 'Status', 'kind' => 'text', 'text' => "version  0.1.0\nprofile  local"],
            ['type' => 'panel', 'title' => 'Skills', 'kind' => 'list', 'items' => [$item], 'hint' => '1 folder skipped'],
        ], $this->sent());
    }

    public function testAToolEndCarriesTheDiffOfTheChange(): void
    {
        $this->connect();
        $diff = TextDiff::build('notes.txt', "one\n", "two\n");

        $this->ui->toolStart('build', 0, 'write', 'write notes.txt');
        $this->ui->toolEnd('build', 0, 'write', true, 'created notes.txt', $diff);

        $end = $this->sent()[1];
        self::assertSame($diff, $end['diff']);
        self::assertSame([1, 1], [$end['diff']['added'], $end['diff']['removed']]);
    }

    public function testAConfirmationCarriesThePreviewOfTheChange(): void
    {
        $this->connect();
        $this->command('confirm_reply', ['id' => 'c1', 'decision' => 'once']);
        $this->ui->beginTurn();
        $preview = TextDiff::build('a.txt', "x\n", "y\n");

        self::assertSame(Decision::AllowOnce, $this->ui->confirm(new ConfirmRequest('edit', ToolCategory::Write, 'build', 0, 'edit a.txt', $preview)));

        $confirm = $this->sent()[1];
        self::assertSame('confirm', $confirm['type']);
        self::assertSame($preview, $confirm['preview']);
    }

    private function connect(): void
    {
        [$reader, $this->terminal] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->output = fopen('php://temp', 'r+');
        $this->channel = new JsonLines($reader, $this->output);
        $this->ui = new RpcUi($this->channel);
        $this->ui->attach(static fn (): string => 'build', function (): void {
            $this->cancellations++;
        });
        $this->ui->start(['type' => 'ready', 'protocol' => 1]);
    }

    private function command(string $type, array $fields = []): void
    {
        fwrite($this->terminal, json_encode(['type' => $type] + $fields, JSON_THROW_ON_ERROR) . "\n");
    }

    private function sent(): array
    {
        rewind($this->output);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($this->output)), static fn (string $line): bool => $line !== ''));
        $events = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
        self::assertSame('ready', array_shift($events)['type'] ?? null);

        return $events;
    }

    private static function usage(): array
    {
        return ['promptTokens' => 26, 'completionTokens' => 12, 'tokensPerSecond' => 8.5, 'contextWindow' => 4096];
    }

    private function request(string $tool, string $summary): ConfirmRequest
    {
        return new ConfirmRequest($tool, $tool === 'bash' ? ToolCategory::Exec : ToolCategory::Write, 'build', 0, $summary);
    }
}
