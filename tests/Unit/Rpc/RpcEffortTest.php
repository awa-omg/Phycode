<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use PHPUnit\Framework\Attributes\DataProvider;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\SessionHarness;

final class RpcEffortTest extends RpcTestCase
{
    private const LEVELS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];
    private const LLAMA_NOTE = 'none turns thinking off; every other level turns it on (enable_thinking)';
    private const OLLAMA_NOTE = 'on ollama, a level the model does not name is sent as the closest name below it, or else the lowest one it names; a model that names no levels takes thinking on for every level but none';
    private const REFUSAL = 'effort must be none, minimal, low, medium, high, xhigh or max';

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
            'named' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'gpt-oss', 'tool_mode' => 'text', 'protocol' => 'ollama'],
            'claude' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'claude-haiku-5-5', 'tool_mode' => 'text', 'protocol' => 'anthropic'],
        ]]);
    }

    public function testSetEffortAnswersWithTheProfileTheModelTheLevelTheLevelsAndTheNoteOfTheProvider(): void
    {
        $result = $this->play([['type' => 'set_effort', 'level' => 'high']], new RequestSpy(['local' => 'qwen']));

        self::assertSame(
            ['type' => 'effort', 'profile' => 'local', 'model' => 'qwen', 'level' => 'high', 'levels' => self::LEVELS, 'accepted' => null, 'note' => self::LLAMA_NOTE],
            self::first($result['events'], 'effort'),
        );
    }

    public function testTheEffortEventNamesTheLevelsThatTheModelTakesAsTheyAre(): void
    {
        $result = $this->play([
            ['type' => 'set_model', 'profile' => 'claude', 'model' => 'claude-haiku-5-5', 'thinking' => 'medium', 'save' => false],
            ['type' => 'set_effort', 'level' => 'minimal'],
        ], new RequestSpy(['local' => 'qwen', 'named' => 'gpt-oss', 'claude' => 'claude-haiku-5-5']));

        self::assertSame(
            [
                'type' => 'effort', 'profile' => 'claude', 'model' => 'claude-haiku-5-5', 'level' => 'minimal', 'levels' => self::LEVELS,
                'accepted' => ['none', 'low', 'medium', 'high', 'xhigh', 'max'],
                'note' => 'claude-haiku-5-5 takes none and low to max: minimal is sent as low; none turns thinking off',
            ],
            self::first($result['events'], 'effort'),
        );
    }

    public static function levelsAndAliases(): iterable
    {
        foreach (self::LEVELS as $level) {
            yield "the level {$level}" => [$level, $level];
        }
        yield 'off is none' => ['off', 'none'];
        yield 'on is medium' => ['on', 'medium'];
    }

    #[DataProvider('levelsAndAliases')]
    public function testEveryLevelAndAliasIsSetAndReportedAsItsLevel(string $sent, string $level): void
    {
        $result = $this->play([['type' => 'set_effort', 'level' => $sent]], new RequestSpy(['local' => 'qwen']));

        self::assertSame($level, self::first($result['events'], 'effort')['level']);
        self::assertSame([], self::of($result['events'], 'notice'));
    }

    public function testALevelThatIsNotOneIsRefusedWithTheListAndTheLevelInUseStays(): void
    {
        $result = $this->play([
            ['type' => 'set_effort', 'level' => 'high'],
            ['type' => 'set_effort', 'level' => 'ultra'],
        ], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['high'], array_column(self::of($result['events'], 'effort'), 'level'), 'the refused level sends no effort event');
        $notices = self::of($result['events'], 'notice');
        self::assertSame(['error'], array_column($notices, 'level'));
        self::assertSame(self::REFUSAL, $notices[0]['message']);
    }

    public function testASetEffortWithNoLevelIsAnErrorNotice(): void
    {
        $result = $this->play([['type' => 'set_effort', 'level' => ' ']], new RequestSpy(['local' => 'qwen']));

        self::assertSame(['ready', 'notice', 'bye'], self::types($result['events']));
        self::assertSame('set_effort needs a level', $result['events'][1]['message']);
    }

    public function testTheNoteOfAnOllamaProfileNamesWhereTheLevelsGo(): void
    {
        $result = $this->play([
            ['type' => 'set_model', 'profile' => 'named', 'model' => null, 'thinking' => 'none', 'save' => false],
            ['type' => 'set_effort', 'level' => 'xhigh'],
        ], new RequestSpy(['local' => 'qwen', 'named' => 'gpt-oss']));

        self::assertSame(
            ['type' => 'effort', 'profile' => 'named', 'model' => 'gpt-oss', 'level' => 'xhigh', 'levels' => self::LEVELS, 'accepted' => null, 'note' => self::OLLAMA_NOTE],
            self::first($result['events'], 'effort'),
        );
    }
}
