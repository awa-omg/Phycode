<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Config\Config;
use Phycode\Config\Profile;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\OpenAiClient;
use Phycode\Support\Cancellation;
use Phycode\Tests\Support\LlamaServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenAiRetryTest extends TestCase
{
    private const MESSAGES = [['role' => 'user', 'content' => 'hello']];

    private ?LlamaServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        Cancellation::setPoll(null);
    }

    public static function busyStatuses(): array
    {
        return ['too many requests' => [429], 'bad gateway' => [502], 'gateway timeout' => [504]];
    }

    #[DataProvider('busyStatuses')]
    public function testABusyStatusIsRetriedTwiceAndTheNextReplyIsUsed(int $status): void
    {
        $server = $this->serve(['busy' => [
            ['status' => $status, 'body' => '{"error":{"message":"busy"}}', 'type' => 'application/json'],
            ['status' => $status, 'body' => '{"error":{"message":"busy"}}', 'type' => 'application/json'],
            ['fixture' => 'text.sse'],
        ]]);

        $response = (new OpenAiClient($server->profile('busy'), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());

        self::assertSame('The three primary colours are red, blue, and yellow.', $response->content);
        $requests = $server->requests();
        self::assertCount(3, $requests);
        self::assertSame($requests[0]['body'], $requests[2]['body'], 'the retries send the same request');
    }

    public function testTheRetriesWaitHalfASecondAndThenOneAndAHalf(): void
    {
        $server = $this->serve(['busy' => [['status' => 502], ['status' => 502], ['fixture' => 'text.sse']]]);

        (new OpenAiClient($server->profile('busy')))->chat(self::MESSAGES, [], new ChatOptions());

        $times = array_column($server->requests(), 'time');
        self::assertGreaterThanOrEqual(0.45, $times[1] - $times[0], 'first backoff is 0.5 s');
        self::assertGreaterThanOrEqual(1.45, $times[2] - $times[1], 'second backoff is 1.5 s');
        self::assertLessThan(4.0, $times[2] - $times[0]);
    }

    public function testWhenTheRetriesRunOutTheStatusIsReported(): void
    {
        $server = $this->serve(['busy' => [['status' => 504, 'body' => '{"error":{"message":"gateway timeout"}}', 'type' => 'application/json']]]);

        try {
            (new OpenAiClient($server->profile('busy'), [0.0, 0.0]))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the reply should have failed');
        } catch (LlmException $error) {
            self::assertSame('HTTP 504: gateway timeout', $error->getMessage());
        }
        self::assertCount(3, $server->requests(), 'one attempt and two retries');
    }

    public static function otherErrors(): array
    {
        return ['bad request' => [400], 'unauthorized' => [401], 'not found' => [404]];
    }

    #[DataProvider('otherErrors')]
    public function testOtherErrorsAreReportedAtOnce(int $status): void
    {
        $server = $this->serve(['bad' => [['status' => $status, 'body' => '{"error":{"message":"refused here"}}', 'type' => 'application/json']]]);

        try {
            (new OpenAiClient($server->profile('bad')))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the reply should have failed');
        } catch (LlmException $error) {
            self::assertSame("HTTP {$status}: refused here", $error->getMessage());
        }
        self::assertCount(1, $server->requests());
    }

    public function testAConnectionFailureIsRetriedAndThenReported(): void
    {
        $profile = $this->profileAt('http://127.0.0.1:' . $this->closedPort());

        $started = microtime(true);
        try {
            (new OpenAiClient($profile))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the connection should have failed');
        } catch (LlmException $error) {
            self::assertStringStartsWith('connection to the model server failed: ', $error->getMessage());
        }
        self::assertGreaterThanOrEqual(1.95, microtime(true) - $started, 'both backoffs were waited');
    }

    public function testNothingIsRetriedOnceTheStreamHasStarted(): void
    {
        $server = $this->serve(['cut' => [['fixture' => 'text.sse', 'truncate' => true]]]);

        try {
            (new OpenAiClient($server->profile('cut')))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the stream should have failed');
        } catch (LlmException $error) {
            self::assertStringStartsWith('connection to the model server failed: ', $error->getMessage());
        }
        self::assertCount(1, $server->requests(), 'a stream that broke after bytes arrived is not sent again');
    }

    public function testACancellationDuringABackoffEndsTheWaitAtOnce(): void
    {
        $server = $this->serve(['busy' => [['status' => 502]]]);
        $cancel = new Cancellation();
        $started = microtime(true);
        Cancellation::setPoll(static function () use ($cancel, $started): void {
            if (microtime(true) - $started >= 0.1) {
                $cancel->cancel('interrupted by the user');
            }
        });

        try {
            (new OpenAiClient($server->profile('busy'), [2.0]))->chat(self::MESSAGES, [], new ChatOptions(null, $cancel));
            self::fail('the reply should have been cancelled');
        } catch (LlmException $error) {
            self::assertSame('cancelled: interrupted by the user', $error->getMessage());
        }

        self::assertLessThan(1.5, microtime(true) - $started, 'the 2 s backoff was not waited out');
        self::assertCount(1, $server->requests(), 'the retry was never sent');
    }

    private function serve(array $scenarios): LlamaServer
    {
        $this->server?->stop();

        return $this->server = new LlamaServer($scenarios);
    }

    private function profileAt(string $baseUrl): Profile
    {
        return Config::fromArrays(['profiles' => ['test' => ['base_url' => $baseUrl, 'model' => 'qwen35-2b']]], null, [], '/nonexistent')->profile('test');
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
