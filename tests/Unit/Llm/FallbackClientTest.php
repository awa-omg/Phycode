<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\ChatOptions;
use Phycode\Llm\FallbackClient;
use Phycode\Llm\LlmException;
use Phycode\Tests\Support\ScriptedClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FallbackClientTest extends TestCase
{
    private const MESSAGES = [['role' => 'user', 'content' => 'hello']];

    public function testA429MovesTheTurnToTheNextProfileAndSaysSo(): void
    {
        $primary = new ScriptedClient([new LlmException('HTTP 429: too many requests')]);
        $backup = new ScriptedClient([ScriptedClient::text('from the backup')]);
        $events = [];
        $options = new ChatOptions(static function (string $type, string $text) use (&$events): void {
            $events[] = [$type, $text];
        });

        $response = (new FallbackClient(['main', 'backup'], [$primary, $backup]))->chat(self::MESSAGES, [], $options);

        self::assertSame('from the backup', $response->content);
        self::assertCount(1, $primary->requests);
        self::assertCount(1, $backup->requests);
        self::assertCount(1, $events);
        self::assertSame('notice', $events[0][0]);
        self::assertStringContainsString('profile main answered HTTP 429', $events[0][1]);
        self::assertStringContainsString('fallback profile backup', $events[0][1]);
    }

    public function testTheTurnStaysOnTheFallbackForTheRestOfIt(): void
    {
        $primary = new ScriptedClient([new LlmException('HTTP 429: busy')]);
        $backup = new ScriptedClient([ScriptedClient::text('first'), ScriptedClient::text('second')]);
        $client = new FallbackClient(['main', 'backup'], [$primary, $backup]);

        $client->chat(self::MESSAGES, [], new ChatOptions());
        $client->chat(self::MESSAGES, [], new ChatOptions());

        self::assertCount(1, $primary->requests, 'the primary is not asked again in the same turn');
        self::assertCount(2, $backup->requests);
    }

    public static function otherErrors(): iterable
    {
        yield 'unauthorized' => [new LlmException('HTTP 401: invalid api key')];
        yield 'server error' => [new LlmException('HTTP 500: internal error')];
        yield 'connection refused' => [new LlmException('connection to the model server failed: refused')];
    }

    #[DataProvider('otherErrors')]
    public function testAnyOtherErrorEndsTheTurnWithoutAFallback(LlmException $error): void
    {
        $primary = new ScriptedClient([$error]);
        $backup = new ScriptedClient([ScriptedClient::text('unused')]);

        try {
            (new FallbackClient(['main', 'backup'], [$primary, $backup]))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the error should end the turn');
        } catch (LlmException $thrown) {
            self::assertSame($error, $thrown);
        }
        self::assertCount(0, $backup->requests);
    }

    public function testEachRateLimitedProfileIsAnnouncedAndTheLastOneAnswers(): void
    {
        $first = new ScriptedClient([new LlmException('HTTP 429: busy')]);
        $second = new ScriptedClient([new LlmException('HTTP 429: busy too')]);
        $third = new ScriptedClient([ScriptedClient::text('from the third')]);
        $notices = [];
        $options = new ChatOptions(static function (string $type, string $text) use (&$notices): void {
            $notices[] = $text;
        });

        $response = (new FallbackClient(['a', 'b', 'c'], [$first, $second, $third]))->chat(self::MESSAGES, [], $options);

        self::assertSame('from the third', $response->content);
        self::assertCount(2, $notices);
        self::assertStringContainsString('profile a answered', $notices[0]);
        self::assertStringContainsString('profile b answered', $notices[1]);
        self::assertStringContainsString('fallback profile c', $notices[1]);
    }

    public function testWhenTheLastProfileIsRateLimitedItsErrorEndsTheTurn(): void
    {
        $primary = new ScriptedClient([new LlmException('HTTP 429: busy')]);
        $last = new LlmException('HTTP 429: busy too');
        $backup = new ScriptedClient([$last]);

        try {
            (new FallbackClient(['main', 'backup'], [$primary, $backup]))->chat(self::MESSAGES, [], new ChatOptions());
            self::fail('the last profile has no fallback left');
        } catch (LlmException $thrown) {
            self::assertSame($last, $thrown);
        }
    }
}
