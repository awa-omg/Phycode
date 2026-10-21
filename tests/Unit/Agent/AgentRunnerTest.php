<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Agent;

use Phycode\Agent\AgentRunner;
use Phycode\Agent\Conversation;
use Phycode\Agent\Outcome;
use Phycode\Agent\OutcomeStatus;
use Phycode\Agent\RunPolicy;
use Phycode\Agent\RunSetup;
use Phycode\Config\Limits;
use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\FallbackClient;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;
use Phycode\Llm\OpenAiClient;
use Phycode\Llm\ToolCall;
use Phycode\Security\Decision;
use Phycode\Support\Cancellation;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\LlamaServer;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tools\BaseTool;
use Phycode\Tools\DeleteTool;
use Phycode\Tools\EditTool;
use Phycode\Tools\ListTool;
use Phycode\Tools\MoveTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolRegistry;
use Phycode\Tools\ToolResult;
use Phycode\Tools\WriteTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Kit::folder('runner');
        Kit::write($this->root . '/src/a.php', "one\ntwo\n");
    }

    protected function tearDown(): void
    {
        OpenAiClient::setLoadingWait(null, null);
    }

    private function runLoop(
        array $replies,
        string $mode = 'native',
        int $maxTurns = 10,
        ?RunPolicy $policy = null,
        ?RecordingUi $ui = null,
        ?Cancellation $cancel = null,
        ?Limits $limits = null,
        ?ToolRegistry $tools = null,
    ): array {
        $ui ??= new RecordingUi();
        $policy ??= RunPolicy::primary(Kit::agent(), 'yolo');
        $client = new ScriptedClient($replies);
        if ($tools === null) {
            $tools = new ToolRegistry();
            foreach ([new ReadTool(), new ListTool(), new WriteTool(), new EditTool(), new MoveTool(), new DeleteTool()] as $tool) {
                $tools->register($tool);
            }
        }
        $context = Kit::context($this->root, Kit::agent(), $policy, $ui, null, $cancel, $limits);
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('task');

        $outcome = (new AgentRunner())->run($conversation, new RunSetup($client, $mode, $tools, $context, $maxTurns, false));

        return [$outcome, $client, $ui];
    }

    private function taskTools(): ToolRegistry
    {
        $tools = new ToolRegistry();
        $tools->register(new class extends BaseTool {
            public function name(): string
            {
                return 'task';
            }

            public function description(): string
            {
                return 'Delegates a task (test double).';
            }

            public function category(): ToolCategory
            {
                return ToolCategory::Delegate;
            }

            public function parameters(): array
            {
                return ['type' => 'object', 'properties' => ['prompt' => ['type' => 'string']], 'required' => ['prompt']];
            }

            public function execute(array $args, ToolContext $context): ToolResult
            {
                return ToolResult::ok('report for ' . $args['prompt']);
            }
        });

        return $tools;
    }

    private function confirmations(RecordingUi $ui): array
    {
        return array_values(array_filter($ui->events, static fn (array $event): bool => $event[0] === 'confirm'));
    }

    public function testNativeToolLoopRunsTheToolAndCompletes(): void
    {
        [$outcome, $client, $ui] = $this->runLoop([
            ScriptedClient::call('read', ['path' => 'src/a.php']),
            ScriptedClient::text('The file has two lines.'),
        ]);

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertSame('The file has two lines.', $outcome->text);
        self::assertSame(2, $outcome->turns);
        self::assertSame(1, $outcome->toolCalls);
        self::assertSame(['read'], $ui->startedTools());
        $lastMessage = end($client->requests[1]['messages']);
        self::assertSame('tool', $lastMessage['role']);
        self::assertStringContainsString('two', $lastMessage['content']);
    }

    public function testUnknownToolIsReportedBackAndNotExecuted(): void
    {
        [$outcome, $client] = $this->runLoop([
            ScriptedClient::call('teleport', ['where' => 'home']),
            ScriptedClient::text('ok'),
        ]);

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        $toolMessage = $client->requests[1]['messages'][count($client->requests[1]['messages']) - 1];
        self::assertStringContainsString("tool 'teleport' is not available", $toolMessage['content']);
    }

    public function testRepeatedIdenticalCallIsRefusedThenTheRunStopsIfItPersists(): void
    {
        $same = ScriptedClient::call('read', ['path' => 'src/a.php']);
        [$outcome, $client] = $this->runLoop([$same, $same, $same, $same, $same, ScriptedClient::text('never reached')]);

        self::assertSame(OutcomeStatus::Stuck, $outcome->status);
        self::assertCount(5, $client->requests, 'the third repetition is refused with a message, the fifth ends the run');
        $refusal = $client->requests[3]['messages'][count($client->requests[3]['messages']) - 1]['content'];
        self::assertStringContainsString('change approach', $refusal);
    }

    public function testTurnBudgetEndsWithAFinalReportWithoutTools(): void
    {
        [$outcome, $client] = $this->runLoop([
            ScriptedClient::call('list', ['path' => '.']),
            ScriptedClient::call('read', ['path' => 'src/a.php']),
            ScriptedClient::text('Report: listed and read the file.'),
        ], maxTurns: 2);

        self::assertSame(OutcomeStatus::Budget, $outcome->status);
        self::assertSame('Report: listed and read the file.', $outcome->text);
        self::assertSame([], $client->requests[2]['tools'], 'the summary request is sent without tools');
    }

    public function testMalformedTextToolCallIsRetriedWithTheError(): void
    {
        [$outcome, $client] = $this->runLoop([
            ScriptedClient::text('<tool_call>{"name": "list", "arguments": {</tool_call>'),
            ScriptedClient::text('<tool_call>{"name": "list", "arguments": {}}</tool_call>'),
            ScriptedClient::text('Listed.'),
        ], mode: 'text');

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        $retryMessage = $client->requests[1]['messages'][count($client->requests[1]['messages']) - 1]['content'];
        self::assertStringContainsString('Tool call error', $retryMessage);
        self::assertSame(1, $outcome->toolCalls);
    }

    public function testNoneModeNeverActsOnToolCallsWrittenAsText(): void
    {
        [$outcome, $client] = $this->runLoop([
            ScriptedClient::text('<tool_call>{"name": "list", "arguments": {}}</tool_call>'),
        ], mode: 'none');

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertSame(0, $outcome->toolCalls);
        self::assertSame([], $client->requests[0]['tools']);
    }

    public function testTaskCallsAreCappedPerReply(): void
    {
        [$outcome, $client] = $this->runLoop([
            new LlmResponse('', '', [
                new ToolCall('a', 'task', '{"prompt":"one"}'),
                new ToolCall('b', 'task', '{"prompt":"two"}'),
                new ToolCall('c', 'task', '{"prompt":"three"}'),
                new ToolCall('d', 'task', '{"prompt":"four"}'),
            ], FinishReason::TOOL_CALLS, new Usage()),
            ScriptedClient::text('done'),
        ], tools: $this->taskTools());

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertSame(4, $outcome->toolCalls);
        $messages = $client->requests[1]['messages'];
        self::assertStringContainsString('report for three', $messages[count($messages) - 2]['content']);
        self::assertStringContainsString('too many task calls in one reply', $messages[count($messages) - 1]['content']);
    }

    public function testTaskCallsAreCappedPerRun(): void
    {
        [$outcome, $client] = $this->runLoop([
            new LlmResponse('', '', [new ToolCall('a', 'task', '{"prompt":"one"}')], FinishReason::TOOL_CALLS, new Usage()),
            new LlmResponse('', '', [new ToolCall('b', 'task', '{"prompt":"two"}')], FinishReason::TOOL_CALLS, new Usage()),
            new LlmResponse('', '', [new ToolCall('c', 'task', '{"prompt":"three"}')], FinishReason::TOOL_CALLS, new Usage()),
            ScriptedClient::text('done'),
        ], limits: new Limits(maxTasksPerRun: 2), tools: $this->taskTools());

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        $messages = $client->requests[3]['messages'];
        self::assertStringContainsString('already used its 2 subagent tasks', $messages[count($messages) - 1]['content']);
    }

    public function testASavedChangeStartsANewComparison(): void
    {
        $read = ScriptedClient::call('read', ['path' => 'src/a.php']);
        $write = ScriptedClient::call('write', ['path' => 'src/a.php', 'content' => "one\ntwo\nthree\n"]);
        [$outcome] = $this->runLoop([$read, $write, $read, $write, $read, ScriptedClient::text('done')]);

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertSame(5, $outcome->toolCalls);
    }

    public function testAlternatingRepeatsAreAlsoDetected(): void
    {
        $read = ScriptedClient::call('read', ['path' => 'src/a.php']);
        $list = ScriptedClient::call('list', ['path' => '.']);
        [$outcome] = $this->runLoop([$read, $list, $read, $list, $read, $list, $read, $list, $read, ScriptedClient::text('never reached')]);

        self::assertSame(OutcomeStatus::Stuck, $outcome->status);
    }

    public function testCancellationBeforeTheFirstRequestStopsTheRun(): void
    {
        $cancel = new Cancellation();
        $cancel->cancel('stopped by the user');

        [$outcome, $client] = $this->runLoop([ScriptedClient::text('unused')], cancel: $cancel);

        self::assertSame(OutcomeStatus::Cancelled, $outcome->status);
        self::assertSame('stopped by the user', $outcome->reason);
        self::assertSame([], $client->requests);
    }

    public function testRepeatedRefusalsStopTheRunInsteadOfRetryingForever(): void
    {
        $ui = new RecordingUi();
        $ui->terminal = false;
        $policy = RunPolicy::primary(Kit::agent(), 'ask');
        [$outcome, $client] = $this->runLoop([
            ScriptedClient::call('write', ['path' => 'a.txt', 'content' => '1']),
            ScriptedClient::call('write', ['path' => 'b.txt', 'content' => '2']),
            ScriptedClient::call('write', ['path' => 'c.txt', 'content' => '3']),
            ScriptedClient::call('write', ['path' => 'd.txt', 'content' => '4']),
            ScriptedClient::text('unreachable'),
        ], policy: $policy, ui: $ui);

        self::assertSame(OutcomeStatus::Stuck, $outcome->status);
        self::assertCount(3, $client->requests, 'the run stops after three refusals in a row');
        self::assertFileDoesNotExist($this->root . '/a.txt');
    }

    public static function outsideCalls(): iterable
    {
        yield 'write' => ['write', ['path' => '/tmp/opencode/outside/note.txt', 'content' => "x\n"]];
        yield 'edit' => ['edit', ['path' => '/tmp/opencode/outside/note.txt', 'old_string' => 'one', 'new_string' => 'uno']];
        yield 'delete' => ['delete', ['path' => '/tmp/opencode/outside/note.txt']];
        yield 'move to outside' => ['move', ['from' => 'src/a.php', 'to' => '/tmp/opencode/outside/note.txt']];
    }

    #[DataProvider('outsideCalls')]
    public function testAPathOutsideTheProjectFailsBeforeAnyApprovalIsAsked(string $tool, array $arguments): void
    {
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];

        [, $client] = $this->runLoop([
            ScriptedClient::call($tool, $arguments),
            ScriptedClient::text('done'),
        ], policy: RunPolicy::primary(Kit::agent(), 'ask'), ui: $ui);

        self::assertSame([], $this->confirmations($ui));
        $reported = $client->requests[1]['messages'][count($client->requests[1]['messages']) - 1]['content'];
        self::assertStringStartsWith('ERROR: path is outside the project', $reported);
        self::assertFileExists($this->root . '/src/a.php');
    }

    public function testAPathInsideTheProjectStillAsksBeforeTheChange(): void
    {
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];

        [$outcome] = $this->runLoop([
            ScriptedClient::call('write', ['path' => 'notes.txt', 'content' => "hi\n"]),
            ScriptedClient::text('done'),
        ], policy: RunPolicy::primary(Kit::agent(), 'ask'), ui: $ui);

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertSame([['confirm', 'write', 'build']], $this->confirmations($ui));
        self::assertFileExists($this->root . '/notes.txt');
    }

    public static function protectedCalls(): iterable
    {
        yield 'write into .git' => ['write', ['path' => '.git/config', 'content' => "x\n"], 'protected folder: .git/config'];
        yield 'edit inside .claude' => ['edit', ['path' => '.claude/agents/x.md', 'old_string' => 'a', 'new_string' => 'b'], 'protected folder: .claude/agents/x.md'];
        yield 'delete inside .phycode' => ['delete', ['path' => 'sub/.phycode/note.txt'], 'protected folder: sub/.phycode/note.txt'];
        yield 'move into .claude' => ['move', ['from' => 'src/a.php', 'to' => '.claude/a.php'], 'protected folder: .claude/a.php'];
        yield 'write through a link into .git' => ['write', ['path' => 'git-link/config', 'content' => "x\n"], 'protected folder: .git/config'];
        yield 'write through a link out of the project' => ['write', ['path' => 'out-link/new.txt', 'content' => "x\n"], 'path leaves the project through a symbolic link'];
    }

    #[DataProvider('protectedCalls')]
    public function testProtectedFoldersAndEscapingLinksFailBeforeAnyApprovalIsAsked(string $tool, array $arguments, string $reason): void
    {
        Kit::write($this->root . '/.git/config', "[core]\n");
        Kit::write($this->root . '/.claude/agents/x.md', "a\n");
        Kit::write($this->root . '/sub/.phycode/note.txt', "keep\n");
        symlink($this->root . '/.git', $this->root . '/git-link');
        symlink(Kit::folder('outside'), $this->root . '/out-link');

        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];

        [, $client] = $this->runLoop([
            ScriptedClient::call($tool, $arguments),
            ScriptedClient::text('done'),
        ], policy: RunPolicy::primary(Kit::agent(), 'ask'), ui: $ui);

        self::assertSame([], $this->confirmations($ui));
        $reported = $client->requests[1]['messages'][count($client->requests[1]['messages']) - 1]['content'];
        self::assertStringStartsWith('ERROR: ' . $reason, $reported);
        self::assertSame("[core]\n", file_get_contents($this->root . '/.git/config'));
        self::assertSame("keep\n", file_get_contents($this->root . '/sub/.phycode/note.txt'));
    }

    public function testToolCallsLeftInAReplyAfterACancellationAreNotRun(): void
    {
        $tools = new ToolRegistry();
        $tools->register(new class extends BaseTool {
            public function name(): string
            {
                return 'stop';
            }

            public function description(): string
            {
                return 'Cancels the turn (test double).';
            }

            public function category(): ToolCategory
            {
                return ToolCategory::Read;
            }

            public function parameters(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args, ToolContext $context): ToolResult
            {
                $context->cancel->cancel('interrupted by the user');

                return ToolResult::ok('stopped');
            }
        });
        $tools->register(new WriteTool());

        [$outcome, , $ui] = $this->runLoop([
            new LlmResponse('', '', [
                new ToolCall('a', 'stop', '{}'),
                new ToolCall('b', 'write', '{"path":"later.txt","content":"1"}'),
            ], FinishReason::TOOL_CALLS, new Usage()),
        ], cancel: new Cancellation(), tools: $tools);

        self::assertSame(OutcomeStatus::Cancelled, $outcome->status);
        self::assertSame(['stop'], $ui->startedTools());
        self::assertFileDoesNotExist($this->root . '/later.txt');
    }

    public function testFailingModelServerEndsTheRunWithTheError(): void
    {
        [$outcome] = $this->runLoop([new LlmException('connection to the model server failed: refused')]);

        self::assertSame(OutcomeStatus::Error, $outcome->status);
        self::assertStringContainsString('refused', (string) $outcome->reason);
    }

    public function testAToolFailingOutsideItsOwnErrorsClosesItsLineAndTheErrorGoesOn(): void
    {
        $tools = new ToolRegistry();
        $tools->register(new class extends BaseTool {
            public function name(): string
            {
                return 'boom';
            }

            public function description(): string
            {
                return 'Fails with a TypeError before it runs (test double).';
            }

            public function category(): ToolCategory
            {
                return ToolCategory::Read;
            }

            public function parameters(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function validate(array $args, ToolContext $context): void
            {
                throw new \TypeError('bad argument');
            }

            public function execute(array $args, ToolContext $context): ToolResult
            {
                return ToolResult::ok('never reached');
            }
        });
        $ui = new RecordingUi();

        try {
            $this->runLoop([ScriptedClient::call('boom', [])], ui: $ui, tools: $tools);
            self::fail('the TypeError should have gone on to the caller');
        } catch (\TypeError $error) {
            self::assertSame('bad argument', $error->getMessage());
        }

        self::assertSame([['tool_start', 'build', 'boom'], ['tool_end', 'boom', false]], $this->toolEvents($ui));
    }

    public function testACallThatCannotBeRecordedInTheTranscriptClosesItsLineAndTheErrorGoesOn(): void
    {
        $ui = new RecordingUi();
        $reply = new LlmResponse('', '', [new ToolCall('a', 'list', '{"path":".","depth":1e400}')], FinishReason::TOOL_CALLS, new Usage());

        try {
            $this->runLoop([$reply], ui: $ui);
            self::fail('a number that JSON cannot carry should stop the call, and the error goes on');
        } catch (\JsonException) {
            self::assertSame([['tool_start', 'build', 'list'], ['tool_end', 'list', false]], $this->toolEvents($ui));
        }
    }

    public function testAnErrorReasonEndsTheTurnWithAnErrorStatus(): void
    {
        [$outcome, $client] = $this->runLoop([
            new LlmResponse('partial', '', [], FinishReason::ERROR, new Usage()),
            ScriptedClient::text('never reached'),
        ]);

        self::assertSame(OutcomeStatus::Error, $outcome->status);
        self::assertSame('the reply ended with a finish reason this client does not know', $outcome->reason);
        self::assertCount(1, $client->requests, 'the turn does not go on');
    }

    public function testAnErrorReasonInTheFinalReportAfterTheBudgetEndsTheTurnWithAnError(): void
    {
        [$outcome, $client] = $this->runLoop([
            ScriptedClient::call('list', ['path' => '.']),
            new LlmResponse('partial', '', [], FinishReason::ERROR, new Usage()),
        ], maxTurns: 1);

        self::assertSame(OutcomeStatus::Error, $outcome->status);
        self::assertSame('the reply ended with a finish reason this client does not know', $outcome->reason);
        self::assertCount(2, $client->requests);
    }

    public function testAStreamEndingWithAnUnknownReasonEndsTheTurnWithAnErrorThatNamesIt(): void
    {
        $outcome = $this->streamedTurn(
            'data: {"choices":[{"delta":{"content":"I cannot help"},"finish_reason":null}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{},"finish_reason":"content_filter"}]}' . "\n\n"
            . "data: [DONE]\n\n",
        );

        self::assertSame(OutcomeStatus::Error, $outcome->status);
        self::assertSame('the reply ended with the finish reason content_filter, which this client does not know', $outcome->reason);
    }

    public function testAStreamWithoutAFinishReasonEndsTheTurnWithAnError(): void
    {
        $outcome = $this->streamedTurn(
            'data: {"choices":[{"delta":{"content":"half a"},"finish_reason":null}]}' . "\n\n"
            . "data: [DONE]\n\n",
        );

        self::assertSame(OutcomeStatus::Error, $outcome->status);
        self::assertSame('the reply ended without a finish reason', $outcome->reason);
    }

    public function testAWriteAndAnEditEndWithTheDiffOfTheirChange(): void
    {
        [$outcome, , $ui] = $this->runLoop([
            ScriptedClient::call('read', ['path' => 'src/a.php']),
            ScriptedClient::call('write', ['path' => 'notes.txt', 'content' => "hi\n"]),
            ScriptedClient::call('edit', ['path' => 'src/a.php', 'old_string' => 'two', 'new_string' => 'TWO']),
            ScriptedClient::text('done'),
        ]);

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertSame(['read', 'write', 'edit'], array_column($ui->diffs, 'tool'));
        self::assertNull($ui->diffs[0]['diff']);
        self::assertSame(['path' => 'notes.txt', 'added' => 1, 'removed' => 0], $this->diffCounts($ui->diffs[1]['diff']));
        self::assertSame(['path' => 'src/a.php', 'added' => 1, 'removed' => 1], $this->diffCounts($ui->diffs[2]['diff']));
    }

    public function testTheApprovalOfAWriteCarriesTheChangeItWouldMake(): void
    {
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];

        [$outcome] = $this->runLoop([
            ScriptedClient::call('write', ['path' => 'src/a.php', 'content' => "one\nTWO\n"]),
            ScriptedClient::text('stopped'),
        ], policy: RunPolicy::primary(Kit::agent(), 'ask'), ui: $ui);

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertCount(1, $ui->requests);
        self::assertSame(['path' => 'src/a.php', 'added' => 1, 'removed' => 1], $this->diffCounts($ui->requests[0]->preview));
        self::assertSame("one\ntwo\n", file_get_contents($this->root . '/src/a.php'), 'a denied write changes nothing');
    }

    private function diffCounts(?array $diff): array
    {
        self::assertNotNull($diff);

        return ['path' => $diff['path'], 'added' => $diff['added'], 'removed' => $diff['removed']];
    }

    public function testNoticesAndProgressReachTheUiAndTextOnlyWhenItIsStreamed(): void
    {
        $streamed = new RecordingUi();
        $this->runWith($this->reportingClient(ScriptedClient::text('done')), $streamed, true);
        $quiet = new RecordingUi();
        $this->runWith($this->reportingClient(ScriptedClient::text('done')), $quiet, false);

        $notices = [['notice', 'warn', 'profile main answered HTTP 429'], ['notice', 'info', 'llama-server is still loading the model: waited 1 of 60 s']];
        self::assertSame(array_merge($notices, [['text', 'streamed']]), $this->notesAndText($streamed));
        self::assertSame($notices, $this->notesAndText($quiet), 'a run that does not stream its text still shows the notices');
    }

    public function testTheFinalReportAfterTheBudgetShowsItsNoticesButDoesNotStreamItsText(): void
    {
        $ui = new RecordingUi();

        $outcome = $this->runWith($this->reportingClient(ScriptedClient::call('list', ['path' => '.']), ScriptedClient::text('final report')), $ui, true, 1);

        self::assertSame(OutcomeStatus::Budget, $outcome->status);
        $texts = array_filter($this->notesAndText($ui), static fn (array $event): bool => $event[0] === 'text');
        self::assertCount(1, $texts, 'only the reply of the turn streams; the final report does not');
        self::assertCount(4, array_filter($this->notesAndText($ui), static fn (array $event): bool => $event[0] === 'notice'), 'the turn and the final report each show their notice and progress');
    }

    public function testTheFallbackNoticeReachesTheUi(): void
    {
        $client = new FallbackClient(['main', 'backup'], [
            new ScriptedClient([new LlmException('HTTP 429: too many requests')]),
            new ScriptedClient([ScriptedClient::text('from the backup')]),
        ]);
        $ui = new RecordingUi();

        $outcome = $this->runWith($client, $ui, false);

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        $notices = $this->notesAndText($ui);
        self::assertCount(1, $notices, 'one switch, so one notice');
        self::assertSame(['notice', 'warn'], [$notices[0][0], $notices[0][1]]);
        self::assertStringContainsString('fallback profile backup', (string) $notices[0][2]);
    }

    public function testTheWaitForALoadingModelReachesTheUi(): void
    {
        $server = new LlamaServer(['loading' => [
            ['status' => 503, 'body' => '{"error":{"message":"Loading model","type":"unavailable_error","code":503}}', 'type' => 'application/json'],
            ['fixture' => 'text.sse'],
        ]]);
        OpenAiClient::setLoadingWait(0.01, 5.0);
        $ui = new RecordingUi();
        try {
            $outcome = $this->runWith(new OpenAiClient($server->profile('loading')), $ui, false);
        } finally {
            $server->stop();
        }

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        $notices = $this->notesAndText($ui);
        self::assertCount(1, $notices, 'one loading reply, so one wait');
        self::assertSame(['notice', 'info'], [$notices[0][0], $notices[0][1]]);
        self::assertStringContainsString('still loading the model', (string) $notices[0][2]);
    }

    private function runWith(ChatClient $client, RecordingUi $ui, bool $streamText, int $maxTurns = 10): Outcome
    {
        $tools = new ToolRegistry();
        $tools->register(new ListTool());
        $context = Kit::context($this->root, Kit::agent(), RunPolicy::primary(Kit::agent(), 'yolo'), $ui);
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('task');

        return (new AgentRunner())->run($conversation, new RunSetup($client, 'native', $tools, $context, $maxTurns, $streamText));
    }

    private function reportingClient(LlmResponse ...$replies): ChatClient
    {
        return new class (array_values($replies)) implements ChatClient {
            public function __construct(private array $replies)
            {
            }

            public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
            {
                $options->emit('notice', 'profile main answered HTTP 429');
                $options->emit('progress', 'llama-server is still loading the model: waited 1 of 60 s');
                $options->emit('text', 'streamed');

                return array_shift($this->replies);
            }

            public function listModels(): array
            {
                return [];
            }

            public function describe(string $model): ModelInfo
            {
                return new ModelInfo($model, 'test', 'openai');
            }
        };
    }

    private function notesAndText(RecordingUi $ui): array
    {
        return array_values(array_filter($ui->events, static fn (array $event): bool => in_array($event[0], ['notice', 'text'], true)));
    }

    private function streamedTurn(string $stream): Outcome
    {
        $server = new LlamaServer(['reply' => [['body' => $stream, 'type' => 'text/event-stream']]]);
        try {
            $context = Kit::context($this->root, Kit::agent(), RunPolicy::primary(Kit::agent(), 'yolo'), new RecordingUi());
            $conversation = new Conversation();
            $conversation->setSystem('system');
            $conversation->addUser('task');

            return (new AgentRunner())->run($conversation, new RunSetup(new OpenAiClient($server->profile('reply')), 'none', new ToolRegistry(), $context, 10, false));
        } finally {
            $server->stop();
        }
    }

    private function toolEvents(RecordingUi $ui): array
    {
        return array_values(array_filter($ui->events, static fn (array $event): bool => in_array($event[0], ['tool_start', 'tool_end'], true)));
    }
}
