<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ollama;

use Phycode\Config\Config;
use Phycode\Config\Profile;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\Ollama\OllamaClient;
use Phycode\Llm\OpenAiClient;
use Phycode\Llm\Retry;
use Phycode\Support\Cancellation;
use Phycode\Tests\Support\OllamaServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OllamaRetryTest extends TestCase
{
    private const MESSAGES = [['role' => 'user', 'content' => 'hello']];

    private ?OllamaServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        Cancellation::setPoll(null);
    }

    public static function busyStatuses(): array
    {
        return ['too many requests' => [429], 'bad gateway' => [502], 'unavailable' => [503], 'gateway timeout' => [504]];
    }

    #[DataProvider('busyStatuses')]
    public function testABusyStatusIsRetriedTwiceAndTheNextReplyIsUsed(int $status): void
    {
        $busy = ['status' => $status, 'body' => '{"error":"busy"}', 'type' => 'application/json'];
        $server = $this->serve(['busy' => ['/api/chat' => [$busy, $busy, ['fixture' => 'live/chat-text.ndjson']]]]);

        $response = (new OllamaClient($server->profile('busy'), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame('**Hola**', $response->content);
        $requests = $server->requests();
        self::assertCount(3, $requests);
        self::assertSame($requests[0]['body'], $requests[2]['body'], 'the retries send the same request');
    }

    public function testTheRetriesWaitHalfASecondAndThenOneAndAHalf(): void
    {
        $server = $this->serve(['busy' => ['/api/chat' => [['status' => 503], ['status' => 503], ['fixture' => 'live/chat-text.ndjson']]]]);

        (new OllamaClient($server->profile('busy')))->chat(self::MESSAGES, [], new ChatOptions());

        $times = array_column($server->requests(), 'time');
        self::assertGreaterThanOrEqual(0.45, $times[1] - $times[0], 'the first wait is 0.5 s');
        self::assertGreaterThanOrEqual(1.45, $times[2] - $times[1], 'the second wait is 1.5 s');
        self::assertLessThan(4.0, $times[2] - $times[0]);
    }

    public function testWhenTheRetriesRunOutTheStatusIsReported(): void
    {
        $server = $this->serve(['busy' => ['/api/chat' => [['status' => 503, 'body' => '{"error":"server busy, please try again"}', 'type' => 'application/json']]]]);

        try {
            (new OllamaClient($server->profile('busy'), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the reply should have failed');
        } catch (LlmException $error) {
            self::assertSame('HTTP 503: server busy, please try again', $error->getMessage());
        }
        self::assertCount(3, $server->requests(), 'one attempt and two retries');
    }

    public static function otherErrors(): array
    {
        return ['bad request' => [400], 'unauthorized' => [401], 'not found' => [404], 'server error' => [500]];
    }

    #[DataProvider('otherErrors')]
    public function testOtherErrorsAreReportedAtOnce(int $status): void
    {
        $server = $this->serve(['bad' => ['/api/chat' => [['status' => $status, 'body' => '{"error":"refused here"}', 'type' => 'application/json']]]]);

        try {
            (new OllamaClient($server->profile('bad'), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the reply should have failed');
        } catch (LlmException $error) {
            self::assertSame("HTTP {$status}: refused here", $error->getMessage());
        }
        self::assertCount(1, $server->requests());
    }

    public function testAConnectionFailureIsRetriedAndThenReported(): void
    {
        $profile = $this->profileAt('http://127.0.0.1:' . $this->closedPort() . '/chat');

        $started = microtime(true);
        try {
            (new OllamaClient($profile))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the connection should have failed');
        } catch (LlmException $error) {
            self::assertStringStartsWith('connection to the model server failed: ', $error->getMessage());
        }
        self::assertGreaterThanOrEqual(1.95, microtime(true) - $started, 'both waits were made');
    }

    public function testNothingIsRetriedOnceTheStreamHasStarted(): void
    {
        $server = $this->serve(['cut' => ['/api/chat' => [['fixture' => 'live/chat-text.ndjson', 'truncate' => true]]]]);

        try {
            (new OllamaClient($server->profile('cut')))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the stream should have failed');
        } catch (LlmException $error) {
            self::assertStringStartsWith('connection to the model server failed: ', $error->getMessage());
        }
        self::assertCount(1, $server->requests(), 'a stream that broke after bytes arrived is not sent again');
    }

    public function testACancellationDuringAWaitEndsItAtOnce(): void
    {
        $server = $this->serve(['busy' => ['/api/chat' => [['status' => 503]]]]);
        $cancel = new Cancellation();
        $started = microtime(true);
        Cancellation::setPoll(static function () use ($cancel, $started): void {
            if (microtime(true) - $started >= 0.1) {
                $cancel->cancel('interrupted by the user');
            }
        });

        try {
            (new OllamaClient($server->profile('busy'), [2.0]))->chat(self::MESSAGES, [], new ChatOptions(null, $cancel));
            self::fail('the reply should have been cancelled');
        } catch (LlmException $error) {
            self::assertSame('cancelled: interrupted by the user', $error->getMessage());
        }

        self::assertLessThan(1.5, microtime(true) - $started, 'the 2 s wait was not waited out');
        self::assertCount(1, $server->requests(), 'the retry was never sent');
    }

    public function testTheScheduleIsTheOneOpenAiClientUses(): void
    {
        self::assertSame(
            (new \ReflectionClassConstant(OpenAiClient::class, 'RETRY_DELAYS'))->getValue(),
            Retry::DELAYS,
        );
        self::assertSame(
            (new \ReflectionClassConstant(OpenAiClient::class, 'RETRY_STATUSES'))->getValue(),
            Retry::STATUSES,
        );
    }

    private function serve(array $scenarios): OllamaServer
    {
        $this->server?->stop();

        return $this->server = new OllamaServer($scenarios);
    }

    private function profileAt(string $baseUrl): Profile
    {
        return Config::fromArrays(['profiles' => ['test' => ['base_url' => $baseUrl, 'model' => 'qwen3:4b', 'protocol' => 'ollama']]], null, [], '/nonexistent')->profile('test');
    }

    private function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($server, $message);
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }
}
