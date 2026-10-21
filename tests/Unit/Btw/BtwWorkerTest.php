<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Btw;

use Phycode\Btw\BtwWorker;
use Phycode\Llm\LlmException;
use Phycode\Tests\Support\FakeModel;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class BtwWorkerTest extends TestCase
{
    private ?SessionHarness $harness = null;
    private ?FakeModel $model = null;

    protected function tearDown(): void
    {
        $this->model?->stop();
        $this->harness?->end();
    }

    public function testTheModelAnswersWithTheConversationAsContextAndWithoutTools(): void
    {
        $this->configure();
        $spy = new RequestSpy(['local' => 'qwen'], [], [ScriptedClient::text('It is 4.')]);

        $result = BtwWorker::run([
            'question' => 'what is two plus two?',
            'messages' => [
                ['role' => 'user', 'content' => 'add two numbers'],
                ['role' => 'assistant', 'content' => 'I can.', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'bash', 'arguments' => '{}']]]],
                ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'a tool output that is not context'],
                ['role' => 'system', 'content' => 'the main prompt of the session'],
            ],
            'profile' => 'local',
            'model' => 'mistral',
            'thinking' => 'on',
            'cwd' => $this->harness->cwd,
        ], $spy);

        self::assertSame(['ok' => true, 'answer' => 'It is 4.'], $result);
        self::assertSame([['local', 'mistral', 'on']], array_map(static fn (array $request): array => [$request['profile'], $request['model'], $request['thinking']], $spy->sent));
        $messages = $spy->sent[0]['messages'];
        self::assertSame(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        self::assertSame(['add two numbers', 'I can.', 'what is two plus two?'], array_column(array_slice($messages, 1), 'content'));
    }

    public function testTheModelAndThinkingOfTheProfileAreUsedWhenTheRequestNamesNone(): void
    {
        $this->configure();
        $spy = new RequestSpy(['local' => 'qwen'], [], [ScriptedClient::text('ok')]);

        BtwWorker::run(['question' => 'hi', 'messages' => [], 'profile' => null, 'model' => null, 'thinking' => null, 'cwd' => $this->harness->cwd], $spy);

        self::assertSame([['local', 'qwen', 'off']], array_map(static fn (array $request): array => [$request['profile'], $request['model'], $request['thinking']], $spy->sent));
    }

    public function testAFailureIsReturnedWithItsCredentialsRemoved(): void
    {
        $this->configure();
        $spy = new RequestSpy(['local' => 'qwen'], [], [
            new LlmException('connection to https://user:pass@models.example failed; Authorization: Bearer sk-test-SECRET-123456; key sk-test-SECRET-123456'),
        ]);

        $result = BtwWorker::run(['question' => 'hi', 'messages' => [], 'profile' => 'local', 'cwd' => $this->harness->cwd], $spy);

        self::assertSame(['ok' => false, 'error' => 'connection to https://models.example failed; Authorization: [redacted]; key [redacted]'], $result);
    }

    public function testAnEmptyAnswerIsAFailure(): void
    {
        $this->configure();
        $spy = new RequestSpy(['local' => 'qwen'], [], [ScriptedClient::text("  \n ")]);

        self::assertSame(['ok' => false, 'error' => 'the model gave no answer'], BtwWorker::run(['question' => 'hi', 'cwd' => $this->harness->cwd], $spy));
    }

    public function testARequestWithoutAQuestionOrAFolderIsAFailure(): void
    {
        $this->configure();

        self::assertSame(['ok' => false, 'error' => 'the side question request has no question'], BtwWorker::run(['cwd' => $this->harness->cwd], new RequestSpy(['local' => 'qwen'])));
        self::assertSame(['ok' => false, 'error' => 'the side question request has no cwd'], BtwWorker::run(['question' => 'hi'], new RequestSpy(['local' => 'qwen'])));
    }

    public function testTheRealClientAnswersOverLoopbackFromTheProfileOfTheConfiguration(): void
    {
        $this->model = new FakeModel([['content' => 'the loopback answer']]);
        $this->configure($this->model->url());

        $result = BtwWorker::run(['question' => 'hello?', 'messages' => [], 'profile' => 'local', 'cwd' => $this->harness->cwd]);

        self::assertSame(['ok' => true, 'answer' => 'the loopback answer'], $result);
    }

    private function configure(string $baseUrl = 'http://127.0.0.1:9'): void
    {
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => $baseUrl, 'model' => 'qwen', 'tool_mode' => 'text'],
        ]]);
    }
}
