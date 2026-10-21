<?php

declare(strict_types=1);

namespace Phycode\Tests\Integration;

use Phycode\Agent\AgentRunner;
use Phycode\Agent\Conversation;
use Phycode\Agent\Outcome;
use Phycode\Agent\OutcomeStatus;
use Phycode\Agent\RunPolicy;
use Phycode\Agent\RunSetup;
use Phycode\Llm\OpenAiClient;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\LlamaServer;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\BaseTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\Tool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolRegistry;
use Phycode\Tools\ToolResult;
use PHPUnit\Framework\TestCase;

final class FinishReasonTurnTest extends TestCase
{
    private const CUT_NOTICE = 'the reply was cut off by the token limit (max_output_tokens)';
    private const CUT_TOOL_NOTICE = 'the tool call was cut off by the token limit and was not run';

    private string $root;
    private ?LlamaServer $server = null;

    protected function setUp(): void
    {
        $this->root = Kit::folder('finish-turn');
        Kit::write($this->root . '/src/a.php', "one\ntwo\n");
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testAReplyCutByTheTokenLimitCompletesWithANotice(): void
    {
        [$outcome, $ui, $context] = $this->turn(['cut' => [['fixture' => 'cut.sse']]], 'native', new ToolRegistry());

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertSame('The three primary colours are red, blue', $outcome->text);
        self::assertSame([['notice', 'warn', self::CUT_NOTICE]], $this->notices($ui));
        $response = $this->llmResponse($context);
        self::assertSame('length', $response['finish']);
        self::assertSame(8, $response['usage']['completionTokens']);
    }

    public function testAToolCallCutByTheTokenLimitIsNotRunAndTheTurnFails(): void
    {
        $calls = new \ArrayObject();
        $tools = new ToolRegistry();
        $tools->register($this->weatherTool($calls));

        [$outcome, $ui] = $this->turn(['cut' => [['fixture' => 'native-tool-cut.sse']]], 'native', $tools);

        self::assertSame(OutcomeStatus::Error, $outcome->status);
        self::assertSame('the token limit (max_output_tokens) cut off a tool call', $outcome->reason);
        self::assertSame([], $ui->startedTools(), 'the cut call never started');
        self::assertCount(0, $calls);
        self::assertSame([['notice', 'warn', self::CUT_TOOL_NOTICE]], $this->notices($ui));
    }

    public function testANativeToolCallFromTheStreamRunsAndItsResultAnswersTheSameId(): void
    {
        $calls = new \ArrayObject();
        $tools = new ToolRegistry();
        $tools->register($this->weatherTool($calls));

        [$outcome, , $context, $server] = $this->turn([
            'weather' => [['fixture' => 'native-tool.sse'], ['fixture' => 'text.sse']],
        ], 'native', $tools);

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        self::assertSame([['city' => 'Madrid']], $calls->getArrayCopy());
        $requests = $server->requests();
        self::assertSame('get_weather', $requests[0]['json']['tools'][0]['function']['name']);
        $messages = $requests[1]['json']['messages'];
        $assistant = $messages[count($messages) - 2];
        $result = $messages[count($messages) - 1];
        self::assertSame('t029FHyybhFRAWj987zdz8mfOiCq4py1', $assistant['tool_calls'][0]['id']);
        self::assertSame('tool', $result['role']);
        self::assertSame('t029FHyybhFRAWj987zdz8mfOiCq4py1', $result['tool_call_id']);
        self::assertSame('Madrid: 21 C, sunny', $result['content']);

        $response = $this->llmResponse($context);
        self::assertSame('tool_calls', $response['finish']);
        self::assertSame(329, $response['usage']['promptTokens']);
        self::assertSame(325, $response['usage']['cachedTokens']);
    }

    public function testAReportCutByTheTokenLimitAfterTheTurnBudgetGetsTheNotice(): void
    {
        $calls = new \ArrayObject();
        $tools = new ToolRegistry();
        $tools->register($this->weatherTool($calls));

        [$outcome, $ui] = $this->turn([
            'budget' => [['fixture' => 'native-tool.sse'], ['fixture' => 'cut.sse']],
        ], 'native', $tools, maxTurns: 1);

        self::assertSame(OutcomeStatus::Budget, $outcome->status);
        self::assertSame('The three primary colours are red, blue', $outcome->text);
        self::assertCount(1, $calls, 'the tool call of the one allowed turn ran');
        self::assertSame([['notice', 'warn', self::CUT_NOTICE]], $this->notices($ui));
    }

    public function testATextModeToolCallRunsTheReadToolAndItsReportGoesBack(): void
    {
        $tools = new ToolRegistry();
        $tools->register(new ReadTool());

        [$outcome, , , $server] = $this->turn([
            'text' => [['fixture' => 'text-tool.sse'], ['fixture' => 'text.sse']],
        ], 'text', $tools);

        self::assertSame(OutcomeStatus::Completed, $outcome->status);
        $messages = $server->requests()[1]['json']['messages'];
        $report = $messages[count($messages) - 1]['content'];
        self::assertStringStartsWith('<tool_response>', $report);
        self::assertStringContainsString('two', $report, 'the file content came back to the model');
    }

    private function turn(array $scenarios, string $mode, ToolRegistry $tools, int $maxTurns = 10): array
    {
        $this->server?->stop();
        $this->server = new LlamaServer($scenarios);
        $ui = new RecordingUi();
        $context = Kit::context($this->root, Kit::agent(), RunPolicy::primary(Kit::agent(), 'yolo'), $ui);
        $conversation = new Conversation();
        $conversation->setSystem('You are the build agent.');
        $conversation->addUser('What is the weather in Madrid?');

        $client = new OpenAiClient($this->server->profile(array_key_first($scenarios)));
        $outcome = (new AgentRunner())->run($conversation, new RunSetup($client, $mode, $tools, $context, $maxTurns, false));

        return [$outcome, $ui, $context, $this->server];
    }

    private function notices(RecordingUi $ui): array
    {
        return array_values(array_filter($ui->events, static fn (array $event): bool => $event[0] === 'notice'));
    }

    private function llmResponse(ToolContext $context): array
    {
        foreach (array_filter(explode("\n", $context->transcript->contents())) as $line) {
            $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($event['type'] === 'llm_response') {
                return $event['data'];
            }
        }
        self::fail('the transcript has no llm_response event');
    }

    private function weatherTool(\ArrayObject $calls): Tool
    {
        return new class($calls) extends BaseTool {
            public function __construct(private readonly \ArrayObject $calls)
            {
            }

            public function name(): string
            {
                return 'get_weather';
            }

            public function description(): string
            {
                return 'Get the current weather for a city.';
            }

            public function category(): ToolCategory
            {
                return ToolCategory::Read;
            }

            public function parameters(): array
            {
                return [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string'], 'unit' => ['type' => 'string', 'enum' => ['celsius', 'fahrenheit']]],
                    'required' => ['city'],
                ];
            }

            public function execute(array $args, ToolContext $context): ToolResult
            {
                $this->calls[] = $args;

                return ToolResult::ok('Madrid: 21 C, sunny');
            }
        };
    }
}
