<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Agent;

use Phycode\Agent\AgentRunner;
use Phycode\Agent\Conversation;
use Phycode\Agent\Hooks;
use Phycode\Agent\OutcomeStatus;
use Phycode\Agent\RunPolicy;
use Phycode\Agent\RunSetup;
use Phycode\Support\Cancellation;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tools\ListTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\ToolRegistry;
use Phycode\Tools\WriteTool;
use PHPUnit\Framework\TestCase;

final class AgentHooksTest extends TestCase
{
    private const SCRIPT = <<<'PHP'
<?php
$payload = json_decode(stream_get_contents(STDIN), true);
file_put_contents(__DIR__ . '/events.jsonl', json_encode($payload) . "\n", FILE_APPEND);
if (($payload['hook_event_name'] ?? '') === 'PreToolUse' && ($payload['tool_name'] ?? '') === 'write') {
    fwrite(STDERR, 'writes are frozen');
    exit(2);
}
exit(0);
PHP;

    private string $root;
    private string $script;

    protected function setUp(): void
    {
        $this->root = Kit::folder('agent-hooks');
        Kit::write($this->root . '/src/a.php', "one\ntwo\n");
        $this->script = $this->root . '/hook.php';
        file_put_contents($this->script, self::SCRIPT);
    }

    private function runTurn(array $replies, ?RunPolicy $policy = null, ?Cancellation $cancel = null): array
    {
        $command = "'" . PHP_BINARY . "' '{$this->script}'";
        $hooks = new Hooks([
            'PreToolUse' => [['command' => $command, 'match' => null]],
            'PostToolUse' => [['command' => $command, 'match' => null]],
            'Stop' => [['command' => $command, 'match' => null]],
            'SubagentStop' => [['command' => $command, 'match' => null]],
        ]);
        $tools = new ToolRegistry();
        foreach ([new ReadTool(), new ListTool(), new WriteTool()] as $tool) {
            $tools->register($tool);
        }
        $ui = new RecordingUi();
        $client = new ScriptedClient($replies);
        $context = Kit::context($this->root, Kit::agent(), $policy ?? RunPolicy::primary(Kit::agent(), 'yolo'), $ui, null, $cancel);
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('task');

        $outcome = (new AgentRunner($hooks))->run($conversation, new RunSetup($client, 'native', $tools, $context, 10, false));

        return [$outcome, $client, $ui, $this->events()];
    }

    private function events(): array
    {
        $file = $this->root . '/events.jsonl';
        if (!is_file($file)) {
            return [];
        }

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents($file)))),
        );
    }

    public function testAPreToolUseBlockStopsTheWriteBeforeItIsApprovedOrRun(): void
    {
        [$outcome, $client, $ui, $events] = $this->runTurn([
            ScriptedClient::call('write', ['path' => 'notes.txt', 'content' => "hi\n"]),
            ScriptedClient::text('stopped'),
        ], RunPolicy::primary(Kit::agent(), 'ask'));

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertFileDoesNotExist($this->root . '/notes.txt');
        self::assertSame([], array_values(array_filter($ui->events, static fn (array $event): bool => $event[0] === 'confirm')), 'nobody is asked about a blocked call');
        $reported = $client->requests[1]['messages'][count($client->requests[1]['messages']) - 1]['content'];
        self::assertSame('ERROR: blocked by a hook: writes are frozen', $reported);
        self::assertSame(['write'], array_column(array_values(array_filter($events, static fn (array $event): bool => $event['hook_event_name'] === 'PreToolUse')), 'tool_name'));
    }

    public function testThePreToolUsePayloadCarriesTheToolAndItsInput(): void
    {
        [, , , $events] = $this->runTurn([
            ScriptedClient::call('read', ['path' => 'src/a.php']),
            ScriptedClient::text('done'),
        ]);

        $pre = $events[0];
        self::assertSame(['hook_event_name' => 'PreToolUse', 'agent' => 'build', 'depth' => 0, 'cwd' => realpath($this->root), 'tool_name' => 'read', 'tool_input' => ['path' => 'src/a.php'], 'tool_use_id' => $pre['tool_use_id']], $pre);
        self::assertNotSame('', (string) $pre['tool_use_id']);
    }

    public function testThePostToolUseHookSeesTheResultOfTheCall(): void
    {
        [, , , $events] = $this->runTurn([
            ScriptedClient::call('read', ['path' => 'src/a.php']),
            ScriptedClient::text('done'),
        ]);

        $post = array_values(array_filter($events, static fn (array $event): bool => $event['hook_event_name'] === 'PostToolUse'));
        self::assertCount(1, $post);
        self::assertSame('read', $post[0]['tool_name']);
        self::assertTrue($post[0]['tool_response']['ok']);
        self::assertStringContainsString('two', $post[0]['tool_response']['content']);
    }

    public function testAStopHookReceivesHowTheRunEnded(): void
    {
        [$outcome, , , $events] = $this->runTurn([ScriptedClient::text('all done')]);

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertSame(['hook_event_name' => 'Stop', 'agent' => 'build', 'depth' => 0, 'cwd' => realpath($this->root), 'status' => 'completed', 'turns' => 1, 'tool_calls' => 0, 'stop_hook_active' => false, 'last_assistant_message' => 'all done'], $events[0]);
    }

    public function testASubagentRunSendsSubagentStopInsteadOfStop(): void
    {
        [, , , $events] = $this->runTurn(
            [ScriptedClient::text('report')],
            new RunPolicy(null, [], [], 'yolo', 1),
        );

        self::assertSame(['SubagentStop'], array_column($events, 'hook_event_name'));
    }

    public function testACancelledRunDoesNotWaitForAStopHook(): void
    {
        $cancel = new Cancellation();
        $cancel->cancel('stopped by the user');

        [$outcome, , , $events] = $this->runTurn([ScriptedClient::text('unused')], null, $cancel);

        self::assertSame(OutcomeStatus::Cancelled, $outcome->status);
        self::assertSame([], $events);
    }
}
