<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use PHPUnit\Framework\Attributes\DataProvider;
use Phycode\Cli\ContextReport;
use Phycode\Session\SessionStore;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;

final class RpcSessionCommandsTest extends RpcTestCase
{
    private const REFUSAL = 'a turn is running; try again when it finishes';

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ]]);
    }

    public function testTheReadyEventIsProtocolTwoWithItsCapabilitiesAndSettings(): void
    {
        $ready = self::first($this->play([], new RequestSpy(['local' => 'qwen']))['events'], 'ready');

        self::assertSame(2, $ready['protocol']);
        self::assertSame(
            ['panels', 'models.v2', 'diff', 'ask', 'btw', 'sessions', 'config', 'effort', 'context', 'deepresearch', 'editor', 'vault', 'search.providers'],
            $ready['capabilities'],
        );
        self::assertSame(['phycode', null, null], [$ready['theme'], $ready['editor'], $ready['contextMax']]);
        self::assertSame('local', $ready['app']['profile'], 'the fields of protocol 1 are still there');
        self::assertSame(['/config', '/btw QUESTION', '/deepresearch QUESTION'], array_slice(array_column($ready['commands'], 'name'), -3));
        self::assertContains('/effort [LEVEL]', array_column($ready['commands'], 'name'), 'the session commands come first');
    }

    public function testConfigListsTheItemsAndConfigSetChangesOneOfThem(): void
    {
        $result = $this->play([
            ['type' => 'config'],
            ['type' => 'config_set', 'key' => 'ui.diff_view', 'value' => 'split'],
        ], new RequestSpy(['local' => 'qwen']));

        $configs = self::of($result['events'], 'config');
        self::assertCount(2, $configs);
        self::assertSame(
            ['key' => 'ui.diff_view', 'label' => 'Diff view', 'value' => 'auto', 'kind' => 'choice', 'choices' => ['auto', 'split', 'unified'], 'category' => 'UI'],
            self::item($configs[0]['items'], 'ui.diff_view'),
        );
        self::assertSame(array_column($configs[0]['items'], 'key'), array_column($configs[1]['items'], 'key'), 'the same settings are listed after the change');
        self::assertSame('split', self::item($configs[1]['items'], 'ui.diff_view')['value']);
        self::assertSame('split', json_decode((string) file_get_contents($this->harness->configFile), true, 512, JSON_THROW_ON_ERROR)['ui']['diff_view']);
    }

    public function testTheReadyEventCarriesTheThemeEditorAndMaxOfTheConfigAndTheSessionStartsWithTheMax(): void
    {
        file_put_contents($this->harness->configFile, json_encode(['default_profile' => 'local', 'ui' => ['theme' => 'paper', 'editor' => 'nvim'], 'context' => ['max' => 4000], 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ]], JSON_THROW_ON_ERROR));

        $result = $this->play([['type' => 'session_new']], new RequestSpy(['local' => 'qwen']));

        $ready = self::first($result['events'], 'ready');
        self::assertSame(['paper', 'nvim', 4000], [$ready['theme'], $ready['editor'], $ready['contextMax']]);
        $context = self::first($result['events'], 'context');
        self::assertSame([4000, 3800], [$context['limit'], $context['threshold']], 'the session starts with the max of the config');
    }

    public function testAConfigReadDuringATurnIsAnsweredAndNotRefused(): void
    {
        $research = function (string $question, int $rounds, callable $progress): string {
            $this->send(['type' => 'config']);
            $progress('plan', 'one query', true);
            ($this->endingInput())();

            return 'an answer';
        };

        $result = $this->play([['type' => 'deepresearch', 'question' => 'what changed?']], new RequestSpy(['local' => 'qwen']), null, null, $research);

        self::assertSame(['ready', 'turn_start', 'config', 'progress', 'output', 'turn_end', 'bye'], self::types($result['events']));
        self::assertSame([], self::of($result['events'], 'notice'));
    }

    public function testAConfigSetThatIsRefusedIsANoticeAndTheConfigIsLeftAlone(): void
    {
        $before = file_get_contents($this->harness->configFile);

        $result = $this->play([
            ['type' => 'config_set', 'key' => '', 'value' => 'x'],
            ['type' => 'config_set', 'key' => 'no.such.setting', 'value' => 1],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['ready', 'notice', 'notice', 'bye'], self::types($result['events']));
        self::assertSame('config_set needs a key and a value', $result['events'][1]['message']);
        self::assertSame('error', $result['events'][2]['level']);
        self::assertSame($before, file_get_contents($this->harness->configFile));
    }

    public function testANewSessionIsListedAndIsTheCurrentOneWithItsContext(): void
    {
        $result = $this->play([['type' => 'session_new'], ['type' => 'session_list']], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['ready', 'sessions', 'model_changed', 'effort', 'context', 'sessions', 'bye'], self::types($result['events']));
        $listed = self::of($result['events'], 'sessions');
        self::assertSame($listed[0]['items'], $listed[1]['items'], 'the list is the same when it is asked for again');
        foreach ($listed[0]['items'] as $item) {
            self::assertSame(['id', 'title', 'updatedAt', 'messages', 'current'], array_keys($item));
        }
        self::assertSame(['type', 'used', 'limit', 'threshold', 'percent', 'messages', 'breakdown', 'autoCompact', 'autoCompactSwitch'], array_keys(self::first($result['events'], 'context')), 'the context event has its fields');
    }

    public function testSwitchingToAnUnknownSessionIsANoticeAndTheSessionGoesOn(): void
    {
        $result = $this->play([['type' => 'session_switch', 'id' => 'ses_missing'], ['type' => 'session_switch']], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['ready', 'notice', 'notice', 'bye'], self::types($result['events']));
        self::assertSame(['error', 'error'], array_column(array_slice($result['events'], 1, 2), 'level'));
        self::assertSame('session_switch needs the id of a session', $result['events'][2]['message']);
    }

    public function testEffortIsSetAndAnswersWithItsLevels(): void
    {
        $result = $this->play([['type' => 'set_effort', 'level' => 'high']], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['type' => 'effort', 'profile' => 'local', 'model' => 'qwen', 'level' => 'high', 'levels' => $this->session->effortLevels(), 'accepted' => null, 'note' => $this->session->effortNote()], self::first($result['events'], 'effort'));
    }

    public function testAnEffortLevelThatIsNotKnownIsANoticeAndNothingIsSent(): void
    {
        $result = $this->play([['type' => 'set_effort', 'level' => 'ultra']], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['ready', 'notice', 'bye'], self::types($result['events']));
        self::assertSame('error', $result['events'][1]['level']);
    }

    public function testTheContextMaxIsSetAndAnswersWithTheContext(): void
    {
        $result = $this->play([
            ['type' => 'set_context', 'max' => 4000],
            ['type' => 'set_context', 'max' => null],
            ['type' => 'set_context', 'max' => 100],
        ], new RequestSpy(['local' => 'qwen']));

        $contexts = self::of($result['events'], 'context');
        self::assertSame([4000, null], [$contexts[0]['limit'], $contexts[1]['limit']]);
        self::assertSame([3800, null], [$contexts[0]['threshold'], $contexts[1]['threshold']], 'the threshold is the max less the buffer');
        self::assertCount(2, $contexts, 'a max of 100 is refused');
        self::assertSame('the context max must be off or a number of tokens from 512 to 1000000', self::of($result['events'], 'notice')[0]['message']);
    }

    public function testTheContextMaxIsANumberOfTokensOrNullAndAnotherFieldIsIgnored(): void
    {
        $result = $this->play([
            ['type' => 'set_context', 'max' => 4000, 'mode' => 'drop'],
            ['type' => 'set_context', 'max' => '4000'],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertSame([4000], array_column(self::of($result['events'], 'context'), 'limit'));
        self::assertSame(['set_context needs a max: a number of tokens, or null for the window of the model'], array_column(self::of($result['events'], 'notice'), 'message'));
    }

    public function testTheCapOfProtocolOneIsNoLongerAField(): void
    {
        $result = $this->play([['type' => 'set_context', 'cap' => 4000]], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['set_context needs a max: a number of tokens, or null for the window of the model'], array_column(self::of($result['events'], 'notice'), 'message'));
        self::assertSame([], self::of($result['events'], 'context'));
    }

    public function testTheContextEventCarriesTheBreakdownByCategoryAndWhetherAutocompactionRuns(): void
    {
        $result = $this->play([['type' => 'set_context', 'max' => null]], new RequestSpy(['local' => 'qwen']));

        $context = self::first($result['events'], 'context');
        self::assertSame(ContextReport::CATEGORIES, array_column($context['breakdown'], 'category'));
        self::assertFalse($context['autoCompact'], 'no max is fixed, so nothing compacts on its own (DEC-01)');
        self::assertNull($context['threshold'], 'the window is unknown, so there is no threshold');
    }

    public function testTheFirstContextEventCountsTheSystemPromptAndTheTools(): void
    {
        $result = $this->play([['type' => 'set_context', 'max' => 800]], new RequestSpy(['local' => 'qwen']));

        $context = self::first($result['events'], 'context');
        $categories = array_column($context['breakdown'], 'tokens', 'category');
        self::assertGreaterThan(0, $categories['tools'], 'the tool listing is in the system message, and counted with the tools');
        self::assertSame(array_sum($categories), $context['used'], 'used counts every category of the breakdown');
        self::assertSame(intdiv($context['used'] * 100, 800), $context['percent']);
    }

    public function testAMaxBelowTheFixedTokensGivesAWarnNoticeAndAMaxAboveItDoesNot(): void
    {
        $fixed = self::fixedOf(self::first($this->play([['type' => 'set_context', 'max' => null]], new RequestSpy(['local' => 'qwen']))['events'], 'context'));

        $below = $this->play([['type' => 'set_context', 'max' => 512]], new RequestSpy(['local' => 'qwen']));
        $above = $this->play([['type' => 'set_context', 'max' => $fixed + 1000]], new RequestSpy(['local' => 'qwen']));

        $notice = self::first($below['events'], 'notice');
        self::assertSame('warn', $notice['level']);
        self::assertStringStartsWith('context max of 512 tokens is below the ', $notice['message']);
        self::assertSame(512, self::first($below['events'], 'context')['limit'], 'the value is kept');
        self::assertSame([], self::of($above['events'], 'notice'), 'a max with room above the fixed tokens has no notice');
        self::assertSame($fixed + 1000, self::first($above['events'], 'context')['limit']);
    }

    private static function fixedOf(array $context): int
    {
        $categories = array_column($context['breakdown'], 'tokens', 'category');

        return $categories['system'] + $categories['tools'];
    }

    public static function compacts(): iterable
    {
        yield 'the command' => [['type' => 'compact']];
        yield 'the line' => [['type' => 'input', 'text' => '/compact']];
    }

    #[DataProvider('compacts')]
    public function testCompactIsATurnThatSummarisesTheOlderTurnsAndEndsWithTheContext(array $compact): void
    {
        (new SessionStore($this->harness->cwd))->save([
            'id' => 'ses-three-turns', 'title' => 'three turns', 'createdAt' => SessionStore::now(), 'updatedAt' => SessionStore::now(),
            'profile' => 'local', 'model' => 'qwen', 'thinking' => 'off',
            'messages' => [
                ['role' => 'user', 'content' => 'the first question'],
                ['role' => 'assistant', 'content' => 'the first answer'],
                ['role' => 'user', 'content' => 'the second question'],
                ['role' => 'assistant', 'content' => 'the second answer'],
                ['role' => 'user', 'content' => 'the third question'],
                ['role' => 'assistant', 'content' => 'the third answer'],
            ],
        ]);
        $spy = new RequestSpy(['local' => 'qwen'], [], [$this->endingInput(), ScriptedClient::text('a summary of the first question')]);

        $result = $this->play([['type' => 'session_switch', 'id' => 'ses-three-turns'], $compact], $spy);

        $events = array_values(array_filter($result['events'], static fn (array $event): bool => in_array($event['type'], ['sessions', 'context', 'turn_start', 'turn_end', 'bye'], true)));
        self::assertSame(['sessions', 'context', 'turn_start', 'turn_end', 'context', 'bye'], array_column($events, 'type'));
        self::assertSame('completed', self::first($result['events'], 'turn_end')['status']);
        $panel = self::first($result['events'], 'panel');
        self::assertSame(['compact', 'text'], [$panel['title'], $panel['kind']]);
        self::assertSame(['tokens before', 'tokens after', 'summarised', 'kept'], array_keys(self::fieldsOf($panel['text'])));
        self::assertLessThan(array_search('turn_end', self::types($result['events']), true), array_search('panel', self::types($result['events']), true), 'the panel is part of the turn');
        $summaryRequest = $spy->sent[0]['messages'][1]['content'];
        self::assertStringContainsString('user: the first question', $summaryRequest, 'the older turn is summarised');
        self::assertStringNotContainsString('the third question', $summaryRequest, 'the last two turns are not in the summary');
    }

    public function testAgentSwitchesAnswerWithTheAgentThatIsPrimaryNow(): void
    {
        $result = $this->play([
            ['type' => 'input', 'text' => '/agent plan'],
            ['type' => 'input', 'text' => '/agent nowhere'],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['plan', 'plan'], array_column(self::of($result['events'], 'agent_changed'), 'agent'), 'a refused switch says which agent still is');
        self::assertSame(['info', 'error'], array_column(self::of($result['events'], 'notice'), 'level'));
    }

    public function testReloadReadsTheConfigAgainAndAnswersWithTheCatalogueAndTheSettings(): void
    {
        $config = $this->harness->configFile;
        $result = $this->play([['type' => 'input', 'text' => '/reload']], new RequestSpy(['local' => 'qwen', 'extra' => 'm']), function () use ($config): void {
            $data = json_decode((string) file_get_contents($config), true, 512, JSON_THROW_ON_ERROR);
            $data['profiles']['extra'] = ['base_url' => 'http://127.0.0.1:7', 'model' => 'm', 'tool_mode' => 'text'];
            $data['ui'] = ['theme' => 'nord'];
            file_put_contents($config, json_encode($data, JSON_THROW_ON_ERROR));
        });

        self::assertSame(['info'], array_column(self::of($result['events'], 'notice'), 'level'));
        self::assertContains('extra', array_column(self::first($result['events'], 'models')['providers'], 'profile'));
        $theme = array_values(array_filter(self::first($result['events'], 'config')['items'], static fn (array $item): bool => $item['key'] === 'ui.theme'));
        self::assertSame('nord', $theme[0]['value']);
    }

    public function testReloadWithAConfigThatDoesNotLoadKeepsTheOneInUse(): void
    {
        $config = $this->harness->configFile;
        $result = $this->play([['type' => 'input', 'text' => '/reload']], new RequestSpy(['local' => 'qwen']), function () use ($config): void {
            file_put_contents($config, '{ not json');
        });

        $notice = self::first($result['events'], 'notice');
        self::assertSame('error', $notice['level']);
        self::assertStringStartsWith('the configuration is as it was:', $notice['message']);
        self::assertContains('local', array_column(self::first($result['events'], 'models')['providers'], 'profile'));
    }

    public function testTheEffortLinesAnswerWithTheEffortEventAndNotWithAPanel(): void
    {
        $result = $this->play([
            ['type' => 'input', 'text' => '/effort'],
            ['type' => 'input', 'text' => '/effort high'],
            ['type' => 'input', 'text' => '/effort ultra'],
        ], new RequestSpy(['local' => 'qwen']));

        $efforts = self::of($result['events'], 'effort');
        self::assertSame(['none', 'high', 'high'], array_column($efforts, 'level'), 'a refused level leaves the level in use');
        self::assertSame($this->session->effortLevels(), $efforts[1]['levels']);
        self::assertSame([], self::of($result['events'], 'panel'));
        self::assertSame(['info', 'error'], array_column(self::of($result['events'], 'notice'), 'level'));
    }

    public function testTheContextLinesAnswerWithTheContextEventAndNotWithAPanel(): void
    {
        $result = $this->play([
            ['type' => 'input', 'text' => '/context'],
            ['type' => 'input', 'text' => '/context max 4000'],
            ['type' => 'input', 'text' => '/context max off'],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertSame([null, 4000, null], array_column(self::of($result['events'], 'context'), 'limit'));
        self::assertSame([], self::of($result['events'], 'panel'));
    }

    public function testTheSessionLineAnswersWithTheSessionsAndTheContext(): void
    {
        $result = $this->play([['type' => 'input', 'text' => '/sessions new']], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['ready', 'sessions', 'model_changed', 'effort', 'context', 'bye'], array_values(array_diff(self::types($result['events']), ['notice'])));
        self::assertSame([], self::of($result['events'], 'panel'));
    }

    public function testStateChangingCommandsAreRefusedWhileATurnRuns(): void
    {
        $spy = new RequestSpy(['local' => 'qwen'], [], [
            function (): void {
                $this->send(['type' => 'config_set', 'key' => 'ui.diff_view', 'value' => 'split']);
                $this->send(['type' => 'set_effort', 'level' => 'high']);
                $this->send(['type' => 'session_new']);
                $this->send(['type' => 'set_context', 'max' => 4000]);
                $this->send(['type' => 'compact']);
            },
            $this->endingInput(),
            ScriptedClient::text('answer'),
        ]);

        $result = $this->play([['type' => 'input', 'text' => 'a question']], $spy);

        $notices = self::of($result['events'], 'notice');
        self::assertSame([self::REFUSAL, self::REFUSAL, self::REFUSAL, self::REFUSAL, self::REFUSAL], array_column($notices, 'message'));
        self::assertSame([], self::of($result['events'], 'config'));
        self::assertSame([], self::of($result['events'], 'effort'));
        self::assertSame([], self::of($result['events'], 'sessions'));
        self::assertSame([], self::of($result['events'], 'context'));
    }

    public function testACommandWithoutItsFieldsIsANotice(): void
    {
        $result = $this->play([['type' => 'set_context'], ['type' => 'set_effort', 'level' => ' '], ['type' => 'session_new', 'extra' => true]], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['ready', 'notice', 'notice', 'sessions', 'model_changed', 'effort', 'context', 'bye'], self::types($result['events']));
    }

    public function testAnApiKeyThatIsNotOneLineOrComesWithAnEnvironmentVariableIsRefusedBeforeAnythingIsWritten(): void
    {
        $before = file_get_contents($this->harness->configFile);
        $provider = ['name' => 'remote', 'protocol' => 'openai', 'base_url' => 'http://127.0.0.1:9', 'model' => 'x'];

        $result = $this->play([
            ['type' => 'add_provider', ...$provider, 'api_key_env' => null, 'api_key' => "sk-one\nsk-two"],
            ['type' => 'add_provider', ...$provider, 'api_key_env' => 'PHYCODE_REMOTE_KEY', 'api_key' => 'sk-test-SECRET-123456'],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['ready', 'notice', 'notice', 'bye'], self::types($result['events']));
        self::assertSame('api_key must be the key itself, as one line of text', $result['events'][1]['message']);
        self::assertSame('api_key and api_key_env cannot both be set: a profile has one key source', $result['events'][2]['message']);
        self::assertSame($before, file_get_contents($this->harness->configFile));
        self::assertStringNotContainsString('sk-test-SECRET', json_encode($result['events'], JSON_THROW_ON_ERROR));
    }

    private static function item(array $items, string $key): array
    {
        return array_values(array_filter($items, static fn (array $item): bool => $item['key'] === $key))[0];
    }

    private static function fieldsOf(string $text): array
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
