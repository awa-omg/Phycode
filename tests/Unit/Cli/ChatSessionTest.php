<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;
use Phycode\Llm\ToolCall;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use Phycode\Tests\Unit\Gentle\FakeGentleAi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChatSessionTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
            'cloud' => ['base_url' => 'https://cloud.example', 'model' => 'gpt', 'tool_mode' => 'text'],
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

    public static function lines(): iterable
    {
        yield '/help' => ['/help', true];
        yield '/status' => ['/status', true];
        yield '/context' => ['/context', true];
        yield '/agents' => ['/agents', true];
        yield '/agent with a name' => ['/agent build', true];
        yield '/agent without a name' => ['/agent', true];
        yield '/models' => ['/models', true];
        yield '/skill' => ['/skill', true];
        yield '/skill with a name' => ['/skill go-testing', true];
        yield '/skills' => ['/skills', true];
        yield '/mcp' => ['/mcp', true];
        yield '/clear' => ['/clear', true];
        yield '/effort' => ['/effort', true];
        yield '/effort with a level' => ['/effort high', true];
        yield '/context max with a number' => ['/context max 4k', true];
        yield '/context max with --save' => ['/context max 4k --save', true];
        yield '/context compact, which is no longer a subcommand' => ['/context compact', true];
        yield 'a word that only starts like /context' => ['/contextual', false];
        yield '/compact' => ['/compact', true];
        yield '/compact with instructions' => ['/compact keep the file names', true];
        yield 'a word that only starts like /compact' => ['/compaction', false];
        yield '/sessions' => ['/sessions', true];
        yield '/sessions switch' => ['/sessions switch 20261019-163512-a1b2c3', true];
        yield '/sessions new' => ['/sessions new', true];
        yield '/init' => ['/init', true];
        yield '/init with words after it' => ['/init now', false];
        yield '/diff' => ['/diff', true];
        yield '/undo' => ['/undo', true];
        yield '/redo' => ['/redo', true];
        yield '/rewind' => ['/rewind', true];
        yield '/rewind with a turn' => ['/rewind 2', true];
        yield 'a word that only starts like /undo' => ['/undos', false];
        yield 'a word that only starts like a command' => ['/skillsaw', false];
        yield '/exit' => ['/exit', true];
        yield '/quit' => ['/quit', true];
        yield 'surrounding spaces' => ['  /models  ', true];
        yield 'a subagent call' => ['@build write the notes', false];
        yield 'an unknown word' => ['/unknown', false];
        yield 'a known word with arguments' => ['/help me', false];
        yield 'plain text' => ['list the files', false];
    }

    #[DataProvider('lines')]
    public function testOnlyTheCommandsAnswerWithoutATurn(string $line, bool $command): void
    {
        self::assertSame($command, $this->session(new RecordingUi(), new RequestSpy(['local' => 'qwen']))->isCommand($line));
    }

    public function testEveryListedCommandIsRecognised(): void
    {
        $session = $this->session(new RecordingUi(), new RequestSpy(['local' => 'qwen']));

        foreach ($session->commands() as $command) {
            self::assertTrue($session->isCommand(str_replace('NAME', 'build', $command['name'])), $command['name']);
        }
        self::assertContains('/models', array_column($session->commands(), 'name'));
        self::assertSame('list models and switch the model for this session', $this->commandDescription('/models'));
    }

    public function testModelsListsEveryModelByProfileAndBadgesTheModelInUse(): void
    {
        $ui = new RecordingUi();
        $spy = new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt'], [
            'local' => [new ModelInfo('qwen', 'local', 'openai', 32768, true, ['off', 'on'], true), new ModelInfo('mistral', 'local', 'openai')],
            'cloud' => new LlmException('connection to https://user:hunter2@cloud.example failed'),
        ]);

        self::assertTrue($this->session($ui, $spy)->handle('/models'));

        [$panel] = $this->listPanels($ui);
        self::assertSame('Models', $panel['title']);
        self::assertSame(['local', 'local', 'cloud'], array_column($panel['items'], 'category'));
        self::assertSame(['qwen', 'mistral', 'gpt'], array_column($panel['items'], 'title'));
        self::assertSame('context 32768  tools yes  thinking off,on  loaded yes', $panel['items'][0]['detail']);
        self::assertSame(['ok', 'ok', 'error'], array_column($panel['items'], 'status'));
        self::assertSame('context -  tools -  thinking -  loaded -', $panel['items'][1]['detail']);
        self::assertSame('connection to https://cloud.example failed', $panel['items'][2]['detail']);
        self::assertSame(['in use'], array_column($panel['items'], 'badge'), 'only the model in use is badged');
        self::assertSame([], $spy->sent, 'listing models starts no turn');
        self::assertSame([], $this->outputs($ui));
    }

    public function testSwitchModelKeepsTheConversationAndTheNextRequestCarriesTheNewModel(): void
    {
        $spy = new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt'], [], [ScriptedClient::text('one'), ScriptedClient::text('two')]);
        $session = $this->session(new RecordingUi(), $spy);

        $session->handle('first question');
        $session->switchModel('local', 'mistral', 'on');
        $session->handle('second question');

        self::assertSame([['local', 'qwen', 'none'], ['local', 'mistral', 'medium']], $this->requestedModels($spy));
        self::assertSame(['system', 'user', 'assistant', 'user'], array_column($spy->sent[1]['messages'], 'role'));
        self::assertSame('one', $spy->sent[1]['messages'][2]['content']);
        self::assertSame(['mistral', 'medium'], [$session->model(), $session->thinking()]);
    }

    public function testSwitchModelToAnotherProfileUsesItsServerAndItsSystemPrompt(): void
    {
        $spy = new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt'], [], [ScriptedClient::text('one'), ScriptedClient::text('two')], ['cloud' => 'none']);
        $ui = new RecordingUi();
        $session = $this->session($ui, $spy);
        $session->handle('first question');
        $before = $spy->sent[0]['messages'][0]['content'];

        $session->switchModel('cloud', null, 'off');
        $session->handle('second question');

        self::assertSame(['cloud', 'gpt', 'none'], [$session->profile(), $session->model(), $session->thinking()]);
        self::assertSame(['local', 'qwen', 'none'], [$spy->sent[0]['profile'], $spy->sent[0]['model'], $spy->sent[0]['thinking']]);
        self::assertSame(['cloud', 'gpt', 'none'], [$spy->sent[1]['profile'], $spy->sent[1]['model'], $spy->sent[1]['thinking']]);
        self::assertNotSame($before, $spy->sent[1]['messages'][0]['content'], 'the system prompt follows the tool mode of the model');
        self::assertContains('warn: profile cloud cannot call tools; the agent runs as a plain chat', $this->notices($ui));
    }

    public function testThePromptCacheNoticeAppearsOnlyWhenTheModelIdChanges(): void
    {
        $ui = new RecordingUi();
        $session = $this->session($ui, new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt']));

        $session->switchModel('local', null, 'on');
        $session->switchModel('local', 'qwen', 'high');
        self::assertSame([], $this->notices($ui));

        $session->switchModel('local', 'mistral', 'high');
        self::assertSame(['info: prompt cache resets when the model changes'], $this->notices($ui));
    }

    public function testAHostedApiHasNoPromptCacheOfOursToResetSoItGetsNoNotice(): void
    {
        $ui = new RecordingUi();
        $session = $this->session($ui, new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt']));

        $session->switchModel('cloud', 'gpt-5', 'off');

        self::assertSame([], $this->notices($ui), 'cloud.example is not on this machine or its network');
    }

    public function testAnUnknownProfileAnEmptyModelOrABadThinkingLevelChangesNothing(): void
    {
        $session = $this->session(new RecordingUi(), new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt']));
        $refusals = [];
        foreach ([['nowhere', null, 'off'], ['local', '  ', 'off'], ['local', 'mistral', 'ultra']] as [$profile, $model, $thinking]) {
            try {
                $session->switchModel($profile, $model, $thinking);
                self::fail("switchModel accepted {$profile}, {$model}, {$thinking}");
            } catch (PhycodeException $error) {
                $refusals[] = $error->getMessage();
            }
        }

        self::assertStringContainsString("unknown profile 'nowhere'", $refusals[0]);
        self::assertSame('the model name cannot be empty', $refusals[1]);
        self::assertSame('thinking must be none, minimal, low, medium, high, xhigh or max (off and on are also accepted)', $refusals[2]);
        self::assertSame(['local', 'qwen', 'none'], [$session->profile(), $session->model(), $session->thinking()]);
    }

    public function testASwitchInsideATurnIsRefused(): void
    {
        $session = null;
        $refusal = null;
        $spy = new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt'], [], [
            static function () use (&$session, &$refusal): void {
                try {
                    $session?->switchModel('cloud', null, 'off');
                } catch (PhycodeException $error) {
                    $refusal = $error->getMessage();
                }
            },
            ScriptedClient::text('done'),
        ]);
        $session = $this->session(new RecordingUi(), $spy);

        $session->handle('a question');

        self::assertSame('a turn is running; try again when it finishes', $refusal);
        self::assertSame(['local', 'qwen'], [$session->profile(), $session->model()]);
    }

    public function testAFailingTurnEndsWithAnErrorAndTheSessionAnswersTheNextLine(): void
    {
        $ui = new RecordingUi();
        $spy = new RequestSpy(['local' => 'qwen'], [], [new \RuntimeException('the reply broke'), ScriptedClient::text('fine')]);
        $session = $this->session($ui, $spy);

        self::assertTrue($session->handle('first'));
        self::assertSame('error', $session->lastStatus());
        self::assertSame('the reply broke', $session->lastReason());
        self::assertSame(['error: turn ended (error): the reply broke'], $this->notices($ui));

        self::assertTrue($session->handle('second'));
        self::assertSame('completed', $session->lastStatus());
    }

    public function testAFailedModelCallEndsTheTurnWithAnErrorNotice(): void
    {
        $ui = new RecordingUi();
        $spy = new RequestSpy(['local' => 'qwen'], [], [new LlmException('connection to the model server failed: refused')]);
        $session = $this->session($ui, $spy);

        self::assertTrue($session->handle('question'));

        self::assertSame('error', $session->lastStatus());
        self::assertSame(['error: turn ended (error): connection to the model server failed: refused'], $this->notices($ui));
    }

    public function testAnAgentNameThatIsTheKeyIsNotEchoedBack(): void
    {
        $key = 'fakekey-7f3a9c2e51b8d4';
        $keyFile = $this->harness->home . '/key.txt';
        file_put_contents($keyFile, $key);
        file_put_contents($this->harness->configFile, json_encode(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'api_key_file' => $keyFile],
        ]], JSON_THROW_ON_ERROR));
        $ui = new RecordingUi();
        $session = $this->session($ui, new RequestSpy(['local' => 'qwen']));

        self::assertTrue($session->handle("/agent {$key}"));

        $notices = $this->notices($ui);
        self::assertCount(1, $notices);
        self::assertStringStartsWith("error: no primary agent named '[redacted]'", $notices[0]);
    }

    public function testTheUsageOfTheLastModelCallIsKeptForTheTurn(): void
    {
        $spy = new RequestSpy(['local' => 'qwen'], [], [new LlmResponse('done', '', [], FinishReason::STOP, new Usage(26, 12, 20, 8.0))]);
        $session = $this->session(new RecordingUi(), $spy);

        $session->handle('question');

        self::assertSame([26, 12, 8.0], [$session->lastUsage()?->promptTokens, $session->lastUsage()?->completionTokens, $session->lastUsage()?->tokensPerSecond]);
        self::assertSame(4096, $session->contextWindow());
        $session->handle('/help');
        self::assertNull($session->lastUsage(), 'a command ends with no usage');
    }

    public function testADirectCallCarriesTheUsageOfTheSubagentsLastModelCall(): void
    {
        $spy = new RequestSpy(['local' => 'qwen'], [], [
            new LlmResponse('', '', [new ToolCall('call_1', 'list', '{"path":"."}')], FinishReason::TOOL_CALLS, new Usage(7, 3)),
            new LlmResponse('Nothing to report yet.', '', [], FinishReason::STOP, new Usage(26, 12, 20, 8.0)),
        ], ['local' => 'native']);
        $session = $this->session(new RecordingUi(), $spy);

        self::assertTrue($session->handle('@explore Find where the config is read in this project.'));

        self::assertSame('completed', $session->lastStatus());
        self::assertSame([26, 12, 8.0], [$session->lastUsage()?->promptTokens, $session->lastUsage()?->completionTokens, $session->lastUsage()?->tokensPerSecond]);
        self::assertCount(2, $spy->sent, 'the subagent made two model calls');
    }

    public function testAnInterruptedDirectCallEndsCancelledAndAFailingOneEndsWithItsReason(): void
    {
        $session = null;
        $spy = new RequestSpy(['local' => 'qwen'], [], [
            static function () use (&$session): void {
                $session?->interrupt();
            },
            new LlmException('cancelled: interrupted by the user'),
            new LlmException('connection to the model server failed: refused'),
        ], ['local' => 'native']);
        $session = $this->session(new RecordingUi(), $spy);

        self::assertTrue($session->handle('@explore Find where the config is read in this project.'));
        self::assertSame('cancelled', $session->lastStatus());
        self::assertSame('interrupted by the user', $session->lastReason());

        self::assertTrue($session->handle('@explore Find where the config is read in this project.'));
        self::assertSame('error', $session->lastStatus());
        self::assertSame('connection to the model server failed: refused', $session->lastReason());
    }

    public function testAgentWithoutANameGivesTheUsage(): void
    {
        $ui = new RecordingUi();
        $spy = new RequestSpy(['local' => 'qwen']);
        $session = $this->session($ui, $spy);

        self::assertTrue($session->handle('/agent'));

        self::assertSame(['error: usage: /agent NAME'], $this->notices($ui));
        self::assertSame([], $spy->sent);
    }

    public function testSkillsWithABinaryTheConfigNamesWronglyIsANoticeAndTheSessionGoesOn(): void
    {
        file_put_contents($this->harness->configFile, json_encode(self::CONFIG + ['gentle_ai' => ['bin' => '/nonexistent/phycode-gentle-ai']], JSON_THROW_ON_ERROR));
        $ui = new RecordingUi();
        $session = $this->session($ui, new RequestSpy(['local' => 'qwen']));

        self::assertTrue($session->handle('/skills'));

        self::assertSame(['error: gentle_ai.bin is not an executable file: /nonexistent/phycode-gentle-ai'], $this->notices($ui));
        self::assertSame([], $this->outputs($ui));
    }

    public function testTheSkillsGentleAiListsAreRedactedLikeAnyOtherMessage(): void
    {
        $key = 'fakekey-7f3a9c2e51b8d4';
        $keyFile = $this->harness->home . '/key.txt';
        file_put_contents($keyFile, $key);
        $bin = FakeGentleAi::install(['skills' => json_encode([[
            'name' => 'api',
            'scope' => 'project',
            'description' => "sends Bearer tok-SECRET-123 and {$key}",
            'path' => '/p/api/SKILL.md',
        ]], JSON_THROW_ON_ERROR)]);
        file_put_contents($this->harness->configFile, json_encode(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'api_key_file' => $keyFile],
        ], 'gentle_ai' => ['bin' => $bin]], JSON_THROW_ON_ERROR));
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui, new RequestSpy(['local' => 'qwen']))->handle('/skills'));

        $output = $this->outputs($ui);
        self::assertCount(1, $output);
        self::assertStringContainsString('sends Bearer [redacted] and [redacted]', $output[0]);
        self::assertStringNotContainsString('tok-SECRET-123', $output[0]);
        self::assertStringNotContainsString($key, $output[0]);
    }

    private function session(RecordingUi $ui, RequestSpy $spy): ChatSession
    {
        return $this->harness->session($this->harness->kernel($spy, $ui));
    }

    private function commandDescription(string $name): string
    {
        foreach ($this->session(new RecordingUi(), new RequestSpy(['local' => 'qwen']))->commands() as $command) {
            if ($command['name'] === $name) {
                return $command['description'];
            }
        }
        self::fail("no command {$name}");
    }

    private function outputs(RecordingUi $ui): array
    {
        return array_values(array_map(
            static fn (array $event): string => (string) $event[1],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'output'),
        ));
    }

    private function listPanels(RecordingUi $ui): array
    {
        return array_values(array_map(
            static fn (array $event): array => ['title' => $event[1], 'items' => $event[2], 'hint' => $event[3]],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'list_panel'),
        ));
    }

    private function notices(RecordingUi $ui): array
    {
        return array_values(array_map(
            static fn (array $event): string => $event[1] . ': ' . $event[2],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'notice'),
        ));
    }

    private function requestedModels(RequestSpy $spy): array
    {
        return array_map(static fn (array $request): array => [$request['profile'], $request['model'], $request['thinking']], $spy->sent);
    }
}
