<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Exec\ProcessRunner;
use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;
use Phycode\Support\StateStore;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use Phycode\Tests\Unit\Cli\DescribingProvider;
use Phycode\Tests\Unit\Gentle\FakeGentleAi;

final class RpcServerTest extends RpcTestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
            'cloud' => ['base_url' => 'https://cloud.example', 'model' => 'gpt', 'tool_mode' => 'text'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new SessionHarness(self::CONFIG);
    }

    public function testTheReadyEventCarriesTheModelTheThinkingLevelAndTheCommands(): void
    {
        $result = $this->play([], new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt']));

        $ready = self::first($result['events'], 'ready');
        self::assertSame(['profile' => 'local', 'model' => 'qwen', 'thinking' => 'none', 'branch' => null], [
            'profile' => $ready['app']['profile'],
            'model' => $ready['app']['model'],
            'thinking' => $ready['app']['thinking'],
            'branch' => $ready['app']['branch'],
        ]);
        self::assertContains('/models', array_column($ready['commands'], 'name'));
        self::assertContains('/skills', array_column($ready['commands'], 'name'));
        self::assertSame(['ready', 'bye'], self::types($result['events']));
        self::assertSame(0, $result['exit']);
    }

    public function testListModelsSendsTheCatalogTheModelInUseAndTheFavoritesAndRecents(): void
    {
        file_put_contents($this->state, json_encode([
            'favorites' => [['profile' => 'local', 'model' => 'mistral']],
            'recents' => [['profile' => 'cloud', 'model' => 'gpt']],
        ], JSON_THROW_ON_ERROR));
        $spy = new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt'], [
            'local' => [new ModelInfo('qwen', 'local', 'openai', 32768, true, ['off', 'on'], true), new ModelInfo('mistral', 'local', 'openai')],
            'cloud' => new LlmException('connection to https://user:hunter2@cloud.example failed'),
        ]);

        $models = self::first($this->play([['type' => 'list_models', 'refresh' => false]], $spy)['events'], 'models');

        self::assertSame([
            ['profile' => 'local', 'model' => 'qwen', 'protocol' => 'openai', 'contextWindow' => 32768, 'tools' => true, 'thinking' => ['off', 'on'], 'thinkingDefault' => 'none', 'loaded' => true, 'error' => null, 'custom' => false, 'kind' => null, 'effortLevels' => []],
            ['profile' => 'local', 'model' => 'mistral', 'protocol' => 'openai', 'contextWindow' => null, 'tools' => null, 'thinking' => [], 'thinkingDefault' => 'none', 'loaded' => null, 'error' => null, 'custom' => false, 'kind' => null, 'effortLevels' => []],
            ['profile' => 'cloud', 'model' => 'gpt', 'protocol' => 'openai', 'contextWindow' => null, 'tools' => null, 'thinking' => [], 'thinkingDefault' => 'none', 'loaded' => null, 'error' => 'connection to https://cloud.example failed', 'custom' => false, 'kind' => null, 'effortLevels' => []],
        ], $models['entries']);
        self::assertSame([
            ['profile' => 'local', 'protocol' => 'openai', 'endpoint' => 'http://127.0.0.1:9', 'count' => 2, 'error' => null, 'refreshing' => false],
            ['profile' => 'cloud', 'protocol' => 'openai', 'endpoint' => 'https://cloud.example', 'count' => 0, 'error' => 'connection to https://cloud.example failed', 'refreshing' => false],
        ], array_map(static fn (array $provider): array => array_diff_key($provider, ['updatedAt' => true]), $models['providers']));
        self::assertNotNull($models['providers'][0]['updatedAt']);
        self::assertNull($models['providers'][1]['updatedAt']);
        self::assertSame(['profile' => 'local', 'model' => 'qwen', 'thinking' => 'none'], $models['current']);
        self::assertSame([['profile' => 'local', 'model' => 'mistral']], $models['favorites']);
        self::assertSame([['profile' => 'cloud', 'model' => 'gpt']], $models['recents']);
    }

    public function testRefreshAsksTheServersAgainAndOtherwiseTheListIsKept(): void
    {
        $spy = new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt']);

        $this->play([
            ['type' => 'list_models', 'refresh' => false],
            ['type' => 'list_models'],
            ['type' => 'list_models', 'refresh' => true],
        ], $spy);

        self::assertSame(['local', 'cloud', 'local', 'cloud'], $spy->listed);
    }

    public function testSetModelChangesTheModelOfTheNextRequestAndLeavesTheConfigAlone(): void
    {
        $before = file_get_contents($this->harness->configFile);
        $spy = new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt'], [], [$this->endingInput(), ScriptedClient::text('answer')]);

        $result = $this->play([
            ['type' => 'set_model', 'profile' => 'local', 'model' => 'mistral', 'thinking' => 'on', 'save' => false],
            ['type' => 'input', 'text' => 'one question'],
        ], $spy);

        self::assertSame([['local', 'mistral', 'medium']], self::requested($spy));
        self::assertSame(
            ['type' => 'model_changed', 'profile' => 'local', 'model' => 'mistral', 'thinking' => 'medium', 'saved' => false],
            self::first($result['events'], 'model_changed'),
        );
        self::assertContains(['type' => 'notice', 'level' => 'info', 'message' => 'prompt cache resets when the model changes'], $result['events']);
        self::assertSame($before, file_get_contents($this->harness->configFile), 'nothing is written without save');
        self::assertFileDoesNotExist($this->harness->configFile . '.bak');
        self::assertSame([['profile' => 'local', 'model' => 'mistral']], (new StateStore($this->state))->recents());
    }

    public function testSetModelWithSaveWritesTheGlobalConfigAndKeepsABackup(): void
    {
        $before = file_get_contents($this->harness->configFile);

        $result = $this->play([['type' => 'set_model', 'profile' => 'cloud', 'model' => 'o4', 'thinking' => 'low', 'save' => true]], new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt']));

        self::assertTrue(self::first($result['events'], 'model_changed')['saved']);
        self::assertSame($before, file_get_contents($this->harness->configFile . '.bak'), 'the file as it was is kept');
        $saved = json_decode((string) file_get_contents($this->harness->configFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['model' => 'o4', 'thinking' => 'low'], array_intersect_key($saved['profiles']['cloud'], ['model' => true, 'thinking' => true]));
        self::assertSame('qwen', $saved['profiles']['local']['model'], 'the other profiles keep their values');
    }

    public function testARefusedSetModelIsANoticeAndTheSessionKeepsItsModel(): void
    {
        $spy = new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt'], [], [$this->endingInput(), ScriptedClient::text('answer')]);

        $result = $this->play([
            ['type' => 'set_model', 'profile' => 'nowhere', 'model' => 'x', 'thinking' => 'off', 'save' => true],
            ['type' => 'set_model', 'profile' => 'local', 'model' => '  ', 'thinking' => 'off', 'save' => false],
            ['type' => 'set_model', 'profile' => 'local', 'model' => 'mistral', 'thinking' => 'ultra', 'save' => false],
            ['type' => 'set_model', 'model' => 'mistral'],
            ['type' => 'input', 'text' => 'question'],
        ], $spy);

        $notices = self::of($result['events'], 'notice');
        self::assertSame(['error', 'error', 'error', 'error'], array_column($notices, 'level'));
        self::assertStringContainsString("unknown profile 'nowhere'", $notices[0]['message']);
        self::assertSame('the model name cannot be empty', $notices[1]['message']);
        self::assertSame('thinking must be none, minimal, low, medium, high, xhigh or max (off and on are also accepted)', $notices[2]['message']);
        self::assertStringContainsString('set_model needs a profile', $notices[3]['message']);
        self::assertSame([], self::of($result['events'], 'model_changed'));
        self::assertSame([['local', 'qwen', 'none']], self::requested($spy));
        self::assertFileDoesNotExist($this->harness->configFile . '.bak');
    }

    public function testAFailedSaveKeepsTheSwitchAndSaysSo(): void
    {
        mkdir($this->harness->configFile . '.bak');
        $before = file_get_contents($this->harness->configFile);

        $result = $this->play([
            ['type' => 'set_model', 'profile' => 'local', 'model' => 'mistral', 'thinking' => 'off', 'save' => true],
        ], new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt']));

        $notices = array_values(array_filter(self::of($result['events'], 'notice'), static fn (array $event): bool => $event['level'] === 'error'));
        self::assertCount(1, $notices);
        $notice = $notices[0];
        self::assertStringStartsWith('the model is set for this session only; saving it failed: cannot write', $notice['message']);
        self::assertStringEndsWith('config.json.bak', $notice['message']);
        self::assertSame($before, file_get_contents($this->harness->configFile), 'the config file is not touched');
        self::assertSame(
            ['type' => 'model_changed', 'profile' => 'local', 'model' => 'mistral', 'thinking' => 'none', 'saved' => false],
            self::first($result['events'], 'model_changed'),
        );
    }

    public function testFavoriteStoresTheModelAndAnswersWithTheModels(): void
    {
        $result = $this->play([
            ['type' => 'favorite', 'profile' => 'local', 'model' => 'mistral', 'on' => true],
            ['type' => 'favorite', 'profile' => 'cloud', 'model' => 'gpt', 'on' => true],
            ['type' => 'favorite', 'profile' => 'local', 'model' => 'mistral', 'on' => false],
            ['type' => 'favorite', 'profile' => 'nowhere', 'model' => 'x', 'on' => true],
            ['type' => 'favorite', 'profile' => 'local', 'model' => 'mistral', 'on' => 'yes'],
        ], new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt']));

        self::assertSame([
            [['profile' => 'local', 'model' => 'mistral']],
            [['profile' => 'local', 'model' => 'mistral'], ['profile' => 'cloud', 'model' => 'gpt']],
            [['profile' => 'cloud', 'model' => 'gpt']],
        ], array_column(self::of($result['events'], 'models'), 'favorites'));
        $notices = self::of($result['events'], 'notice');
        self::assertSame(['error', 'error'], array_column($notices, 'level'));
        self::assertStringContainsString("unknown profile 'nowhere'", $notices[0]['message']);
        self::assertSame('favorite needs a profile, a model and on (true or false)', $notices[1]['message']);
    }

    public function testAModelCommandReadDuringATurnIsRefusedAndChangesNothing(): void
    {
        $spy = new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt'], [], [$this->endingInput(), ScriptedClient::text('answer')]);

        $result = $this->play([
            ['type' => 'input', 'text' => 'question'],
            ['type' => 'set_model', 'profile' => 'cloud', 'model' => 'o4', 'thinking' => 'on', 'save' => true],
            ['type' => 'list_models', 'refresh' => true],
            ['type' => 'favorite', 'profile' => 'cloud', 'model' => 'gpt', 'on' => true],
        ], $spy);

        $refusal = ['type' => 'notice', 'level' => 'warn', 'message' => 'a turn is running; try again when it finishes'];
        self::assertSame(['ready', 'turn_start', 'notice', 'notice', 'notice', 'text', 'turn_end', 'bye'], self::types($result['events']));
        self::assertSame([$refusal, $refusal, $refusal], array_values(self::of($result['events'], 'notice')));
        self::assertSame([], self::of($result['events'], 'model_changed'));
        self::assertSame([], self::of($result['events'], 'models'));
        self::assertSame([['local', 'qwen', 'none']], self::requested($spy));
        self::assertFileDoesNotExist($this->harness->configFile . '.bak');
    }

    public function testAnExceptionInsideATurnEndsThatTurnWithAnErrorStatus(): void
    {
        $spy = new RequestSpy(['local' => 'qwen'], [], [$this->endingInput(), new \RuntimeException('the client broke')]);

        $result = $this->play([['type' => 'input', 'text' => 'question']], $spy);

        self::assertSame(['ready', 'turn_start', 'notice', 'turn_end', 'bye'], self::types($result['events']));
        self::assertSame(['error', 'turn ended (error): the client broke'], [self::first($result['events'], 'notice')['level'], self::first($result['events'], 'notice')['message']]);
        $turnEnd = self::first($result['events'], 'turn_end');
        self::assertSame(['error', 'the client broke'], [$turnEnd['status'], $turnEnd['reason']]);
        self::assertSame(['promptTokens' => null, 'completionTokens' => null, 'tokensPerSecond' => null, 'contextWindow' => 4096], $turnEnd['usage']);
        self::assertSame(['type' => 'bye', 'reason' => 'eof'], $result['events'][4]);
        self::assertSame(0, $result['exit']);
    }

    public function testAFailedModelCallShowsItsReasonAsAnErrorNoticeBeforeTheTurnEnds(): void
    {
        $spy = new RequestSpy(['local' => 'qwen'], [], [$this->endingInput(), new LlmException('connection to the model server failed: refused')]);

        $result = $this->play([['type' => 'input', 'text' => 'question']], $spy);

        self::assertSame(['ready', 'turn_start', 'text', 'notice', 'turn_end', 'bye'], self::types($result['events']));
        $notice = $result['events'][3];
        self::assertSame(['error', 'turn ended (error): connection to the model server failed: refused'], [$notice['level'], $notice['message']]);
        $turnEnd = $result['events'][4];
        self::assertSame(['error', 'connection to the model server failed: refused'], [$turnEnd['status'], $turnEnd['reason']]);
    }

    public function testTheReasonOfAFailedTurnNeverCarriesASecretOrCredentials(): void
    {
        $key = 'sk-test-SECRET-123456';
        $keyFile = $this->harness->home . '/key.txt';
        file_put_contents($keyFile, $key . "\n");
        file_put_contents($this->harness->configFile, json_encode(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'api_key_file' => $keyFile],
        ]], JSON_THROW_ON_ERROR));
        $spy = new RequestSpy(['local' => 'qwen'], [], [
            $this->endingInput(),
            new LlmException("connection to https://user:pass@models.example failed; Authorization: Bearer {$key}; key {$key}"),
        ]);

        $result = $this->play([['type' => 'input', 'text' => 'question']], $spy);

        $turnEnd = self::first($result['events'], 'turn_end');
        self::assertSame('error', $turnEnd['status']);
        self::assertSame('connection to https://models.example failed; Authorization: [redacted]; key [redacted]', $turnEnd['reason']);
        $wire = json_encode($result['events'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($key, $wire);
        self::assertStringNotContainsString('user:pass', $wire);
    }

    public function testSkillsAndModelsAreCommandsAndNotTurns(): void
    {
        $bin = FakeGentleAi::install(['skills' => '[{"name":"api","scope":"project","description":"REST APIs","path":"/p"}]']);
        file_put_contents($this->harness->configFile, json_encode(self::CONFIG + ['gentle_ai' => ['bin' => $bin]], JSON_THROW_ON_ERROR));
        $spy = new RequestSpy(['local' => 'qwen', 'cloud' => 'gpt']);

        $result = $this->play([
            ['type' => 'input', 'text' => '/skills'],
            ['type' => 'input', 'text' => '/models'],
            ['type' => 'input', 'text' => '/help'],
        ], $spy);

        self::assertSame(['ready', 'output', 'panel', 'bye'], self::types($result['events']));
        self::assertStringContainsString('api', $result['events'][1]['text']);
        self::assertStringContainsString('/models', $result['events'][2]['text']);
        self::assertSame([], $spy->sent, 'no model was asked');
    }

    public function testTheTurnEndCarriesTheUsageOfItsLastCallAndTheContextWindow(): void
    {
        $spy = new RequestSpy(['local' => 'qwen'], [], [
            $this->endingInput(),
            new LlmResponse('done', '', [], FinishReason::STOP, new Usage(26, 12, 20, 8.5)),
        ]);

        $result = $this->play([['type' => 'input', 'text' => 'question']], $spy);

        self::assertSame(['promptTokens' => 26, 'completionTokens' => 12, 'tokensPerSecond' => 8.5, 'contextWindow' => 4096], self::first($result['events'], 'turn_end')['usage']);
    }

    public function testTheTurnEndCarriesTheWindowTheServerReportsForTheModel(): void
    {
        file_put_contents($this->harness->configFile, json_encode(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ]], JSON_THROW_ON_ERROR));
        $provider = new DescribingProvider([new ModelInfo('qwen', 'local', 'openai', 32768)], [$this->endingInput(), ScriptedClient::text('done')]);

        $result = $this->play([['type' => 'input', 'text' => 'question']], $provider);

        self::assertSame(['ready', 'turn_start', 'text', 'turn_end', 'bye'], self::types($result['events']));
        self::assertSame(32768, self::first($result['events'], 'turn_end')['usage']['contextWindow'], 'not the default of the config');
    }

    public function testAFailureWhileTheWindowIsReadEndsTheTurnWithAnErrorAndTheSessionGoesOn(): void
    {
        file_put_contents($this->harness->configFile, json_encode(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ]], JSON_THROW_ON_ERROR));
        $provider = new DescribingProvider([new \RuntimeException('the lookup broke')]);

        $result = $this->play([['type' => 'input', 'text' => 'question'], ['type' => 'quit']], $provider);

        self::assertSame(['ready', 'turn_start', 'notice', 'turn_end', 'bye'], self::types($result['events']), 'the window is read once the turn has begun');
        $notice = self::first($result['events'], 'notice');
        self::assertSame(['error', 'turn ended (error): the lookup broke'], [$notice['level'], $notice['message']]);
        $turnEnd = self::first($result['events'], 'turn_end');
        self::assertSame(['error', 'the lookup broke', null], [$turnEnd['status'], $turnEnd['reason'], $turnEnd['usage']['contextWindow']]);
        self::assertSame(['type' => 'bye', 'reason' => 'quit'], $result['events'][4]);
        self::assertSame(0, $result['exit']);
    }

    public function testTheBranchOfAGitProjectIsReportedAtStartAndAfterATurn(): void
    {
        file_put_contents($this->harness->home . '/.gitconfig', "[user]\n\tname = Phycode Test\n\temail = test@example.invalid\n[commit]\n\tgpgsign = false\n");
        $this->git(['init', '-q']);
        $this->git(['symbolic-ref', 'HEAD', 'refs/heads/feature/rpc']);
        $this->git(['commit', '-q', '--allow-empty', '-m', 'first']);
        $spy = new RequestSpy(['local' => 'qwen'], [], [$this->endingInput(), ScriptedClient::text('ok')]);

        $result = $this->play([['type' => 'input', 'text' => 'question']], $spy);

        self::assertSame('feature/rpc', self::first($result['events'], 'ready')['app']['branch']);
        self::assertSame('feature/rpc', self::first($result['events'], 'turn_end')['branch']);
    }

    private function git(array $args): void
    {
        $result = (new ProcessRunner())->run(['git', ...$args], $this->harness->cwd, 30, 65536);
        self::assertTrue($result->succeeded(), implode(' ', $args) . ': ' . $result->stderr);
    }

    private static function requested(RequestSpy $spy): array
    {
        return array_map(static fn (array $request): array => [$request['profile'], $request['model'], $request['thinking']], $spy->sent);
    }
}
