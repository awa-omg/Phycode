<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Rpc\JsonLines;
use PHPUnit\Framework\TestCase;

final class JsonLinesTest extends TestCase
{
    public function testEachEventIsOneCompactLine(): void
    {
        $output = fopen('php://temp', 'r+');
        $channel = new JsonLines(fopen('php://temp', 'r+'), $output);

        $channel->send(['type' => 'output', 'text' => "a\nb"]);

        rewind($output);
        self::assertSame("{\"type\":\"output\",\"text\":\"a\\nb\"}\n", stream_get_contents($output));
    }

    public function testCommandsComeInOrderAndNonObjectsAreEmptyArrays(): void
    {
        $channel = new JsonLines($this->input("{\"type\":\"one\"}\n\nnot json\n[1,2]\n{\"type\":\"two\"}\n"), fopen('php://temp', 'r+'));

        self::assertSame(['type' => 'one'], $channel->next(0.0));
        self::assertSame([], $channel->next(0.0), 'an empty line');
        self::assertSame([], $channel->next(0.0), 'text that is not JSON');
        self::assertSame([], $channel->next(0.0), 'a JSON list');
        self::assertSame(['type' => 'two'], $channel->next(0.0));
        self::assertNull($channel->next(0.0));
        self::assertTrue($channel->ended());
    }

    public function testTheLastLineMayLackANewline(): void
    {
        $channel = new JsonLines($this->input('{"type":"quit"}'), fopen('php://temp', 'r+'));

        self::assertSame(['type' => 'quit'], $channel->next(null));
        self::assertNull($channel->next(null));
    }

    public function testBlockingReadReturnsNullAtTheEndOfInput(): void
    {
        $channel = new JsonLines($this->input(''), fopen('php://temp', 'r+'));

        self::assertNull($channel->next(null));
        self::assertTrue($channel->ended());
    }

    public function testATimedWaitThatExpiresDoesNotEndTheInput(): void
    {
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $channel = new JsonLines($reader, fopen('php://temp', 'r+'));

        self::assertNull($channel->next(0.05));
        self::assertFalse($channel->ended());

        fwrite($writer, "{\"type\":\"late\"}\n");
        self::assertSame(['type' => 'late'], $channel->next(1.0));

        fclose($writer);
        self::assertNull($channel->next(1.0));
        self::assertTrue($channel->ended());
    }

    private function input(string $content)
    {
        $input = fopen('php://temp', 'r+');
        fwrite($input, $content);
        rewind($input);

        return $input;
    }
}
