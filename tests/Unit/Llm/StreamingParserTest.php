<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\SseParser;
use Phycode\Llm\TextToolProtocol;
use Phycode\Llm\ToolCallAccumulator;
use PHPUnit\Framework\TestCase;

final class StreamingParserTest extends TestCase
{
    public function testEventsAreAssembledAcrossChunks(): void
    {
        $parser = new SseParser();
        $events = [];
        foreach (['da', "ta: {\"a\":1}\r\n", "\n: keep-alive\n", "data: [DONE]\n\n"] as $chunk) {
            $events = [...$events, ...$parser->feed($chunk)];
        }

        self::assertSame(['{"a":1}', '[DONE]'], $events);
    }

    public function testTrailingEventIsReturnedByFlush(): void
    {
        $parser = new SseParser();
        self::assertSame([], $parser->feed('data: last'));
        self::assertSame(['last'], $parser->flush());
    }

    public function testMultilineDataIsJoinedWithNewlines(): void
    {
        $parser = new SseParser();
        self::assertSame(["a\nb"], $parser->feed("data: a\ndata: b\n\n"));
    }

    public function testToolCallArgumentsArriveInPiecesAndAreJoined(): void
    {
        $calls = new ToolCallAccumulator();
        $calls->merge([['index' => 0, 'id' => 'c1', 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{"path"']]]);
        $calls->merge([['index' => 0, 'function' => ['arguments' => ':"a.php"}']]]);

        $result = $calls->calls();
        self::assertCount(1, $result);
        self::assertSame('c1', $result[0]->id);
        self::assertSame('read', $result[0]->name);
        self::assertSame('{"path":"a.php"}', $result[0]->arguments);
    }

    public function testDifferentIndexesStayApartAndMissingIdsAreGenerated(): void
    {
        $calls = new ToolCallAccumulator();
        $calls->merge([
            ['index' => 0, 'id' => 'first', 'function' => ['name' => 'read', 'arguments' => '{}']],
            ['index' => 1, 'function' => ['name' => 'grep', 'arguments' => '{"pattern":"x"}']],
        ]);

        $result = $calls->calls();
        self::assertCount(2, $result);
        self::assertSame(['read', 'grep'], [$result[0]->name, $result[1]->name]);
        self::assertStringStartsWith('call_', $result[1]->id);
    }

    public function testObjectArgumentsAreEncodedAsJson(): void
    {
        $calls = new ToolCallAccumulator();
        $calls->merge([['index' => 0, 'id' => 'c', 'function' => ['name' => 'read', 'arguments' => ['path' => 'x']]]]);

        self::assertSame('{"path":"x"}', $calls->calls()[0]->arguments);
    }

    public function testTextProtocolParsesBlocksAndStripsThem(): void
    {
        $text = "Let me read it.\n<tool_call>\n{\"name\": \"read\", \"arguments\": {\"path\": \"a.php\"}}\n</tool_call>";

        $parsed = TextToolProtocol::parse($text);
        self::assertSame([], $parsed['errors']);
        self::assertCount(1, $parsed['calls']);
        self::assertSame('read', $parsed['calls'][0]->name);
        self::assertSame('{"path":"a.php"}', $parsed['calls'][0]->arguments);
        self::assertSame('Let me read it.', TextToolProtocol::stripCalls($text));
    }

    public function testTextProtocolRepairsTrailingCommasAndReportsBrokenBlocks(): void
    {
        $text = '<tool_call>{"name": "list", "arguments": {"depth": 2,},}</tool_call><tool_call>not json</tool_call>';

        $parsed = TextToolProtocol::parse($text);
        self::assertCount(1, $parsed['calls']);
        self::assertSame('list', $parsed['calls'][0]->name);
        self::assertCount(1, $parsed['errors']);
        self::assertStringContainsString('not valid JSON', $parsed['errors'][0]);
    }

    public function testTextProtocolReportsAnUnclosedBlock(): void
    {
        $parsed = TextToolProtocol::parse('<tool_call>{"name":"read"');

        self::assertSame([], $parsed['calls']);
        self::assertStringContainsString('not closed', implode(' ', $parsed['errors']));
    }
}
