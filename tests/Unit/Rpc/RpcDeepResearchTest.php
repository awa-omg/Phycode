<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\Attributes\DataProvider;

final class RpcDeepResearchTest extends RpcTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ]]);
    }

    public function testTheStepsAreProgressEventsAndTheAnswerWithItsSourcesIsOutput(): void
    {
        $asked = [];
        $research = function (string $question, int $rounds, callable $progress) use (&$asked): string {
            $asked[] = [$question, $rounds];
            $progress('plan', 'two queries', false);
            $progress('write', 'the answer', true);
            ($this->endingInput())();

            return "the answer\n\nSources:\n- https://example.test/a";
        };

        $result = $this->play(
            [['type' => 'deepresearch', 'question' => ' what changed? ', 'rounds' => 3]],
            new RequestSpy(['local' => 'qwen']),
            null,
            null,
            $research,
        );

        self::assertSame([['what changed?', 3]], $asked);
        self::assertSame(['ready', 'turn_start', 'progress', 'progress', 'output', 'turn_end', 'bye'], self::types($result['events']));
        self::assertSame(
            ['type' => 'progress', 'id' => 'r1', 'step' => 'plan', 'detail' => 'two queries', 'done' => false],
            $result['events'][2],
        );
        self::assertSame(['type' => 'progress', 'id' => 'r1', 'step' => 'write', 'detail' => 'the answer', 'done' => true], $result['events'][3]);
        self::assertSame(['type' => 'output', 'text' => "the answer\n\nSources:\n- https://example.test/a"], $result['events'][4]);
        self::assertSame(['completed', null], [$result['events'][5]['status'], $result['events'][5]['reason']]);
    }

    public function testTheTypedLineRunsTheResearchWithTwoRoundsByDefault(): void
    {
        $rounds = null;
        $research = function (string $question, int $count, callable $progress) use (&$rounds): string {
            $rounds = $count;
            ($this->endingInput())();

            return 'an answer';
        };

        $result = $this->play([['type' => 'input', 'text' => '/deepresearch what changed?']], new RequestSpy(['local' => 'qwen']), null, null, $research);

        self::assertSame(2, $rounds);
        self::assertSame(['ready', 'turn_start', 'output', 'turn_end', 'bye'], self::types($result['events']));
    }

    public function testAResearchThatFailsEndsItsTurnWithAnErrorAndTheSessionGoesOn(): void
    {
        $research = function (): string {
            ($this->endingInput())();

            throw new \RuntimeException('the search engine did not answer');
        };

        $result = $this->play([['type' => 'deepresearch', 'question' => 'what changed?']], new RequestSpy(['local' => 'qwen']), null, null, $research);

        self::assertSame(['ready', 'turn_start', 'notice', 'turn_end', 'bye'], self::types($result['events']));
        self::assertSame(['error', 'turn ended (error): the search engine did not answer'], [$result['events'][2]['level'], $result['events'][2]['message']]);
        self::assertSame(['error', 'the search engine did not answer'], [$result['events'][3]['status'], $result['events'][3]['reason']]);
    }

    public function testAnInterruptStopsTheResearchAtItsNextStep(): void
    {
        $research = function (string $question, int $rounds, callable $progress): string {
            $progress('plan', 'two queries', false);
            $this->send(['type' => 'interrupt']);
            ($this->endingInput())();
            $progress('fetch', 'page 1', false);

            return 'never delivered';
        };

        $result = $this->play([['type' => 'deepresearch', 'question' => 'what changed?']], new RequestSpy(['local' => 'qwen']), null, null, $research);

        self::assertSame(['ready', 'turn_start', 'progress', 'progress', 'turn_end', 'bye'], self::types($result['events']));
        self::assertSame(['cancelled', null], [$result['events'][4]['status'], $result['events'][4]['reason']]);
        self::assertSame([], self::of($result['events'], 'output'));
    }

    public static function badRounds(): iterable
    {
        yield 'none' => [0];
        yield 'too many' => [6];
        yield 'a text' => ['2'];
        yield 'a fraction' => [2.5];
    }

    #[DataProvider('badRounds')]
    public function testRoundsOutsideOneToFiveAreRefusedBeforeAnyWork(mixed $rounds): void
    {
        $calls = 0;
        $research = function () use (&$calls): string {
            $calls++;

            return 'never';
        };

        $result = $this->play([['type' => 'deepresearch', 'question' => 'what changed?', 'rounds' => $rounds], ['type' => 'quit']], new RequestSpy(['local' => 'qwen']), null, null, $research);

        self::assertSame(['ready', 'notice', 'bye'], self::types($result['events']));
        self::assertSame(['error', 'deepresearch needs a question, and rounds from 1 to 5'], [$result['events'][1]['level'], $result['events'][1]['message']]);
        self::assertSame(0, $calls);
    }
}
