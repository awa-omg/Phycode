<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\Application;
use Phycode\Cli\ChatSession;
use Phycode\Cli\ContextReport;
use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\LlmResponse;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use Phycode\Tests\Unit\Mcp\FakeMcpServer;
use PHPUnit\Framework\TestCase;

final class PanelCommandsTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
        ],
    ];

    private SessionHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testHelpIsAPanelThatListsEveryCommandAndTheSubagentCall(): void
    {
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui, new RequestSpy(['local' => 'qwen']))->handle('/help'));

        $panel = $this->textPanels($ui)[0];
        self::assertSame('Help', $panel['title']);
        foreach (['/help', '/status', '/context', '/context max N|Nk|off [--save]', '/compact [INSTRUCTIONS]', '/effort [LEVEL]', '/agents', '/agent NAME', '/models', '/skill [NAME]', '/skills', '/mcp', '/sessions [switch ID|new]', '/init', '/diff', '/undo', '/redo', '/rewind TURN', '/clear', '/exit', '@NAME TASK'] as $line) {
            self::assertStringContainsString($line, $panel['text']);
        }
        self::assertSame([], $this->outputs($ui), 'help is a panel, not plain output');
    }

    public function testAgentsIsAPanel(): void
    {
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui, new RequestSpy(['local' => 'qwen']))->handle('/agents'));

        $panel = $this->textPanels($ui)[0];
        self::assertSame('Agents', $panel['title']);
        self::assertStringContainsString('build', $panel['text']);
        self::assertSame([], $this->outputs($ui));
    }

    public function testStatusShowsTheSettingsTheSessionRunsWith(): void
    {
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui, new RequestSpy(['local' => 'qwen']))->handle('/status'));

        $panel = $this->textPanels($ui)[0];
        self::assertSame('Status', $panel['title']);
        self::assertSame([
            'version' => Application::VERSION,
            'profile' => 'local',
            'model' => 'qwen',
            'protocol' => 'openai',
            'thinking' => 'none',
            'tool mode' => 'text',
            'sandbox' => 'host',
            'permissions' => 'ask',
            'cwd' => (string) realpath($this->harness->cwd),
            'transcript' => 'memory',
        ], self::fields($panel['text']));
        self::assertSame([], $this->outputs($ui));
    }

    public function testStatusAndContextStartNoTurnAndAskNoModel(): void
    {
        $spy = new RequestSpy(['local' => 'qwen']);
        $ui = new RecordingUi();
        $session = $this->session($ui, $spy);

        $session->handle('/status');
        $session->handle('/context');

        self::assertSame([], $spy->sent);
        self::assertSame([], $this->notices($ui));
        self::assertNull($session->lastStatus(), 'a command is not a turn');
    }

    public function testContextSummarisesTheUsedTokensTheThresholdAndTheBreakdown(): void
    {
        $ui = new RecordingUi();
        $spy = new RequestSpy(['local' => 'qwen'], [], [new LlmResponse('hi there', '', [], FinishReason::STOP, new Usage(26, 12, null, 8.5))]);
        $session = $this->session($ui, $spy);
        $session->setContextMax(4096);
        $session->handle('hello');

        $session->handle('/context');

        $system = (string) $spy->sent[0]['messages'][0]['content'];
        $panel = $this->textPanels($ui);
        self::assertSame('Context', $panel[0]['title']);
        $fields = self::fields($panel[0]['text']);
        self::assertSame(['used', 'autocompact', 'conversation', 'system', 'tools', 'messages', 'tool results', 'summaries'], array_keys($fields));
        self::assertMatchesRegularExpression('/^\d\.\dk of 4\.1k tokens, \d{2}%$/', $fields['used'], 'the system prompt and the tools are counted too, so the used tokens are above the 38 the reply reported');
        self::assertSame('at 3.9k, on', $fields['autocompact']);
        self::assertSame('3 messages', $fields['conversation'], 'the system message, the question and the answer');
        self::assertSame('3 tokens', $fields['messages'], 'the question and the answer: 13 characters');
        self::assertSame(['0 tokens', '0 tokens'], [$fields['tool results'], $fields['summaries']]);
        $breakdown = $session->contextState()['breakdown'];
        self::assertSame(ContextReport::CATEGORIES, array_column($breakdown, 'category'));
        self::assertSame(ContextReport::tokens($system), $breakdown[0]['tokens'] + $breakdown[1]['tokens'], 'the system and the tools make up the system message');
    }

    public function testTheTokensAfterTheLastReplyStayInTheUsedRowAfterOtherCommands(): void
    {
        $ui = new RecordingUi();
        $spy = new RequestSpy(['local' => 'qwen'], [], [new LlmResponse('done', '', [], FinishReason::STOP, new Usage(7, 3))]);
        $session = $this->session($ui, $spy);
        $session->handle('question');
        $session->handle('/status');

        $session->handle('/context');

        self::assertMatchesRegularExpression('/^\d\.\dk of 4\.1k tokens, \d{2}%$/', self::fields($this->textPanels($ui)[1]['text'])['used'], 'the categories are above the 10 the reply reported');
    }

    public function testContextBeforeAnyReplyIsAnEstimateOfTheSystemPrompt(): void
    {
        $ui = new RecordingUi();

        $this->session($ui, new RequestSpy(['local' => 'qwen']))->handle('/context');

        $fields = self::fields($this->textPanels($ui)[0]['text']);
        self::assertSame('1 message', $fields['conversation']);
        self::assertStringEndsWith(' (estimated)', $fields['used']);
    }

    public function testTheWindowIsUnknownWhenNeitherTheConfigNorTheServerGivesOne(): void
    {
        file_put_contents($this->harness->configFile, json_encode([
            'default_profile' => 'local',
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text']],
        ], JSON_THROW_ON_ERROR));
        $ui = new RecordingUi();

        $this->session($ui, new RequestSpy(['local' => 'qwen']))->handle('/context');

        $fields = self::fields($this->textPanels($ui)[0]['text']);
        self::assertStringEndsWith(' tokens, the window is unknown (estimated)', $fields['used']);
        self::assertSame('none: the window is unknown', $fields['autocompact']);
    }

    public function testMcpWithNoServersSaysWhereToConfigureThem(): void
    {
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui, new RequestSpy(['local' => 'qwen']))->handle('/mcp'));

        $panel = $this->textPanels($ui)[0];
        self::assertSame('MCP servers', $panel['title']);
        self::assertStringContainsString('"mcp_servers"', $panel['text']);
        self::assertStringContainsString($this->harness->configFile, $panel['text']);
        self::assertSame([], $this->listPanels($ui));
    }

    public function testMcpListsTheToolsOfAWorkingServerAndTheReasonForAFailingOne(): void
    {
        file_put_contents($this->harness->configFile, json_encode(self::CONFIG + ['mcp_servers' => [
            'fake' => ['command' => FakeMcpServer::argv()],
            'missing' => ['command' => ['/nonexistent/phycode-mcp']],
            'off' => ['command' => ['/nonexistent/phycode-mcp'], 'enabled' => false],
        ]], JSON_THROW_ON_ERROR));
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui, new RequestSpy(['local' => 'qwen']))->handle('/mcp'));

        $list = $this->listPanels($ui)[0];
        self::assertSame('MCP servers', $list['title']);
        self::assertSame(['fake', 'missing', 'off'], array_column($list['items'], 'category'));
        self::assertSame(['ok'], array_column(array_filter($list['items'], static fn (array $item): bool => $item['category'] === 'fake'), 'status'));
        self::assertSame(['echo'], array_column(array_filter($list['items'], static fn (array $item): bool => $item['category'] === 'fake'), 'title'));
        self::assertSame(['error', 'off'], array_column(array_slice($list['items'], 1), 'status'));
        self::assertStringContainsString('cannot start /nonexistent/phycode-mcp', $list['items'][1]['detail']);
        self::assertStringContainsString('servers start for this list and stop again', $list['hint']);
        self::assertSame([], $this->outputs($ui));
    }

    private function session(RecordingUi $ui, RequestSpy $spy): ChatSession
    {
        return $this->harness->session($this->harness->kernel($spy, $ui));
    }

    private function textPanels(RecordingUi $ui): array
    {
        return array_values(array_map(
            static fn (array $event): array => ['title' => $event[1], 'text' => $event[2]],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'panel'),
        ));
    }

    private function listPanels(RecordingUi $ui): array
    {
        return array_values(array_map(
            static fn (array $event): array => ['title' => $event[1], 'items' => $event[2], 'hint' => $event[3]],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'list_panel'),
        ));
    }

    private function outputs(RecordingUi $ui): array
    {
        return array_values(array_map(
            static fn (array $event): string => (string) $event[1],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'output'),
        ));
    }

    private function notices(RecordingUi $ui): array
    {
        return array_values(array_map(
            static fn (array $event): string => $event[1] . ': ' . $event[2],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'notice'),
        ));
    }

    private static function fields(string $text): array
    {
        $fields = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(.+?)\s{2,}(.*)$/', $line, $match) === 1) {
                $fields[$match[1]] = $match[2];
            }
        }

        return $fields;
    }
}
