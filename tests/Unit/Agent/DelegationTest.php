<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Agent;

use Phycode\Agent\AgentRegistry;
use Phycode\Agent\AgentRunner;
use Phycode\Agent\Delegation;
use Phycode\Agent\PromptBuilder;
use Phycode\Agent\RunPolicy;
use Phycode\Config\Config;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ClientProvider;
use Phycode\Support\Cancellation;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\ScriptedProvider;
use Phycode\Tools\BaseTool;
use Phycode\Tools\BashTool;
use Phycode\Tools\EditTool;
use Phycode\Tools\GrepTool;
use Phycode\Tools\ListTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\TaskTool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolRegistry;
use Phycode\Tools\ToolResult;
use Phycode\Tools\WriteTool;
use PHPUnit\Framework\TestCase;

final class DelegationTest extends TestCase
{
    private string $root;
    private Config $config;

    protected function setUp(): void
    {
        $this->root = Kit::folder('delegation');
        Kit::write($this->root . '/src/a.php', "<?php\n// config is read here\n");
        Kit::write($this->root . '/.phycode/agents/writer.md', "---\nname: writer\ndescription: Writes files when asked\ntools: [read, write]\n---\nYou write files.\n");
        Kit::write($this->root . '/.phycode/agents/quick.md', "---\nname: quick\ndescription: One turn only\nmax_turns: 1\n---\nBe brief.\n");
        $this->config = Config::fromArrays(
            ['profiles' => ['test' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'scripted', 'tool_mode' => 'native']]],
            null,
            [],
            '/nonexistent',
        );
    }

    private function delegation(array $childReplies): array
    {
        $client = new ScriptedClient($childReplies);

        return [$this->delegationWith(new ScriptedProvider(['test' => $client])), $client, new RecordingUi()];
    }

    private function delegationWith(ClientProvider $provider, ?Config $config = null): Delegation
    {
        $agents = AgentRegistry::load($this->root, $this->root, '/nonexistent', '/nonexistent', Kit::builtinAgents());
        $tools = new ToolRegistry();
        foreach ([new ReadTool(), new ListTool(), new GrepTool(), new WriteTool(), new EditTool(), new BashTool()] as $tool) {
            $tools->register($tool);
        }
        $tools->register(new TaskTool($agents, true));

        return new Delegation($agents, $provider, $tools, new AgentRunner(), new PromptBuilder(), $config ?? $this->config, $this->root, null);
    }

    private function parent(RecordingUi $ui, RunPolicy $policy, Delegation $delegation): ToolContext
    {
        return Kit::context($this->root, Kit::agent('build', 'primary'), $policy, $ui, $delegation);
    }

    public function testASubagentRunsOnTheModelAndThinkingOfTheSessionThatDelegates(): void
    {
        $client = new ScriptedClient([ScriptedClient::call('grep', ['pattern' => 'config']), ScriptedClient::text('src/a.php:2 reads the config.')]);
        $provider = new class ($client) implements ClientProvider {
            public array $asked = [];

            public function __construct(private readonly ChatClient $client)
            {
            }

            public function forProfile(string $name, ?string $model = null, ?string $thinking = null): ChatClient
            {
                $this->asked[] = ['forProfile', $name, $model, $thinking];

                return $this->client;
            }

            public function toolMode(string $name, ?string $model = null, ?string $thinking = null): string
            {
                $this->asked[] = ['toolMode', $name, $model, $thinking];

                return $model === 'claude-haiku-5-5' ? 'native' : 'none';
            }
        };
        $delegation = $this->delegationWith($provider);
        $parent = Kit::context($this->root, Kit::agent('build', 'primary'), RunPolicy::primary(Kit::agent(), 'yolo'), new RecordingUi(), $delegation, model: 'claude-haiku-5-5', thinking: 'low');

        $result = $delegation->delegate('explore', 'Find where the config is read in this project.', $parent);

        self::assertTrue($result->ok, $result->content);
        self::assertSame([
            ['toolMode', 'test', 'claude-haiku-5-5', 'low'],
            ['forProfile', 'test', 'claude-haiku-5-5', 'low'],
        ], $provider->asked);
    }

    public function testASubagentOfAProfileWithoutAModelSaysSoAndAsksNothing(): void
    {
        $config = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'http://127.0.0.1:9', 'tool_mode' => 'native']]], null, [], '/nonexistent');
        $client = new ScriptedClient([]);
        $delegation = $this->delegationWith(new ScriptedProvider(['test' => $client]), $config);
        $parent = Kit::context($this->root, Kit::agent('build', 'primary'), RunPolicy::primary(Kit::agent(), 'yolo'), new RecordingUi(), $delegation);

        $result = $delegation->delegate('explore', 'Find where the config is read in this project.', $parent);

        self::assertFalse($result->ok);
        self::assertSame("profile 'test' has no model: choose one in /models, or set its model in the config", $result->content);
        self::assertSame([], $client->requests);
    }

    public function testExploreRunsWithReadOnlyToolsAndReturnsItsReport(): void
    {
        [$delegation, $client, $ui] = $this->delegation([
            ScriptedClient::call('grep', ['pattern' => 'config']),
            ScriptedClient::text('src/a.php:2 reads the config.'),
        ]);
        $parent = $this->parent($ui, RunPolicy::primary(Kit::agent(), 'yolo'), $delegation);

        $result = $delegation->delegate('explore', 'Find where the config is read in this project.', $parent);

        self::assertTrue($result->ok);
        self::assertStringStartsWith('[subagent explore finished', $result->content);
        self::assertStringContainsString('src/a.php:2', $result->content);
        self::assertSame(['read', 'list', 'grep'], array_column(array_column($client->requests[0]['tools'], 'function'), 'name'));
        self::assertSame(['subagent_start', 'subagent_end'], array_values(array_map(static fn (array $e): string => $e[0], array_filter($ui->events, static fn (array $e): bool => str_starts_with($e[0], 'subagent')))));
    }

    public function testChildTasksDoNotSeeTheParentConversation(): void
    {
        [$delegation, $client, $ui] = $this->delegation([ScriptedClient::text('nothing to add')]);
        $parent = $this->parent($ui, RunPolicy::primary(Kit::agent(), 'yolo'), $delegation);

        $delegation->delegate('explore', 'Summarize the single file under src/ in two sentences.', $parent);

        $messages = $client->requests[0]['messages'];
        self::assertSame(['system', 'user'], array_column($messages, 'role'), 'the subagent starts with its own system prompt and only the task');
        self::assertSame('Summarize the single file under src/ in two sentences.', $messages[1]['content']);
    }

    public function testSubagentCannotExceedAReadonlyParent(): void
    {
        [$delegation, $client, $ui] = $this->delegation([
            ScriptedClient::call('write', ['path' => 'src/new.php', 'content' => 'x']),
            ScriptedClient::text('could not write'),
        ]);
        $readonly = RunPolicy::primary(Kit::agent('plan'), 'yolo');
        $readonly = $readonly->withDenied(['write']);
        $parent = $this->parent($ui, $readonly, $delegation);

        $result = $delegation->delegate('writer', 'Create src/new.php with the text x in it.', $parent);

        self::assertTrue($result->ok);
        self::assertFileDoesNotExist($this->root . '/src/new.php');
        self::assertStringContainsString("tool 'write' is not available", end($client->requests[1]['messages'])['content']);
    }

    public function testSubagentWithPermittedToolsCanWriteWhenTheModeAllowsIt(): void
    {
        [$delegation, , $ui] = $this->delegation([
            ScriptedClient::call('write', ['path' => 'src/new.php', 'content' => "x\n"]),
            ScriptedClient::text('written'),
        ]);
        $parent = $this->parent($ui, RunPolicy::primary(Kit::agent(), 'yolo'), $delegation);

        $result = $delegation->delegate('writer', 'Create src/new.php containing one line with the letter x.', $parent);

        self::assertTrue($result->ok);
        self::assertSame("x\n", file_get_contents($this->root . '/src/new.php'));
    }

    public function testSubagentCannotStartAnotherSubagent(): void
    {
        [$delegation, $client, $ui] = $this->delegation([
            ScriptedClient::call('task', ['agent' => 'explore', 'prompt' => 'Look for anything interesting in the project.']),
            ScriptedClient::text('no further delegation possible'),
        ]);
        $parent = $this->parent($ui, RunPolicy::primary(Kit::agent(), 'yolo'), $delegation);

        $delegation->delegate('general', 'Try to delegate a search to another agent and report back.', $parent);

        self::assertNotContains('task', array_column(array_column($client->requests[0]['tools'], 'function'), 'name'));
        self::assertStringContainsString("tool 'task' is not available", end($client->requests[1]['messages'])['content']);
    }

    public function testUnknownAgentAndTooShortTasksAreRejected(): void
    {
        [$delegation, , $ui] = $this->delegation([]);
        $parent = $this->parent($ui, RunPolicy::primary(Kit::agent(), 'yolo'), $delegation);

        $unknown = $delegation->delegate('nobody', 'A long enough task description here.', $parent);
        self::assertFalse($unknown->ok);
        self::assertStringContainsString('unknown subagent', $unknown->content);

        $short = $delegation->delegate('explore', 'find it', $parent);
        self::assertFalse($short->ok);
        self::assertStringContainsString('too short', $short->content);
    }

    public function testBudgetExhaustionReturnsAPartialReport(): void
    {
        [$delegation, , $ui] = $this->delegation([
            ScriptedClient::call('list', ['path' => '.']),
            ScriptedClient::text('partial: the src folder exists'),
        ]);
        $parent = $this->parent($ui, RunPolicy::primary(Kit::agent(), 'yolo'), $delegation);

        $result = $delegation->delegate('quick', 'List the project and say what you find in one line.', $parent);

        self::assertTrue($result->ok);
        self::assertStringContainsString('reached its turn budget', $result->content);
        self::assertStringContainsString('partial: the src folder exists', $result->content);
    }

    public function testAReportThatUsedNoToolsIsFlagged(): void
    {
        [$delegation, , $ui] = $this->delegation([
            ScriptedClient::text('I believe it is in a.php.'),
            ScriptedClient::text('I believe it is in a.php.'),
        ]);
        $parent = $this->parent($ui, RunPolicy::primary(Kit::agent(), 'yolo'), $delegation);

        $result = $delegation->delegate('explore', 'Find where the config is read in this project.', $parent);

        self::assertTrue($result->ok);
        self::assertStringContainsString('used no tools', $result->content);
    }

    public function testAnAlreadyCancelledParentStopsTheSubagentBeforeItStarts(): void
    {
        [$delegation, $client, $ui] = $this->delegation([ScriptedClient::text('should not run')]);
        $cancel = new Cancellation();
        $cancel->cancel('interrupted by the user');
        $parent = Kit::context($this->root, Kit::agent('build', 'primary'), RunPolicy::primary(Kit::agent(), 'yolo'), $ui, $delegation, $cancel);

        $result = $delegation->delegate('explore', 'Find where the config is read in this project.', $parent);

        self::assertFalse($result->ok);
        self::assertStringContainsString('stopped (cancelled)', $result->content);
        self::assertSame([], $client->requests);
    }

    public function testAFailingChildIsReportedAsAFailure(): void
    {
        [$delegation, , $ui] = $this->delegation([new \Phycode\Llm\LlmException('connection to the model server failed: refused')]);
        $parent = $this->parent($ui, RunPolicy::primary(Kit::agent(), 'yolo'), $delegation);

        $result = $delegation->delegate('explore', 'Find where the config is read in this project.', $parent);

        self::assertFalse($result->ok);
        self::assertStringContainsString('stopped (error)', $result->content);
    }

    public function testTheReasonOfAFailedChildHasNoTokenInTheResultOrTheTranscript(): void
    {
        [$delegation, , $ui] = $this->delegation([new \Phycode\Llm\LlmException('the server refused Bearer abc123 for this call')]);
        $parent = $this->parent($ui, RunPolicy::primary(Kit::agent(), 'yolo'), $delegation);

        $result = $delegation->delegate('explore', 'Find where the config is read in this project.', $parent);

        self::assertFalse($result->ok);
        self::assertSame("subagent explore stopped (error): the server refused Bearer [redacted] for this call", $result->content);
        $transcript = $parent->transcript->contents();
        self::assertStringNotContainsString('abc123', $transcript);
        self::assertStringContainsString('"reason":"the server refused Bearer [redacted] for this call"', $transcript);
    }

    public function testANonPhycodeExceptionInsideTheSubagentEndsItsLineAndIsRethrown(): void
    {
        $tools = new ToolRegistry();
        $tools->register(new class extends BaseTool {
            public function name(): string
            {
                return 'boom';
            }

            public function description(): string
            {
                return 'Fails in validation with a plain exception (test double).';
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
                throw new \RuntimeException('the tool broke with Bearer abc123');
            }

            public function execute(array $args, ToolContext $context): ToolResult
            {
                return ToolResult::ok('never reached');
            }
        });
        $client = new ScriptedClient([ScriptedClient::call('boom', [])]);
        $agents = AgentRegistry::load($this->root, $this->root, '/nonexistent', '/nonexistent', Kit::builtinAgents());
        $delegation = new Delegation($agents, new ScriptedProvider(['test' => $client]), $tools, new AgentRunner(), new PromptBuilder(), $this->config, $this->root, null);
        $ui = new RecordingUi();
        $parent = $this->parent($ui, RunPolicy::primary(Kit::agent(), 'yolo'), $delegation);

        $thrown = null;
        try {
            $delegation->delegate('quick', 'Call the boom tool once and say what it returned.', $parent);
        } catch (\RuntimeException $error) {
            $thrown = $error;
        }

        self::assertSame('the tool broke with Bearer abc123', $thrown?->getMessage(), 'the exception goes on to the caller');
        self::assertSame(
            [['subagent_start', 'quick'], ['tool_start', 'quick', 'boom'], ['tool_end', 'boom', false], ['subagent_end', 'quick', 'error']],
            array_values(array_filter($ui->events, static fn (array $event): bool => in_array($event[0], ['subagent_start', 'subagent_end', 'tool_start', 'tool_end'], true))),
        );
        $transcript = $parent->transcript->contents();
        self::assertStringContainsString('"type":"subagent_end"', $transcript);
        self::assertStringContainsString('"reason":"the tool broke with Bearer [redacted]"', $transcript);
        self::assertStringNotContainsString('abc123', $transcript);
    }
}
