<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\LlmException;
use Phycode\Llm\Retry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryLoadingTest extends TestCase
{
    public function testTheScheduleIsOneTwoFourEightAndSixteenSecondsThenSixteenUpToSixty(): void
    {
        self::assertSame([1.0, 2.0, 4.0, 8.0, 16.0, 16.0, 13.0], Retry::loadingPauses());
    }

    public function testTheLastPauseIsCutSoThatThePausesStopAtTheLimit(): void
    {
        self::assertSame([1.0, 2.0, 2.0], Retry::loadingPauses([1.0, 2.0], 5.0));
        self::assertSame([0.5, 0.5, 0.25], Retry::loadingPauses([0.5], 1.25));
    }

    public static function replies(): array
    {
        return [
            'loading, as the server sends it' => [503, '{"error":{"message":"Loading model","type":"unavailable_error","code":503}}', true],
            'loading, as plain text' => [503, 'Loading model', true],
            'another 503' => [503, '{"error":{"message":"unavailable"}}', false],
            'loading with a busy status' => [429, 'Loading model', false],
            'loading with a success status' => [200, 'Loading model', false],
        ];
    }

    #[DataProvider('replies')]
    public function testOnlyA503ThatSaysTheModelIsLoadingIsWaitedOn(int $status, string $body, bool $waited): void
    {
        self::assertSame($waited, Retry::isLoading($status, $body));
    }

    public function testTheTimeoutErrorStartsWithTheStatusAndNamesTheServer(): void
    {
        $error = Retry::loadingTimeout(60.0);

        self::assertInstanceOf(LlmException::class, $error);
        self::assertSame('HTTP 503: llama-server did not finish loading the model in 60 s; check the server', $error->getMessage());
    }

    public function testWhileLoadingSendsAgainUntilTheReplyIsNoLoadingAnswer(): void
    {
        $replies = [['status' => 503, 'body' => 'Loading model'], ['status' => 503, 'body' => 'Loading model'], ['status' => 200, 'body' => 'ok']];
        $sent = 0;

        $reply = Retry::whileLoading(static function () use (&$replies, &$sent): array {
            $sent++;

            return array_shift($replies);
        }, [0.01], 5.0);

        self::assertSame(['status' => 200, 'body' => 'ok'], $reply);
        self::assertSame(3, $sent);
    }

    public function testWhileLoadingReturnsTheLoadingAnswerWhenTheLimitHasRunOut(): void
    {
        $sent = 0;

        $reply = Retry::whileLoading(static function () use (&$sent): array {
            $sent++;

            return ['status' => 503, 'body' => 'Loading model'];
        }, [0.01], 0.05);

        self::assertSame(['status' => 503, 'body' => 'Loading model'], $reply);
        self::assertSame(count(Retry::loadingPauses([0.01], 0.05)) + 1, $sent, 'one request before each pause and one after the last');
    }
}
