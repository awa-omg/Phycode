<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ollama;

use Phycode\Llm\Ollama\NdjsonParser;
use PHPUnit\Framework\TestCase;

final class NdjsonParserTest extends TestCase
{
    public function testALineIsReturnedWhenItsNewlineArrives(): void
    {
        $parser = new NdjsonParser();

        self::assertSame(['{"a":1}'], $parser->feed("{\"a\":1}\n{\"b\""));
        self::assertSame(['{"b":2}'], $parser->feed(":2}\n"));
    }

    public function testAChunkCanHoldSeveralLines(): void
    {
        self::assertSame(['1', '2', '3'], (new NdjsonParser())->feed("1\n2\n3\n"));
    }

    public function testBlankLinesAndCarriageReturnsAreIgnored(): void
    {
        self::assertSame(['{"a":1}', '{"b":2}'], (new NdjsonParser())->feed("\r\n{\"a\":1}\r\n\n  {\"b\":2}  \n"));
    }

    public function testTheLastLineWithoutANewlineIsReturnedByFlushOnce(): void
    {
        $parser = new NdjsonParser();

        self::assertSame([], $parser->feed('{"done":true}'));
        self::assertSame(['{"done":true}'], $parser->flush());
        self::assertSame([], $parser->flush());
    }

    public function testTheSameLinesComeOutWhateverTheChunkSizes(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/ollama/live/chat-tool.ndjson');
        $expected = array_values(array_filter(explode("\n", $body), static fn (string $line): bool => $line !== ''));

        $parser = new NdjsonParser();
        $lines = [];
        foreach (str_split($body) as $byte) {
            array_push($lines, ...$parser->feed($byte));
        }
        array_push($lines, ...$parser->flush());

        self::assertSame($expected, $lines);
    }
}
