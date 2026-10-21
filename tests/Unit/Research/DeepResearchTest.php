<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Research;

use Phycode\Llm\LlmException;
use Phycode\Research\DeepResearch;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\ScriptedProvider;
use Phycode\Tests\Unit\Web\FakeSearchBackend;
use Phycode\Tests\Unit\Web\FixtureServer;
use Phycode\Web\Fetcher;
use Phycode\Web\SearchChain;
use Phycode\Web\SsrfGuard;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\TestCase;

final class DeepResearchTest extends TestCase
{
    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function site(): FixtureServer
    {
        return $this->server = new FixtureServer([
            '/a' => [['body' => '<html><head><title>Alpha page</title></head><body><p>Alpha facts.</p></body></html>', 'type' => 'text/html']],
            '/b' => [['status' => 404, 'body' => 'missing', 'type' => 'text/plain']],
            '/d' => [['body' => '<html><head><title>Delta page</title></head><body><p>Delta facts.</p></body></html>', 'type' => 'text/html']],
            '/e' => [['body' => '<html><head><title>Epsilon page</title></head><body><p>Epsilon facts.</p></body></html>', 'type' => 'text/html']],
        ]);
    }

    private function research(array $replies, array $backends, int $rounds, string $question = 'What is alpha?', ?Cancellation $cancel = null): array
    {
        $client = new ScriptedClient($replies);
        $fetcher = new Fetcher(new Transport(new SsrfGuard(["127.0.0.1:{$this->server?->port()}"])));
        $research = new DeepResearch(
            new ScriptedProvider(['home' => $client]),
            'home',
            new SearchChain($backends),
            $fetcher,
            null,
            null,
            $cancel,
        );
        $events = [];
        $answer = $research->run($question, $rounds, static function (string $step, string $detail, bool $done) use (&$events): void {
            $events[] = [$step, $detail, $done];
        });

        return [$answer, $events, $client];
    }

    private static function hasEvent(array $events, string $step, string $detail, bool $done): bool
    {
        return in_array([$step, $detail, $done], $events, true);
    }

    private static function lastMessage(ScriptedClient $client, int $request): string
    {
        $messages = $client->requests[$request]['messages'];

        return (string) $messages[count($messages) - 1]['content'];
    }

    public function testTwoRoundsReadTheBestPagesNoteThemAndAnswerWithTheSources(): void
    {
        $server = $this->site();
        $a = $server->url('/a');
        $b = $server->url('/b');
        $d = $server->url('/d');
        $e = $server->url('/e');
        $backend = new FakeSearchBackend([
            'alpha topic' => [FakeSearchBackend::hit($a, 'Alpha result'), FakeSearchBackend::hit($b, 'Missing result')],
            'alpha history' => [FakeSearchBackend::hit($a, 'Alpha again'), FakeSearchBackend::hit($d, 'Delta result')],
            'epsilon' => [FakeSearchBackend::hit($e, 'Epsilon result')],
        ]);

        [$answer, $events, $client] = $this->research([
            ScriptedClient::text('{"queries": ["alpha topic", "alpha history"]}'),
            ScriptedClient::text("- Alpha facts [1]\n- Delta facts [2]"),
            ScriptedClient::text('{"queries": ["epsilon"]}'),
            ScriptedClient::text('- Epsilon facts [3]'),
            ScriptedClient::text('Alpha, delta and epsilon facts [1][2][3].'),
        ], [$backend], 2);

        self::assertSame(
            "Alpha, delta and epsilon facts [1][2][3].\n\nSources:\n[1] Alpha page — {$a}\n[2] Delta page — {$d}\n[3] Epsilon page — {$e}",
            $answer,
        );
        self::assertCount(5, $client->requests, 'two plans, two notes and the answer');
        self::assertSame(['alpha topic', 'alpha history', 'epsilon'], $backend->asked);
        self::assertSame(['plan', 'answer'], [$events[0][0], $events[array_key_last($events)][0]]);
        self::assertSame(['round 1 of 2: planning the searches', false], [$events[0][1], $events[0][2]]);
        self::assertSame(['answer', 'answer written', true], $events[array_key_last($events)]);
        self::assertTrue(self::hasEvent($events, 'plan', 'round 1: alpha topic | alpha history', true));
        self::assertTrue(self::hasEvent($events, 'fetch', "round 1: skipped {$b}: HTTP 404", true), 'a page that fails is reported and skipped');
        self::assertTrue(self::hasEvent($events, 'fetch', 'round 1: [2] Delta page', true));
        self::assertTrue(self::hasEvent($events, 'fetch', 'round 2: [3] Epsilon page', true));
        self::assertTrue(self::hasEvent($events, 'notes', 'round 2: notes written', true));
    }

    public function testThePagesReachTheModelInsideTheUntrustedWrapper(): void
    {
        $server = $this->site();
        $a = $server->url('/a');
        $backend = new FakeSearchBackend(['alpha topic' => [FakeSearchBackend::hit($a)]]);

        [, , $client] = $this->research([
            ScriptedClient::text('{"queries": ["alpha topic"]}'),
            ScriptedClient::text('- Alpha facts [1]'),
            ScriptedClient::text('answer'),
        ], [$backend], 1);

        self::assertStringContainsString('<untrusted_web_content source="' . $a . '">', (string) $client->requests[1]['messages'][1]['content']);
        self::assertStringContainsString("[1] Alpha page\nAlpha facts.", (string) $client->requests[1]['messages'][1]['content']);
    }

    public function testAFailingBackendIsReportedAndTheRunGoesOn(): void
    {
        $server = $this->site();
        $a = $server->url('/a');
        $working = new FakeSearchBackend(['alpha topic' => [FakeSearchBackend::hit($a)]]);
        $failing = new FakeSearchBackend(['alpha topic' => new WebException('Exa search failed: HTTP 503')]);

        [$answer, $events] = $this->research([
            ScriptedClient::text('{"queries": ["alpha topic"]}'),
            ScriptedClient::text('- Alpha facts [1]'),
            ScriptedClient::text('Alpha [1].'),
        ], [$working, $failing], 1);

        self::assertTrue(self::hasEvent($events, 'search', 'round 1: a search backend failed: Exa search failed: HTTP 503', false));
        self::assertStringStartsWith("Alpha [1].\n\nSources:\n[1] Alpha page — {$a}", $answer);
    }

    public function testWhenThePlanFailsTheQuestionIsSearched(): void
    {
        $server = $this->site();
        $a = $server->url('/a');
        $backend = new FakeSearchBackend(['What is alpha?' => [FakeSearchBackend::hit($a)]]);

        [, $events] = $this->research([
            new LlmException('model down'),
            ScriptedClient::text('- note [1]'),
            ScriptedClient::text('answer'),
        ], [$backend], 1);

        self::assertTrue(self::hasEvent($events, 'plan', 'round 1: the model did not plan the searches: model down', false));
        self::assertSame(['What is alpha?'], $backend->asked);
    }

    public function testAPlanWithNoUsableQueriesInTheFirstRoundFallsBackToTheQuestion(): void
    {
        $server = $this->site();
        $a = $server->url('/a');
        $backend = new FakeSearchBackend(['What is alpha?' => [FakeSearchBackend::hit($a)]]);

        [, $events] = $this->research([
            ScriptedClient::text('I am not sure what to search.'),
            ScriptedClient::text('- note [1]'),
            ScriptedClient::text('answer'),
        ], [$backend], 1);

        self::assertTrue(self::hasEvent($events, 'plan', 'round 1: What is alpha?', true));
        self::assertSame(['What is alpha?'], $backend->asked);
    }

    public function testTheQueriesOfAReplyAreCleanedAndCappedAtFour(): void
    {
        $server = $this->site();
        $a = $server->url('/a');
        $backend = new FakeSearchBackend(['alpha topic' => [FakeSearchBackend::hit($a)], 'x' => []]);

        [, $events] = $this->research([
            ScriptedClient::text("Here you go:\n```json\n{\"queries\": [\"alpha topic\", \"Alpha Topic\", \"  \", \"x\", 7]}\n```"),
            ScriptedClient::text('- note [1]'),
            ScriptedClient::text('answer'),
        ], [$backend], 1);

        self::assertTrue(self::hasEvent($events, 'plan', 'round 1: alpha topic | x', true));
        self::assertSame(['alpha topic', 'x'], $backend->asked);
    }

    public function testAModelThatFailsToWriteNotesSkipsThem(): void
    {
        $server = $this->site();
        $a = $server->url('/a');
        $backend = new FakeSearchBackend(['alpha topic' => [FakeSearchBackend::hit($a)]]);

        [$answer, $events, $client] = $this->research([
            ScriptedClient::text('{"queries": ["alpha topic"]}'),
            new LlmException('notes failed'),
            ScriptedClient::text('answer without notes'),
        ], [$backend], 1);

        self::assertStringStartsWith('answer without notes', $answer);
        self::assertTrue(self::hasEvent($events, 'notes', 'round 1: no notes, the model failed: notes failed', true));
        self::assertStringContainsString("Notes:\nnone\n", self::lastMessage($client, 2));
    }

    public function testAFinalAnswerThatFailsEndsTheRunWithAnError(): void
    {
        $server = $this->site();
        $a = $server->url('/a');
        $backend = new FakeSearchBackend(['alpha topic' => [FakeSearchBackend::hit($a)]]);
        $client = new ScriptedClient([
            ScriptedClient::text('{"queries": ["alpha topic"]}'),
            ScriptedClient::text('- note [1]'),
            new LlmException('model down at the end'),
        ]);
        $events = [];
        $research = new DeepResearch(new ScriptedProvider(['home' => $client]), 'home', new SearchChain([$backend]), new Fetcher(new Transport(new SsrfGuard(["127.0.0.1:{$server->port()}"]))));

        try {
            $research->run('What is alpha?', 1, static function (string $step, string $detail, bool $done) use (&$events): void {
                $events[] = [$step, $detail, $done];
            });
            self::fail('a final answer that cannot be written must end the run');
        } catch (PhycodeException $error) {
            self::assertSame('the final answer could not be written: model down at the end', $error->getMessage());
        }
        self::assertTrue(self::hasEvent($events, 'answer', 'the answer failed: model down at the end', true));
    }

    public function testNoNewQueriesStopTheRoundsEarly(): void
    {
        $server = $this->site();
        $a = $server->url('/a');
        $backend = new FakeSearchBackend(['alpha topic' => [FakeSearchBackend::hit($a)]]);

        [$answer, $events, $client] = $this->research([
            ScriptedClient::text('{"queries": ["alpha topic"]}'),
            ScriptedClient::text('- note [1]'),
            ScriptedClient::text('{"queries": ["alpha topic"]}'),
            ScriptedClient::text('answer'),
        ], [$backend], 3);

        self::assertStringStartsWith('answer', $answer);
        self::assertCount(4, $client->requests, 'the third round is never planned');
        self::assertTrue(self::hasEvent($events, 'plan', 'round 2: no new queries', true));
        self::assertFalse(self::hasEvent($events, 'plan', 'round 3 of 3: planning the searches', false));
    }

    public function testPagesThatWereReadBeforeAreNotReadAgain(): void
    {
        $server = $this->site();
        $a = $server->url('/a');
        $backend = new FakeSearchBackend([
            'alpha topic' => [FakeSearchBackend::hit($a)],
            'alpha again' => [FakeSearchBackend::hit($a)],
        ]);

        [, $events, $client] = $this->research([
            ScriptedClient::text('{"queries": ["alpha topic"]}'),
            ScriptedClient::text('- note [1]'),
            ScriptedClient::text('{"queries": ["alpha again"]}'),
            ScriptedClient::text('answer'),
        ], [$backend], 2);

        self::assertTrue(self::hasEvent($events, 'notes', 'round 2: no new pages to read', true));
        self::assertCount(4, $client->requests);
    }

    public function testZeroRoundsAreRunAsOne(): void
    {
        $this->site();
        $backend = new FakeSearchBackend([]);

        [, , $client] = $this->research([
            ScriptedClient::text('{"queries": ["nothing"]}'),
            ScriptedClient::text('answer'),
        ], [$backend], 0);

        self::assertCount(2, $client->requests, 'zero rounds are run as one');
        self::assertSame(['nothing'], $backend->asked);
    }

    public function testACancelledResearchStopsBeforeTheModelIsAsked(): void
    {
        $this->site();
        $cancel = new Cancellation();
        $cancel->cancel('stopped by the user');

        try {
            $this->research([ScriptedClient::text('unused')], [new FakeSearchBackend([])], 1, 'What is alpha?', $cancel);
            self::fail('a cancelled research must not run');
        } catch (PhycodeException $error) {
            self::assertSame('the research was cancelled', $error->getMessage());
        }
    }
}
