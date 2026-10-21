<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Btw\BtwJobs;
use Phycode\Tests\Support\FakeModel;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;

final class RpcBtwTest extends RpcTestCase
{
    private ?FakeModel $model = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new SessionHarness(self::config('http://127.0.0.1:9'));
    }

    protected function tearDown(): void
    {
        $this->model?->stop();
        parent::tearDown();
    }

    public function testTheAnswerToASideQuestionIsAPanelTitledBtw(): void
    {
        $result = $this->play([['type' => 'btw', 'question' => 'what is two plus two?']], new RequestSpy(['local' => 'qwen']), null, self::fake());

        self::assertSame(['ready', 'panel', 'bye'], self::types($result['events']));
        self::assertSame(['type' => 'panel', 'title' => 'btw', 'kind' => 'text'], array_intersect_key($result['events'][1], ['type' => true, 'title' => true, 'kind' => true]));
        self::assertStringStartsWith('answer to: what is two plus two? (', $result['events'][1]['text']);
    }

    public function testASideQuestionTypedAsALineIsAnsweredLikeTheCommand(): void
    {
        $result = $this->play([['type' => 'input', 'text' => '/btw what is two plus two?']], new RequestSpy(['local' => 'qwen']), null, self::fake());

        self::assertSame(['ready', 'panel', 'bye'], self::types($result['events']));
        self::assertSame('btw', $result['events'][1]['title']);
    }

    public function testAFailedSideQuestionIsANoticeOfLevelErrorAndTheSessionGoesOn(): void
    {
        $result = $this->play([['type' => 'btw', 'question' => 'crash please']], new RequestSpy(['local' => 'qwen']), null, self::fake());

        self::assertSame(['ready', 'notice', 'bye'], self::types($result['events']));
        self::assertSame(['type' => 'notice', 'level' => 'error', 'message' => 'btw failed: the side question process ended with status 3: boom'], $result['events'][1]);
        self::assertSame(0, $result['exit']);
    }

    public function testAnEmptySideQuestionIsRefusedWithANoticeAndStartsNothing(): void
    {
        $result = $this->play([['type' => 'btw', 'question' => '   '], ['type' => 'input', 'text' => '/btw']], new RequestSpy(['local' => 'qwen']), null, self::fake());

        self::assertSame(['ready', 'notice', 'notice', 'bye'], self::types($result['events']));
        self::assertSame(['error', 'error'], array_column(array_slice($result['events'], 1, 2), 'level'));
        self::assertSame('btw needs a question: /btw QUESTION', $result['events'][1]['message']);
    }

    public function testASideQuestionDuringATurnIsAcceptedAndDoesNotWaitForIt(): void
    {
        $spy = new RequestSpy(['local' => 'qwen'], [], [
            function (): void {
                $this->send(['type' => 'btw', 'question' => 'what is two plus two?']);
            },
            $this->endingInput(),
            ScriptedClient::text('the answer'),
        ]);

        $result = $this->play([['type' => 'input', 'text' => 'a question for the model']], $spy, null, self::fake());

        self::assertSame(['ready', 'turn_start'], array_slice(self::types($result['events']), 0, 2));
        self::assertSame([], self::of($result['events'], 'notice'), 'the side question is not refused during a turn');
        $panel = self::first($result['events'], 'panel');
        self::assertSame('btw', $panel['title']);
        self::assertSame('bye', end($result['events'])['type']);
        self::assertGreaterThan(
            array_search('turn_end', self::types($result['events']), true),
            array_search('panel', self::types($result['events']), true),
            'the answer comes after the turn ends, which it did not wait for',
        );
    }

    public function testTheRealWorkerAnswersASideQuestionOverLoopback(): void
    {
        $this->model = new FakeModel([['content' => 'the loopback side answer']]);
        file_put_contents($this->harness->configFile, json_encode(self::config($this->model->url()), JSON_THROW_ON_ERROR));
        $child = new BtwJobs([PHP_BINARY, dirname(__DIR__, 2) . '/Support/btw-child.php'], 60.0);

        $result = $this->play([['type' => 'btw', 'question' => 'hello?']], new RequestSpy(['local' => 'qwen']), null, $child);

        self::assertSame(['ready', 'panel', 'bye'], self::types($result['events']));
        self::assertSame(['btw', 'the loopback side answer'], [$result['events'][1]['title'], $result['events'][1]['text']]);
    }

    private static function config(string $baseUrl): array
    {
        return ['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => $baseUrl, 'model' => 'qwen', 'tool_mode' => 'text']]];
    }

    private static function fake(): BtwJobs
    {
        return new BtwJobs([PHP_BINARY, dirname(__DIR__, 2) . '/Support/fake-btw.php'], 30.0);
    }
}
