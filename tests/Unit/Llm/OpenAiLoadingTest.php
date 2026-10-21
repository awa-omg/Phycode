<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\OpenAiClient;
use Phycode\Support\Cancellation;
use Phycode\Tests\Support\LlamaServer;
use PHPUnit\Framework\TestCase;

final class OpenAiLoadingTest extends TestCase
{
    private const MESSAGES = [['role' => 'user', 'content' => 'hello']];
    private const LOADING = ['status' => 503, 'body' => '{"error":{"code":503,"message":"Loading model","type":"unavailable_error"}}', 'type' => 'application/json'];
    private const REPLY = 'The three primary colours are red, blue, and yellow.';

    private ?LlamaServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        OpenAiClient::setLoadingWait(null, null);
        Cancellation::setPoll(null);
    }

    public function testTheRequestIsSentAgainAfterEachPauseUntilTheModelHasLoaded(): void
    {
        $server = $this->serve(['loading' => [self::LOADING, self::LOADING, ['fixture' => 'text.sse']]]);
        OpenAiClient::setLoadingWait(0.1, 30.0);
        $progress = [];

        $response = (new OpenAiClient($server->profile('loading')))->chat(self::MESSAGES, [], new ChatOptions(
            static function (string $type, string $text) use (&$progress): void {
                if ($type === 'progress') {
                    $progress[] = $text;
                }
            },
        ));

        self::assertSame(self::REPLY, $response->content);
        $times = array_column($server->requests(), 'time');
        self::assertCount(3, $times, 'two loading replies, then the one that succeeds');
        for ($index = 1; $index < count($times); $index++) {
            self::assertGreaterThanOrEqual(0.09, $times[$index] - $times[$index - 1], 'each request waits one pause');
        }
        self::assertCount(2, $progress, 'each wait is reported as progress');
        self::assertStringContainsString('still loading the model', $progress[0]);
    }

    public function testTheWaitEndsWithTheErrorOfTheReplyWhenTheModelNeverLoads(): void
    {
        $server = $this->serve(['loading' => [self::LOADING]]);
        OpenAiClient::setLoadingWait(0.1, 0.5);

        $started = microtime(true);
        try {
            (new OpenAiClient($server->profile('loading')))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the model never loaded, so the reply should have failed');
        } catch (LlmException $error) {
            self::assertSame('HTTP 503: llama-server did not finish loading the model in 0.5 s; check the server', $error->getMessage());
        }

        $waited = microtime(true) - $started;
        self::assertGreaterThanOrEqual(0.45, $waited, 'the wait lasted up to the limit');
        self::assertLessThan(3.0, $waited);
        self::assertGreaterThan(2, count($server->requests()), 'the model was asked again at each poll');
    }

    public function testACancellationEndsTheWaitAtOnce(): void
    {
        $server = $this->serve(['loading' => [self::LOADING]]);
        $cancel = new Cancellation();
        $started = microtime(true);
        Cancellation::setPoll(static function () use ($cancel, $started): void {
            if (microtime(true) - $started >= 0.3) {
                $cancel->cancel('interrupted by the user');
            }
        });

        try {
            (new OpenAiClient($server->profile('loading')))->chat(self::MESSAGES, [], new ChatOptions(null, $cancel));
            self::fail('the wait should have been cancelled');
        } catch (LlmException $error) {
            self::assertSame('cancelled: interrupted by the user', $error->getMessage());
        }

        self::assertLessThan(1.5, microtime(true) - $started, 'the 1 s pause was not waited out');
        self::assertCount(1, $server->requests(), 'the request was not sent again');
    }

    public function testA503WithoutTheLoadingMessageIsNotWaitedOn(): void
    {
        $server = $this->serve(['busy' => [['status' => 503, 'body' => '{"error":{"message":"unavailable"}}', 'type' => 'application/json'], ['fixture' => 'text.sse']]]);
        OpenAiClient::setLoadingWait(5.0, 60.0);
        $progress = 0;

        $started = microtime(true);
        $response = (new OpenAiClient($server->profile('busy'), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions(
            static function (string $type) use (&$progress): void {
                if ($type === 'progress') {
                    $progress++;
                }
            },
        ));

        self::assertSame(self::REPLY, $response->content, 'the short retry gets the next reply');
        self::assertCount(2, $server->requests());
        self::assertSame(0, $progress, 'no loading wait is reported');
        self::assertLessThan(4.0, microtime(true) - $started, 'the 5 s pause of a loading wait was not taken');
    }

    private function serve(array $scenarios): LlamaServer
    {
        $this->server?->stop();

        return $this->server = new LlamaServer($scenarios);
    }
}
