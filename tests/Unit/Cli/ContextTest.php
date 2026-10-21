<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Cli\ContextReport;
use Phycode\Session\SessionStore;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
        ],
    ];

    private const MODELS = ['local' => 'qwen'];

    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testTheContextStateCountsTheConversationAgainstTheWindow(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        $state = $session->contextState();

        self::assertSame([4096, 3896, 1], [$state['limit'], $state['threshold'], $state['messages']]);
        self::assertGreaterThan(0, $state['used']);
        self::assertSame(intdiv($state['used'] * 100, 4096), $state['percent']);
        self::assertTrue($state['estimated'], 'no reply has reported its usage yet');
        self::assertFalse($state['autoCompact'], 'no max is fixed, so nothing compacts on its own (DEC-01)');
    }

    public function testTheUsedTokensCountTheSystemPromptAndTheToolsBeforeAnyReply(): void
    {
        $state = $this->session(new CountingProvider(self::MODELS))->contextState();

        $categories = array_column($state['breakdown'], 'tokens', 'category');
        self::assertGreaterThan(0, $categories['tools'], 'the text-mode tool listing is in the system message');
        self::assertSame(array_sum($categories), $state['used'], 'used is the sum of the categories of the breakdown');
        self::assertSame(intdiv($state['used'] * 100, 4096), $state['percent'], 'the percentage is measured against the same used tokens');
    }

    public function testAMaxBelowTheFixedTokensWarnsAndIsKept(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));
        $fixed = $this->fixedTokens($session);

        $session->setContextMax(512);

        self::assertSame(512, $session->contextState()['limit'], 'the value is kept');
        self::assertSame(
            ['warn: context max of 512 tokens is below the ' . ContextReport::tokensText($fixed) . ' tokens of the system prompt and the tools, with room for a summary: a summary cannot bring the context under the max, so it stays above it. Set context.max to '
                . ContextReport::tokensText((int) ceil(($fixed + 1000) / 1000) * 1000) . ' or more'],
            $this->notices(),
        );
    }

    public function testAMaxOfTheFixedTokensPlusRoomGivesNoWarning(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));
        $fixed = $this->fixedTokens($session);

        $session->setContextMax($fixed + 1000);

        self::assertSame([], $this->notices(), 'at the room, no warning');
    }

    public function testContextMaxCommandWarnsWhenTheMaxIsBelowTheFixedTokens(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        $session->handle('/context max 512');

        self::assertSame(2, count($this->notices()));
        self::assertStringStartsWith('warn: context max of 512 tokens is below the ', $this->notices()[0], 'the warning comes before the confirmation');
        self::assertSame('info: context max is 512 tokens; autocompact runs at 312', $this->notices()[1]);
    }

    private function fixedTokens(ChatSession $session): int
    {
        $categories = array_column($session->contextState()['breakdown'], 'tokens', 'category');

        return $categories['system'] + $categories['tools'];
    }

    public static function thresholds(): iterable
    {
        yield 'the example of the plan: 8000 compacts at 7800' => [8000, 7800];
        yield 'a window under ten thousand keeps the two hundred tokens' => [1000, 800];
        yield 'a larger window keeps two percent of it' => [131072, 128451];
        yield 'the smallest window' => [512, 312];
    }

    #[DataProvider('thresholds')]
    public function testTheThresholdIsTheMaxLessTheBuffer(int $max, int $threshold): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        $session->setContextMax($max);

        self::assertSame([$max, $threshold], [$session->contextState()['limit'], $session->contextState()['threshold']]);
    }

    public function testTheMaxOfTheConfigStartsTheSession(): void
    {
        file_put_contents($this->harness->configFile, json_encode(self::CONFIG + ['context' => ['max' => 8000]], JSON_THROW_ON_ERROR));

        $session = $this->session(new CountingProvider(self::MODELS));

        self::assertSame([8000, 7800], [$session->contextState()['limit'], $session->contextState()['threshold']]);
    }

    public function testTheMaxIsSetAndReturnsToTheWindowOfTheModel(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        $session->setContextMax(8000);
        self::assertSame(8000, $session->contextState()['limit']);
        $session->setContextMax(null);
        self::assertSame(4096, $session->contextState()['limit']);
        self::assertSame('the context max must be off or a number of tokens from 512 to 1000000', self::refusal(fn () => $session->setContextMax(100)));
        self::assertSame(4096, $session->contextState()['limit'], 'a refused max changes nothing');
    }

    public function testTheContextMaxCommandTakesTokensKOrOff(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        $session->handle('/context max 8k');
        self::assertSame(8000, $session->contextState()['limit']);
        self::assertSame('info: context max is 8k tokens; autocompact runs at 7.8k', array_slice($this->notices(), -1)[0]);

        $session->handle('/context max 4000');
        self::assertSame(4000, $session->contextState()['limit']);

        $session->handle('/context max off');
        self::assertSame(4096, $session->contextState()['limit']);
        self::assertSame('info: context max is the window of the model: 4.1k tokens', array_slice($this->notices(), -1)[0], 'no max is fixed, so the notice says no autocompact threshold');
    }

    public function testTheRemovedWordsAndAnUnknownValueAreRefusedAsUnknownArguments(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        $session->handle('/context cap 4000');
        $session->handle('/context 4000');
        $session->handle('/context off');
        $session->handle('/context max 4000 drop');
        $session->handle('/context max 100');
        $session->handle('/context max nope');

        $usage = 'error: usage: /context, or /context max N|Nk|off [--save]';
        self::assertSame(
            [$usage, $usage, $usage, $usage, 'error: the context max must be off or a number of tokens from 512 to 1000000', $usage],
            $this->notices(),
        );
        self::assertSame(4096, $session->contextState()['limit'], 'nothing was set');
    }

    public function testTheContextReportAsksNoModelAndChangesNothing(): void
    {
        $provider = new CountingProvider(self::MODELS);
        $session = $this->session($provider);
        $session->setContextMax(4000);

        $session->handle('/context');

        self::assertSame([], $provider->sent, 'the report asks no model');
        self::assertSame([], $this->notices());
        self::assertSame(4000, $session->contextState()['limit']);
        self::assertSame(1, $session->contextState()['messages']);
    }

    public function testTheMaxSavedWithSaveKeepsTheOtherKeysAndTakesABackup(): void
    {
        file_put_contents($this->harness->configFile, json_encode(self::CONFIG + ['context' => ['buffer' => 500]], JSON_THROW_ON_ERROR));
        $before = file_get_contents($this->harness->configFile);
        $session = $this->session(new CountingProvider(self::MODELS));

        $session->handle('/context max 8000 --save');

        $saved = json_decode((string) file_get_contents($this->harness->configFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['buffer' => 500, 'max' => 8000], $saved['context'], 'the buffer of the config is kept');
        self::assertSame(self::CONFIG['profiles'], $saved['profiles']);
        self::assertSame('local', $saved['default_profile']);
        self::assertSame($before, file_get_contents($this->harness->configFile . '.bak'));
        self::assertSame(['info: context max is 8k tokens; autocompact runs at 7.5k', "info: saved context.max in {$this->harness->configFile}"], array_slice($this->notices(), -2));
        self::assertSame(8000, $session->contextState()['limit']);
    }

    public function testTheMaxOffSavedWritesNull(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        $session->handle('/context max off --save');

        $saved = json_decode((string) file_get_contents($this->harness->configFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('max', $saved['context']);
        self::assertNull($saved['context']['max']);
    }

    public function testASaveThatCannotBeWrittenKeepsTheMaxForTheSession(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));
        unlink($this->harness->configFile);

        $session->handle('/context max 8000 --save');

        self::assertSame(8000, $session->contextState()['limit'], 'the session keeps the max');
        self::assertStringStartsWith('error: the max is set for this session only; saving it failed: cannot read ', array_slice($this->notices(), -1)[0]);
    }

    public function testEveryRequestCarriesTheWholeConversationWithoutAMax(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('one'), ScriptedClient::text('two'), ScriptedClient::text('three')]);
        $session = $this->session($provider);
        $session->handle('first question');
        $session->handle('second question');

        $session->handle('third question');

        self::assertSame(['system', 'user', 'assistant', 'user', 'assistant', 'user'], array_column($provider->sent[2]['messages'], 'role'));
    }

    public function testWithoutAMaxNothingIsCompactedOnItsOwnButCompactStillWorks(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('answer four'), ScriptedClient::text('the summary')]);
        $session = $this->session($provider);
        $session->switchSession($this->saveHistory(self::history()));

        $session->handle('question four');

        self::assertCount(1, $provider->sent, 'the conversation is over the threshold of the window, but no summary is asked for (DEC-01)');
        self::assertCount(8, $provider->sent[0]['messages'], 'the whole conversation is sent');
        self::assertSame([], $this->autocompactNotices());

        $session->compact();

        self::assertCount(2, $provider->sent, 'a manual /compact still asks for the summary');
        self::assertStringContainsString('first', (string) $provider->sent[1]['messages'][1]['content']);
        self::assertStringNotContainsString('third question', (string) $provider->sent[1]['messages'][1]['content'], 'the last quarter of the window is kept');
    }

    public function testAutocompactSummarisesTheOlderTurnsBeforeATurnThatStartsAtTheThreshold(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('the summary'), ScriptedClient::text('answer four')]);
        $session = $this->session($provider);
        $max = $this->systemTokens($session) + 2000;
        $session->setContextMax($max);
        $session->switchSession($this->saveHistory(self::history()));
        $used = $session->contextState()['used'];

        $session->handle('question four');

        self::assertCount(2, $provider->sent, 'the summary is asked for, then the turn');
        self::assertSame(['system', 'user'], array_column($provider->sent[0]['messages'], 'role'));
        self::assertStringContainsString('first', (string) $provider->sent[0]['messages'][1]['content']);
        self::assertStringNotContainsString('third question', (string) $provider->sent[0]['messages'][1]['content'], 'the last turn is kept');
        self::assertSame(['system', 'user', 'user', 'assistant', 'user'], array_column($provider->sent[1]['messages'], 'role'));
        self::assertSame("Summary of the earlier conversation:\nthe summary", $provider->sent[1]['messages'][1]['content']);
        self::assertSame('third question', $provider->sent[1]['messages'][2]['content']);
        self::assertSame(6, $session->contextState()['messages'], 'the system message, the summary, the kept turn and the new one');
        self::assertSame(
            ['info: autocompact: context at ' . ContextReport::tokensText($used) . ' of ' . ContextReport::tokensText($max) . ', older messages summarised'],
            $this->autocompactNotices(),
        );
        self::assertSame('completed', $session->lastStatus());
    }

    public function testAutocompactRunsAfterATurnThatEndsAtTheThreshold(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [
            ScriptedClient::text('one'),
            ScriptedClient::text(str_repeat('y', 16000)),
            ScriptedClient::text('the summary'),
        ]);
        $session = $this->session($provider);
        $session->setContextMax($this->systemTokens($session) + 2000);

        $session->handle('question one');
        self::assertCount(1, $provider->sent, 'the first turn starts far from the threshold');
        $session->handle('question two');

        self::assertCount(3, $provider->sent, 'the second turn, then the summary of the first');
        self::assertSame(['system', 'user'], array_column($provider->sent[2]['messages'], 'role'));
        self::assertStringContainsString('question one', (string) $provider->sent[2]['messages'][1]['content']);
        self::assertStringNotContainsString('question two', (string) $provider->sent[2]['messages'][1]['content']);
        self::assertSame(4, $session->contextState()['messages'], 'the system message, the summary, and the last turn');
        self::assertStringStartsWith('info: autocompact: context at ', array_slice($this->notices(), -1)[0]);
    }

    public function testWithAutocompactOffAConversationOverItsThresholdIsSentWhole(): void
    {
        file_put_contents($this->harness->configFile, json_encode(self::CONFIG + ['context' => ['auto_compact' => false]], JSON_THROW_ON_ERROR));
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('answer four')]);
        $session = $this->session($provider);
        $session->setContextMax($this->systemTokens($session) + 2000);
        $session->switchSession($this->saveHistory(self::history()));

        $session->handle('question four');

        self::assertCount(1, $provider->sent, 'no summary is asked for');
        self::assertSame(['system', 'user', 'assistant', 'user', 'assistant', 'user', 'assistant', 'user'], array_column($provider->sent[0]['messages'], 'role'));
        self::assertSame([], array_values(array_filter($this->notices(), static fn (string $notice): bool => str_contains($notice, 'autocompact'))));
        self::assertSame(9, $session->contextState()['messages']);
        self::assertFalse($session->contextState()['autoCompact']);
    }

    public function testWithNoWindowThereIsNoThresholdAndNothingIsCompacted(): void
    {
        file_put_contents($this->harness->configFile, json_encode(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ]], JSON_THROW_ON_ERROR));
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('answer four')]);
        $session = $this->session($provider);
        $session->switchSession($this->saveHistory(self::history()));

        $session->handle('question four');

        self::assertCount(1, $provider->sent);
        self::assertNull($session->contextState()['threshold']);
    }

    public function testAFailedAutocompactIsAWarningAndTheTurnGoesOn(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('   '), ScriptedClient::text('answer four')]);
        $session = $this->session($provider);
        $session->setContextMax($this->systemTokens($session) + 2000);
        $session->switchSession($this->saveHistory(self::history()));

        $session->handle('question four');

        self::assertContains('warn: autocompact failed: the model gave an empty summary; the conversation is unchanged', $this->notices());
        self::assertCount(2, $provider->sent, 'the summary was asked for, then the turn ran');
        self::assertSame('completed', $session->lastStatus());
        self::assertSame(9, $session->contextState()['messages'], 'the conversation is unchanged, plus the new turn');
    }

    public function testACompactionSummarisesTheTurnsBeforeTheLastQuarterOfTheWindow(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [
            ScriptedClient::text(self::long('answer one')),
            ScriptedClient::text(self::long('answer two')),
            ScriptedClient::text(self::long('answer three')),
            ScriptedClient::text('the summary'),
            ScriptedClient::text('answer four'),
        ]);
        $session = $this->session($provider);
        $session->handle(self::long('question one'));
        $session->handle(self::long('question two'));
        $session->handle(self::long('question three'));

        $session->compact();

        $summaryRequest = (string) $provider->sent[3]['messages'][1]['content'];
        self::assertStringContainsString('question one', $summaryRequest);
        self::assertStringContainsString('question two', $summaryRequest);
        self::assertStringNotContainsString('question three', $summaryRequest, 'the last quarter of the window is kept as it is');
        self::assertSame(4, $session->contextState()['messages'], 'the system message, the summary and the last turn');

        $session->handle('question four');

        $messages = $provider->sent[4]['messages'];
        self::assertSame(['system', 'user', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        self::assertSame("Summary of the earlier conversation:\nthe summary", $messages[1]['content']);
        self::assertStringStartsWith('question three', $messages[2]['content']);
    }

    public function testCompactNeedsOlderTurnsThanTheLastQuarterOfTheWindow(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('one'), ScriptedClient::text('two')]);
        $session = $this->session($provider);
        $session->handle('question one');
        $session->handle('question two');

        self::assertSame('nothing to compact: every turn fits in the last quarter of the window', self::refusal(fn () => $session->compact()));
        self::assertSame(5, $session->contextState()['messages']);
        self::assertCount(2, $provider->sent, 'no summary is asked for');
    }

    public function testAnEmptySummaryLeavesTheConversationAsItWas(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [
            ScriptedClient::text(self::long('one')),
            ScriptedClient::text(self::long('two')),
            ScriptedClient::text(self::long('three')),
            ScriptedClient::text('   '),
        ]);
        $session = $this->session($provider);
        $session->handle(self::long('question one'));
        $session->handle(self::long('question two'));
        $session->handle(self::long('question three'));

        self::assertSame('the model gave an empty summary; the conversation is unchanged', self::refusal(fn () => $session->compact()));
        self::assertSame(7, $session->contextState()['messages']);
    }

    public function testTheCompactCommandSummarisesOrSaysWhyNot(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [
            ScriptedClient::text(self::long('one')),
            ScriptedClient::text(self::long('two')),
            ScriptedClient::text(self::long('three')),
            ScriptedClient::text('the summary'),
        ]);
        $session = $this->session($provider);
        $session->handle('question one');
        $session->handle('question two');

        $session->handle('/compact');
        self::assertSame(['error: compaction failed: nothing to compact: every turn fits in the last quarter of the window'], array_slice($this->notices(), -1));
        self::assertSame('error', $session->lastStatus());

        $session->handle(self::long('question three'));
        $session->handle('/compact');
        self::assertSame(['info: prompt cache resets: the older turns are now one summary'], array_slice($this->notices(), -1));
        self::assertSame(4, $session->contextState()['messages']);
        self::assertSame('completed', $session->lastStatus());
        self::assertSame(['compact'], array_column(array_slice($this->panels(), -1), 'title'));
    }

    public function testTheCompactCommandPanelShowsTheTokensBeforeAndAfter(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [
            ScriptedClient::text(self::long('one')),
            ScriptedClient::text(self::long('two')),
            ScriptedClient::text(self::long('three')),
            ScriptedClient::text('the summary'),
        ]);
        $session = $this->session($provider);
        $session->handle(self::long('question one'));
        $session->handle(self::long('question two'));
        $session->handle(self::long('question three'));

        $session->handle('/compact  keep the file names ');

        $fields = self::fields(array_slice($this->panels(), -1)[0]['text']);
        self::assertSame(['tokens before', 'tokens after', 'summarised', 'kept'], array_keys($fields));
        self::assertGreaterThan((int) $fields['tokens after'], (int) $fields['tokens before']);
        self::assertSame(['4 messages', '2 messages'], [$fields['summarised'], $fields['kept']], 'two older turns, and the last turn');
        self::assertStringContainsString('Focus the summary on this: keep the file names', (string) $provider->sent[3]['messages'][1]['content']);
    }

    public function testTheSummaryAsksForTheFixedSections(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [
            ScriptedClient::text(self::long('one')),
            ScriptedClient::text(self::long('two')),
            ScriptedClient::text(self::long('three')),
            ScriptedClient::text('the summary'),
        ]);
        $session = $this->session($provider);
        $session->handle(self::long('question one'));
        $session->handle(self::long('question two'));
        $session->handle(self::long('question three'));

        $session->compact();

        $system = (string) $provider->sent[3]['messages'][0]['content'];
        self::assertStringContainsString('Goal, Progress, Decisions, Files touched, Pending', $system);
        self::assertSame(['system', 'user'], array_column($provider->sent[3]['messages'], 'role'), 'a request of its own: the summariser, and the older turns as plain text');
    }

    public function testTheSummarySurvivesASessionSwitch(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [
            ScriptedClient::text(self::long('one')),
            ScriptedClient::text(self::long('two')),
            ScriptedClient::text(self::long('three')),
            ScriptedClient::text('the summary'),
            ScriptedClient::text('answer four'),
        ]);
        $session = $this->session($provider);
        $session->handle(self::long('question one'));
        $session->handle(self::long('question two'));
        $session->handle(self::long('question three'));
        $id = $session->listSessions()[0]['id'];
        $session->compact();

        $session->newSession();
        $session->switchSession($id);
        $session->handle('question four');

        self::assertSame(6, $session->contextState()['messages'], 'the system message, the summary, the last turn and the new one');
        self::assertSame("Summary of the earlier conversation:\nthe summary", $provider->sent[4]['messages'][1]['content']);
    }

    public function testASideQuestionCarriesTheLastSixMessagesAndTheSettingsOfTheSession(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [
            ScriptedClient::text('one'),
            ScriptedClient::text('two'),
            ScriptedClient::text('three'),
            ScriptedClient::text('four'),
        ]);
        $session = $this->session($provider);
        $session->handle('first question');
        $session->handle('second question');
        $session->handle('third question');
        $session->handle('fourth question');

        $context = $session->sideContext();

        self::assertSame(
            ['second question', 'two', 'third question', 'three', 'fourth question', 'four'],
            array_column($context['messages'], 'content'),
        );
        self::assertSame(['user', 'assistant', 'user', 'assistant', 'user', 'assistant'], array_column($context['messages'], 'role'));
        self::assertSame(['local', 'qwen', $session->effort()], [$context['profile'], $context['model'], $context['thinking']]);
    }

    private function session(CountingProvider $provider): ChatSession
    {
        return $this->harness->session($this->harness->kernel($provider, $this->ui));
    }

    private function systemTokens(ChatSession $session): int
    {
        return array_sum(array_column($session->contextState()['breakdown'], 'tokens'));
    }

    private function saveHistory(array $messages): string
    {
        (new SessionStore($this->harness->cwd))->save([
            'id' => 'ses-history', 'title' => 'history', 'createdAt' => SessionStore::now(), 'updatedAt' => SessionStore::now(),
            'profile' => 'local', 'model' => 'qwen', 'thinking' => 'off',
            'messages' => $messages,
        ]);

        return 'ses-history';
    }

    private static function history(): array
    {
        $long = str_repeat('x', 6000);

        return [
            ['role' => 'user', 'content' => "first {$long}"],
            ['role' => 'assistant', 'content' => "first answer {$long}"],
            ['role' => 'user', 'content' => "second {$long}"],
            ['role' => 'assistant', 'content' => "second answer {$long}"],
            ['role' => 'user', 'content' => 'third question'],
            ['role' => 'assistant', 'content' => 'third answer'],
        ];
    }

    private static function long(string $label): string
    {
        return "{$label} " . str_repeat('x', 1500);
    }

    private static function refusal(\Closure $action): string
    {
        try {
            $action();
        } catch (PhycodeException $error) {
            return $error->getMessage();
        }
        self::fail('the action was not refused');
    }

    private function panels(): array
    {
        return array_values(array_map(
            static fn (array $event): array => ['title' => $event[1], 'text' => $event[2]],
            array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'panel'),
        ));
    }

    private function notices(): array
    {
        return array_values(array_map(
            static fn (array $event): string => $event[1] . ': ' . $event[2],
            array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice'),
        ));
    }

    private function autocompactNotices(): array
    {
        return array_values(array_filter($this->notices(), static fn (string $notice): bool => str_contains($notice, 'autocompact')));
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
