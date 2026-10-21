<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Agent;

use Phycode\Agent\AgentRunner;
use Phycode\Agent\Checks;
use Phycode\Agent\Conversation;
use Phycode\Agent\Hooks;
use Phycode\Agent\Outcome;
use Phycode\Agent\OutcomeStatus;
use Phycode\Agent\RunPolicy;
use Phycode\Agent\RunSetup;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tools\EditTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\ToolRegistry;
use Phycode\Tools\WriteTool;
use PHPUnit\Framework\TestCase;

final class AgentHooksV2Test extends TestCase
{
    private const SCRIPT = <<<'PHP'
<?php
$name = $argv[1];
$payload = stream_get_contents(STDIN);
file_put_contents(__DIR__ . '/events.jsonl', $payload, FILE_APPEND);
$count = (int) @file_get_contents(__DIR__ . "/{$name}.count") + 1;
file_put_contents(__DIR__ . "/{$name}.count", (string) $count);
// "<name>.script" holds one line per call: "<exit code>|<stdout>|<stderr>"; the last line repeats.
$lines = is_file(__DIR__ . "/{$name}.script") ? file(__DIR__ . "/{$name}.script", FILE_IGNORE_NEW_LINES) : ['0||'];
[$code, $out, $err] = explode('|', $lines[min($count, count($lines)) - 1] . '||', 4) + [0, '', ''];
echo str_replace('\n', "\n", $out);
fwrite(STDERR, str_replace('\n', "\n", $err));
exit((int) $code);
PHP;

    private string $root;

    protected function setUp(): void
    {
        $this->root = Kit::folder('agent-hooks-v2');
        file_put_contents($this->root . '/hook.php', self::SCRIPT);
        Kit::write($this->root . '/src/a.php', "<?php\necho 1;\n");
        Kit::write($this->root . '/lint.php', "<?php\n\$lines = file(\$argv[1]);\nforeach (\$lines as \$n => \$line) { if (str_contains(\$line, 'BAD')) { fwrite(STDERR, 'line ' . (\$n + 1) . ': BAD word'); exit(1); } }\n");
    }

    private function command(string $name, string $script = ''): string
    {
        if ($script !== '') {
            file_put_contents("{$this->root}/{$name}.script", $script);
        }

        return "'" . PHP_BINARY . "' '{$this->root}/hook.php' {$name}";
    }

    private function turn(array $replies, array $hooks = [], array $checks = [], ?RunPolicy $policy = null): array
    {
        $tools = new ToolRegistry();
        foreach ([new ReadTool(), new WriteTool(), new EditTool()] as $tool) {
            $tools->register($tool);
        }
        $ui = new RecordingUi();
        $client = new ScriptedClient($replies);
        $context = Kit::context($this->root, Kit::agent(), $policy ?? RunPolicy::primary(Kit::agent(), 'yolo'), $ui);
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('task');

        $outcome = (new AgentRunner(new Hooks($hooks), new Checks($checks)))->run($conversation, new RunSetup($client, 'native', $tools, $context, 20, false));

        return [$outcome, $client, $ui];
    }

    private function toolReply(ScriptedClient $client, int $request = 1): string
    {
        $messages = array_values(array_filter($client->requests[$request]['messages'], static fn (array $message): bool => ($message['role'] ?? '') === 'tool'));

        return (string) end($messages)['content'];
    }

    private function events(): array
    {
        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) @file_get_contents($this->root . '/events.jsonl')))),
        );
    }

    private function lint(): array
    {
        return ['name' => 'lint', 'match' => '*.txt', 'command' => "'" . PHP_BINARY . "' '{$this->root}/lint.php' {file}", 'kind' => 'check', 'timeout' => 10];
    }

    public function testAFailedCheckIsInTheResultOfTheEditThatCausedIt(): void
    {
        [$outcome, $client] = $this->turn([
            ScriptedClient::call('write', ['path' => 'src/b.txt', 'content' => "fine\nBAD\n"]),
            ScriptedClient::call('write', ['path' => 'src/b.txt', 'content' => "fine\n"], 'call_2'),
            ScriptedClient::text('done'),
        ], checks: [$this->lint()]);

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertStringContainsString("check lint failed on src/b.txt (exit 1):\nline 2: BAD word", $this->toolReply($client, 1));
        self::assertStringStartsWith('created src/b.txt', $this->toolReply($client, 1), 'the result of the write is still first');
        self::assertStringContainsString('check lint: ok', $this->toolReply($client, 2));
    }

    public function testNothingIsCheckedForAFailedCallOrForAToolThatDoesNotSaveAFile(): void
    {
        Kit::write($this->root . '/src/d.txt', "BAD\n");
        [, $client] = $this->turn([
            ScriptedClient::call('read', ['path' => 'src/d.txt']),
            ScriptedClient::call('edit', ['path' => 'src/d.txt', 'old_string' => 'absent text', 'new_string' => 'x'], 'call_2'),
            ScriptedClient::text('done'),
        ], checks: [$this->lint()]);

        self::assertStringNotContainsString('check lint', $this->toolReply($client, 1));
        self::assertStringNotContainsString('check lint', $this->toolReply($client, 2));
    }

    public function testExitTwoOfAPostToolUseHookTellsTheModelAfterTheResult(): void
    {
        [, $client] = $this->turn([
            ScriptedClient::call('write', ['path' => 'src/c.php', 'content' => "<?php\n"]),
            ScriptedClient::text('done'),
        ], ['PostToolUse' => [['command' => $this->command('post', "2||phpstan: 1 error on line 2\\nundefined variable \$x"), 'match' => 'edit|write']]]);

        $reply = $this->toolReply($client);
        self::assertStringStartsWith('created src/c.php', $reply);
        self::assertStringContainsString("PostToolUse hook: phpstan: 1 error on line 2\nundefined variable \$x", $reply);
        self::assertStringNotContainsString('ERROR:', $reply, 'the tool itself worked');
    }

    public function testAPreToolUseHookCanChangeTheArgumentsAndTheCallRunsWithThem(): void
    {
        $update = json_encode(['hookSpecificOutput' => ['updatedInput' => ['path' => 'src/safe.txt', 'content' => 'rewritten']]]);
        [, $client] = $this->turn([
            ScriptedClient::call('write', ['path' => 'src/asked.txt', 'content' => 'original']),
            ScriptedClient::text('done'),
        ], ['PreToolUse' => [['command' => $this->command('pre', '0|' . $update . '|'), 'match' => 'write']]]);

        self::assertFileDoesNotExist($this->root . '/src/asked.txt');
        self::assertSame('rewritten', file_get_contents($this->root . '/src/safe.txt'));
        self::assertStringContainsString('src/safe.txt', $this->toolReply($client));
    }

    public function testArgumentsFromAHookThatAreNotValidFailTheCallInsteadOfRunningIt(): void
    {
        $update = json_encode(['hookSpecificOutput' => ['updatedInput' => ['path' => '../outside.txt', 'content' => 'x']]]);
        [, $client] = $this->turn([
            ScriptedClient::call('write', ['path' => 'src/ok.txt', 'content' => 'x']),
            ScriptedClient::text('done'),
        ], ['PreToolUse' => [['command' => $this->command('pre', '0|' . $update . '|'), 'match' => 'write']]]);

        self::assertFileDoesNotExist(dirname($this->root) . '/outside.txt');
        self::assertFileDoesNotExist($this->root . '/src/ok.txt');
        self::assertStringContainsString('ERROR:', $this->toolReply($client));
    }

    public function testAStopHookThatAsksForMoreWorkSendsTheModelBackWithItsReason(): void
    {
        [$outcome, $client] = $this->turn(
            [ScriptedClient::text('first answer'), ScriptedClient::text('second answer')],
            ['Stop' => [['command' => $this->command('stop', "2||run the tests before you stop\n0||"), 'match' => null]]],
        );

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertSame('second answer', $outcome->text);
        self::assertSame(2, $outcome->turns);
        $last = end($client->requests[1]['messages']);
        self::assertSame('user', $last['role']);
        self::assertSame("A Stop hook asks you to keep working:\nrun the tests before you stop", $last['content']);
        $stops = array_values(array_filter($this->events(), static fn (array $event): bool => $event['hook_event_name'] === 'Stop'));
        self::assertSame([false, true], array_column($stops, 'stop_hook_active'));
        self::assertSame(['first answer', 'second answer'], array_column($stops, 'last_assistant_message'));
    }

    public function testAStopHookThatNeverLetsGoIsStoppedAfterEightContinuations(): void
    {
        $replies = array_map(static fn (int $n): \Phycode\Llm\LlmResponse => ScriptedClient::text("answer {$n}"), range(1, 12));

        [$outcome, $client] = $this->turn($replies, ['Stop' => [['command' => $this->command('stop', '2||not yet'), 'match' => null]]]);

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertCount(9, $client->requests, 'the first answer and eight more');
        self::assertSame('answer 9', $outcome->text);
        self::assertSame(9, $outcome->turns);
    }

    public function testAStopHookDoesNotKeepARunGoingThatDidNotEndWithAnAnswer(): void
    {
        [$outcome, $client] = $this->turn(
            [new \Phycode\Llm\LlmResponse('partial', '', [], \Phycode\Llm\Canonical\FinishReason::ERROR, new \Phycode\Llm\Canonical\Usage()), ScriptedClient::text('never asked')],
            ['Stop' => [['command' => $this->command('stop', '2||go on'), 'match' => null]]],
        );

        self::assertSame(OutcomeStatus::Error, $outcome->status);
        self::assertCount(1, $client->requests);
    }

    public function testAPromptHookCanRefuseThePromptOrAddContext(): void
    {
        $ui = new RecordingUi();
        $context = Kit::context($this->root, Kit::agent(), RunPolicy::primary(Kit::agent(), 'yolo'), $ui);
        $runner = new AgentRunner(new Hooks(['UserPromptSubmit' => [['command' => $this->command('prompt', "0|branch: main|\n2||no secrets please"), 'match' => null]]]));

        $first = $runner->promptSubmitted('fix the bug', $context);
        $second = $runner->promptSubmitted('my password is hunter2', $context);

        self::assertNull($first->block);
        self::assertSame(['branch: main'], $first->context);
        self::assertSame('no secrets please', $second->block);
        $events = $this->events();
        self::assertSame(['fix the bug', 'my password is hunter2'], array_column($events, 'prompt'));
        self::assertSame('UserPromptSubmit', $events[0]['hook_event_name']);
    }
}
