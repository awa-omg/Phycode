<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EffortCommandTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
            'plain' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'llama', 'tool_mode' => 'text', 'context_limit' => 4096],
            'named' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'gpt-oss', 'tool_mode' => 'text', 'context_limit' => 4096, 'protocol' => 'ollama'],
        ],
    ];

    private const MODELS = ['local' => 'qwen', 'plain' => 'llama', 'named' => 'gpt-oss'];

    private const LEVELS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

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

    public function testTheLevelsAreTheCanonicalListWhateverTheModelReports(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS, levels: ['local' => ['high', 'off']]));

        self::assertSame(self::LEVELS, $session->effortLevels());
    }

    public function testTheLevelSetIsSentWithTheNextRequest(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('ok')]);
        $session = $this->session($provider);

        $session->setEffort('low');
        $session->handle('a question');

        self::assertSame('low', $session->effort());
        self::assertSame('low', $provider->sent[0]['thinking']);
    }

    public static function valuesAndLevels(): iterable
    {
        foreach (self::LEVELS as $level) {
            yield "the level {$level}" => [$level, $level];
        }
        yield 'off is none' => ['off', 'none'];
        yield 'on is medium' => ['on', 'medium'];
    }

    #[DataProvider('valuesAndLevels')]
    public function testEveryLevelAndAliasIsSetForTheSession(string $value, string $level): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        $session->setEffort($value);

        self::assertSame($level, $session->effort());
    }

    public function testAnUnknownLevelIsRefusedWithTheList(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        self::assertSame('effort must be none, minimal, low, medium, high, xhigh or max', self::refusal(fn () => $session->setEffort('ultra')));
        self::assertSame('none', $session->effort());
    }

    public function testALevelIsAcceptedOnAModelThatNamesNoLevels(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));
        $session->switchModel('plain', null, 'none');

        $session->setEffort('max');

        self::assertSame('max', $session->effort(), 'the mapping falls a level to what the model takes; the session keeps the level');
    }

    public function testTheLevelCannotChangeDuringATurn(): void
    {
        $session = null;
        $refusal = null;
        $provider = new CountingProvider(self::MODELS, replies: [
            static function () use (&$session, &$refusal): void {
                try {
                    $session?->setEffort('low');
                } catch (PhycodeException $error) {
                    $refusal = $error->getMessage();
                }
            },
            ScriptedClient::text('done'),
        ]);
        $session = $this->session($provider);

        $session->handle('a question');

        self::assertSame('a turn is running; try again when it finishes', $refusal);
        self::assertSame('none', $session->effort());
    }

    public function testTheEffortPanelShowsTheLevelTheLevelsAndTheNoteOfTheProvider(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        self::assertTrue($session->handle('/effort'));

        [$panel] = $this->panels();
        self::assertSame('Effort', $panel['title']);
        self::assertSame(
            ['effort' => 'none', 'levels' => 'none, minimal, low, medium, high, xhigh, max', 'note' => 'none turns thinking off; every other level turns it on (enable_thinking)'],
            self::fields($panel['text']),
        );
    }

    public function testTheNoteOfAnOllamaProfileNamesWhereTheLevelsGo(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));
        $session->switchModel('named', null, 'none');

        $session->handle('/effort');

        self::assertSame(
            'on ollama, a level the model does not name is sent as the closest name below it, or else the lowest one it names; a model that names no levels takes thinking on for every level but none',
            self::fields($this->panels()[0]['text'])['note'],
        );
    }

    public function testSettingAnEffortInTheLineChatSaysWhenItApplies(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        $session->handle('/effort high');
        $session->handle('/effort off');
        $session->handle('/effort ultra');

        self::assertSame('none', $session->effort());
        self::assertSame(
            [
                'info: effort is now high; it applies to the next turn',
                'info: effort is now none; it applies to the next turn',
                'error: effort must be none, minimal, low, medium, high, xhigh or max',
            ],
            $this->notices(),
        );
    }

    public function testTheEffortCommandIsACommandAndNotATurn(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));

        self::assertTrue($session->isCommand('/effort'));
        self::assertTrue($session->isCommand('/effort high'));
    }

    public function testTheDefaultEffortOfTheConfigStartsTheSessionWithoutProbingTheServer(): void
    {
        file_put_contents($this->harness->configFile, json_encode(self::CONFIG + ['effort' => ['default' => 'low']], JSON_THROW_ON_ERROR));
        $provider = new CountingProvider(self::MODELS);

        $session = $this->session($provider);

        self::assertSame('low', $session->effort());
        self::assertSame(0, $provider->probes, 'the session starts without asking the server');
        self::assertSame([], $provider->sent);
    }

    public function testTheThinkingOfTheProfileStandsWhenTheConfigSetsNoEffort(): void
    {
        $config = self::CONFIG;
        $config['profiles']['local']['thinking'] = 'high';
        file_put_contents($this->harness->configFile, json_encode($config, JSON_THROW_ON_ERROR));

        self::assertSame('high', $this->session(new CountingProvider(self::MODELS))->effort());
    }

    public function testAProfileThatSetsTheOldOnStartsAtMedium(): void
    {
        $config = self::CONFIG;
        $config['profiles']['local']['thinking'] = 'on';
        file_put_contents($this->harness->configFile, json_encode($config, JSON_THROW_ON_ERROR));

        self::assertSame('medium', $this->session(new CountingProvider(self::MODELS))->effort());
    }

    private function session(CountingProvider $provider): ChatSession
    {
        return $this->harness->session($this->harness->kernel($provider, $this->ui));
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
