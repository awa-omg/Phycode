<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Review;

use Phycode\Review\ReplyParser;
use Phycode\Review\ReviewLens;
use Phycode\Review\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReplyParserTest extends TestCase
{
    public function testAFindingIsReadFromAReplyWithAHeaderAndAFence(): void
    {
        $reply = "[subagent reviewer-risk finished: 2 turns, 1 tool calls]\n```json\n"
            . json_encode(['findings' => [[
                'severity' => 'error',
                'file' => 'src/a.php',
                'line' => 2,
                'title' => 'Raw input is echoed',
                'detail' => 'the value of the query string reaches the page',
                'evidence' => 'line 2 echoes $_GET',
            ]]], JSON_THROW_ON_ERROR)
            . "\n```";

        $parsed = ReplyParser::findings($reply, ReviewLens::Risk, 1);

        self::assertNotNull($parsed);
        self::assertFalse($parsed['salvaged']);
        self::assertSame(0, $parsed['dropped']);
        self::assertCount(1, $parsed['findings']);
        $finding = $parsed['findings'][0];
        self::assertSame('F1', $finding->id);
        self::assertSame(ReviewLens::Risk, $finding->lens);
        self::assertSame(Severity::Error, $finding->severity);
        self::assertSame('src/a.php:2', $finding->location());
        self::assertSame('Raw input is echoed', $finding->title);
        self::assertSame('unchecked', $finding->check);
    }

    public function testAnIncompleteFindingIsDroppedAndCounted(): void
    {
        $reply = json_encode(['findings' => [
            ['severity' => 'error', 'line' => 4, 'title' => 'no file', 'detail' => 'd', 'evidence' => 'e'],
            ['severity' => 'error', 'file' => 'src/a.php', 'line' => 4, 'title' => 'no evidence', 'detail' => 'd'],
            ['file' => 'src/a.php', 'line' => 5, 'title' => 'no severity', 'detail' => 'd', 'evidence' => 'e'],
            'not an object',
            ['severity' => 'warning', 'file' => 'src/b.php', 'line' => 7, 'title' => 'complete', 'detail' => 'd', 'evidence' => 'e'],
        ]], JSON_THROW_ON_ERROR);

        $parsed = ReplyParser::findings($reply, ReviewLens::Reliability, 1);

        self::assertNotNull($parsed);
        self::assertSame(4, $parsed['dropped']);
        self::assertCount(1, $parsed['findings']);
        self::assertSame('F1', $parsed['findings'][0]->id, 'the numbering counts the findings that are kept');
        self::assertSame('src/b.php', $parsed['findings'][0]->file);
    }

    public static function severityWords(): iterable
    {
        yield 'error' => ['error', Severity::Error];
        yield 'blocker in capitals' => ['BLOCKER', Severity::Error];
        yield 'critical' => ['Critical', Severity::Error];
        yield 'warning' => ['warning', Severity::Warning];
        yield 'warn' => ['WARN', Severity::Warning];
        yield 'suggestion' => ['suggestion', Severity::Suggestion];
        yield 'info' => ['Info', Severity::Suggestion];
        yield 'an unknown word' => ['nit', null];
    }

    #[DataProvider('severityWords')]
    public function testSeverityWordsAreUnderstood(string $word, ?Severity $expected): void
    {
        self::assertSame($expected, Severity::parse($word));
    }

    public function testAFileWithALineNumberIsSplitAndALeadingDotIsRemoved(): void
    {
        $reply = json_encode(['findings' => [
            ['severity' => 'error', 'file' => 'src/a.php:7', 'title' => 't', 'detail' => 'd', 'evidence' => 'e'],
            ['severity' => 'error', 'file' => './src/b.php', 'line' => 9, 'title' => 't', 'detail' => 'd', 'evidence' => 'e'],
        ]], JSON_THROW_ON_ERROR);

        $parsed = ReplyParser::findings($reply, ReviewLens::Risk, 1);

        self::assertNotNull($parsed);
        self::assertSame(['src/a.php:7', 'src/b.php:9'], array_map(static fn ($finding): string => $finding->location(), $parsed['findings']));
    }

    public function testAFindingWithoutATitleTakesItsDetail(): void
    {
        $reply = json_encode(['findings' => [['severity' => 'error', 'file' => 'src/a.php', 'detail' => 'the total is divided by zero', 'evidence' => 'e']]], JSON_THROW_ON_ERROR);

        $parsed = ReplyParser::findings($reply, ReviewLens::Risk, 1);

        self::assertNotNull($parsed);
        self::assertSame('the total is divided by zero', $parsed['findings'][0]->title);
    }

    public function testACleanReplyHasNoFindings(): void
    {
        $parsed = ReplyParser::findings('{"findings": []}', ReviewLens::Readability, 1);

        self::assertNotNull($parsed);
        self::assertSame([], $parsed['findings']);
        self::assertSame(0, $parsed['dropped']);
    }

    public function testAReplyWithoutAFindingsListIsUnreadable(): void
    {
        self::assertNull(ReplyParser::findings('I looked and found nothing.', ReviewLens::Risk, 1));
    }

    public function testBracesAfterTheObjectDoNotSpoilACleanReply(): void
    {
        $parsed = ReplyParser::findings('{"findings": []} (nothing to add; see {the diff})', ReviewLens::Risk, 1);

        self::assertNotNull($parsed);
        self::assertFalse($parsed['salvaged']);
        self::assertSame([], $parsed['findings']);
    }

    public function testAReplyCutBeforeAnyCompleteFindingIsSalvagedWithNoFindings(): void
    {
        $parsed = ReplyParser::findings('{"findings":[{"severity":"err' . "\n[... truncated, 40 bytes omitted]", ReviewLens::Risk, 1);

        self::assertNotNull($parsed);
        self::assertTrue($parsed['salvaged']);
        self::assertSame([], $parsed['findings']);
    }

    public function testAReplyCutAtTheReportLimitKeepsItsCompleteFindings(): void
    {
        $first = json_encode(['severity' => 'error', 'file' => 'src/a.php', 'line' => 3, 'title' => 'One', 'detail' => 'd', 'evidence' => 'e'], JSON_THROW_ON_ERROR);
        $second = json_encode(['severity' => 'warning', 'file' => 'src/b.php', 'line' => 9, 'title' => 'Two', 'detail' => 'd', 'evidence' => 'e'], JSON_THROW_ON_ERROR);
        $reply = '[subagent reviewer-risk finished: 4 turns, 3 tool calls]' . "\n"
            . '{"findings":[' . $first . ',' . $second . ',{"severity":"error","file":"src/c.php","li'
            . "\n[... truncated, 900 bytes omitted]";

        $parsed = ReplyParser::findings($reply, ReviewLens::Risk, 1);

        self::assertNotNull($parsed);
        self::assertTrue($parsed['salvaged']);
        self::assertSame(['One', 'Two'], array_map(static fn ($finding): string => $finding->title, $parsed['findings']));
    }

    public function testABraceInsideAStringDoesNotEndAnObject(): void
    {
        $reply = '{"findings":[{"severity":"error","file":"src/a.php","line":1,"title":"Brace","detail":"x } y","evidence":"e"},{"severity":"error","fi';

        $parsed = ReplyParser::findings($reply, ReviewLens::Risk, 1);

        self::assertNotNull($parsed);
        self::assertTrue($parsed['salvaged']);
        self::assertSame('x } y', $parsed['findings'][0]->detail);
    }

    public function testVerdictsReadTheOutcomeAndCountARefutationOnlyWithEvidence(): void
    {
        $reply = json_encode(['results' => [
            ['id' => 'F1', 'outcome' => 'refuted', 'evidence' => 'the caller clamps the value in b.php'],
            ['id' => 'F2', 'outcome' => 'refuted', 'evidence' => ''],
            ['id' => '3', 'outcome' => 'corroborated', 'evidence' => 'line 3 divides by the count'],
            ['id' => 'F4', 'outcome' => 'maybe', 'evidence' => 'x'],
        ]], JSON_THROW_ON_ERROR);

        $parsed = ReplyParser::verdicts($reply);

        self::assertNotNull($parsed);
        self::assertFalse($parsed['salvaged']);
        self::assertSame([
            'F1' => ['outcome' => 'refuted', 'evidence' => 'the caller clamps the value in b.php'],
            'F2' => ['outcome' => 'inconclusive', 'evidence' => ''],
            'F3' => ['outcome' => 'corroborated', 'evidence' => 'line 3 divides by the count'],
            'F4' => ['outcome' => 'inconclusive', 'evidence' => 'x'],
        ], $parsed['verdicts']);
    }

    public function testAVerdictsReplyCutAtTheReportLimitKeepsItsCompleteResults(): void
    {
        $reply = '{"results":[{"id":"F1","outcome":"corroborated","evidence":"line 3"},{"id":"F2","outcome":"refuted","evi'
            . "\n[... truncated, 300 bytes omitted]";

        $parsed = ReplyParser::verdicts($reply);

        self::assertNotNull($parsed);
        self::assertTrue($parsed['salvaged']);
        self::assertSame(['F1'], array_keys($parsed['verdicts']));
    }

    public function testBracesAfterTheObjectDoNotSpoilAVerdictsReply(): void
    {
        $parsed = ReplyParser::verdicts('{"results":[{"id":"F1","outcome":"refuted","evidence":"b.php clamps it"}]} See {F1}.');

        self::assertNotNull($parsed);
        self::assertFalse($parsed['salvaged']);
        self::assertSame(['F1'], array_keys($parsed['verdicts']));
    }

    public function testAReplyWithoutResultsHasNoVerdicts(): void
    {
        self::assertNull(ReplyParser::verdicts('no json here'));
    }
}
