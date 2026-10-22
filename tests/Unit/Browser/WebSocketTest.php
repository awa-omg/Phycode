<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Browser;

use Phycode\Browser\WebSocket;
use Phycode\Support\PhycodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebSocketTest extends TestCase
{
    private $peer;
    private WebSocket $socket;

    protected function setUp(): void
    {
        $this->open();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->peer)) {
            fclose($this->peer);
        }
    }

    private function open(int $maxMessageBytes = 1_000_000): void
    {
        [$client, $this->peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (function_exists('socket_import_stream')) {
            foreach ([$client, $this->peer] as $stream) {
                $socket = socket_import_stream($stream);
                if ($socket !== false) {
                    socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, 1048576);
                    socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 1048576);
                }
            }
        }
        $this->socket = WebSocket::over($client, $maxMessageBytes);
    }

    private function feed(string ...$frames): void
    {
        fwrite($this->peer, implode('', $frames));
    }

    public static function lengths(): iterable
    {
        yield 'seven bit length' => [5];
        yield 'the largest seven bit length' => [125];
        yield 'sixteen bit length' => [126];
        yield 'a longer sixteen bit length' => [65535];
        yield 'sixty-four bit length' => [65536];
        yield 'a long sixty-four bit length' => [300000];
    }

    #[DataProvider('lengths')]
    public function testItReadsMessagesOfEveryLengthForm(int $length): void
    {
        $text = str_repeat('é', intdiv($length, 2)) . str_repeat('a', $length % 2);
        $this->feed(Frames::server(0x1, $text));

        self::assertSame($text, $this->socket->receive(1.0));
    }

    #[DataProvider('lengths')]
    public function testItMasksWhatItSendsInEveryLengthForm(int $length): void
    {
        $text = str_repeat('x', $length);

        $this->socket->send($text);

        $frame = Frames::client($this->peer);
        self::assertTrue($frame['fin']);
        self::assertSame(0x1, $frame['opcode']);
        self::assertTrue($frame['masked'], 'RFC 6455 5.3: every frame from a client is masked');
        self::assertSame(strlen($frame['mask']), 4);
        self::assertSame($text, $frame['payload']);
        self::assertSame($length < 126 ? 0 : ($length < 65536 ? 2 : 8), $frame['form']);
        self::assertNotSame($text, $frame['raw'], 'the bytes on the wire are not the plain text');
    }

    public function testEveryFrameGetsAFreshMaskingKey(): void
    {
        $this->socket->send('same');
        $this->socket->send('same');

        self::assertNotSame(Frames::client($this->peer)['mask'], Frames::client($this->peer)['mask']);
    }

    public function testItReassemblesFragmentsAndAnswersAPingBetweenThem(): void
    {
        $this->feed(
            Frames::server(0x1, 'He', fin: false),
            Frames::server(0x9, 'are you there'),
            Frames::server(0x0, 'llo ', fin: false),
            Frames::server(0xA, 'ignored'),
            Frames::server(0x0, 'world'),
        );

        self::assertSame('Hello world', $this->socket->receive(1.0));
        $pong = Frames::client($this->peer);
        self::assertSame(0xA, $pong['opcode']);
        self::assertTrue($pong['masked']);
        self::assertSame('are you there', $pong['payload'], 'a pong repeats the data of its ping');
    }

    public function testACharacterMaySplitAcrossFragments(): void
    {
        $euro = "\xE2\x82\xAC";
        $this->feed(Frames::server(0x1, 'a' . $euro[0], fin: false), Frames::server(0x0, substr($euro, 1) . 'b'));

        self::assertSame('a€b', $this->socket->receive(1.0));
    }

    public function testAPingAloneIsAnsweredAndTheNextMessageStillComes(): void
    {
        $this->feed(Frames::server(0x9, ''), Frames::server(0x1, 'next'));

        self::assertSame('next', $this->socket->receive(1.0));
        self::assertSame(['opcode' => 0xA, 'payload' => ''], array_intersect_key(Frames::client($this->peer), ['opcode' => 1, 'payload' => 1]));
    }

    public function testItReturnsNothingWhenNothingArrives(): void
    {
        $started = microtime(true);

        self::assertNull($this->socket->receive(0.1));
        self::assertGreaterThanOrEqual(0.09, microtime(true) - $started);
        self::assertLessThan(1.0, microtime(true) - $started);
        self::assertTrue($this->socket->isOpen());
    }

    public function testAFrameThatArrivesInPiecesIsKeptBetweenWaits(): void
    {
        $frame = Frames::server(0x1, str_repeat('p', 300));
        $this->feed(substr($frame, 0, 3));
        self::assertNull($this->socket->receive(0.05));
        $this->feed(substr($frame, 3, 100));
        self::assertNull($this->socket->receive(0.05));
        $this->feed(substr($frame, 103));

        self::assertSame(str_repeat('p', 300), $this->socket->receive(1.0));
    }

    public function testAFragmentedMessageIsKeptBetweenWaits(): void
    {
        $this->feed(Frames::server(0x1, 'one ', fin: false));
        self::assertNull($this->socket->receive(0.05));
        $this->feed(Frames::server(0x0, 'two'));

        self::assertSame('one two', $this->socket->receive(1.0));
    }

    public function testSeveralMessagesInOneReadComeOneByOne(): void
    {
        $this->feed(Frames::server(0x1, 'a'), Frames::server(0x1, 'b'), Frames::server(0x1, 'c'));

        self::assertSame(['a', 'b', 'c'], [$this->socket->receive(1.0), $this->socket->receive(1.0), $this->socket->receive(1.0)]);
    }

    public function testAnEmptyMessageIsAMessage(): void
    {
        $this->feed(Frames::server(0x1, ''));

        self::assertSame('', $this->socket->receive(1.0));
    }

    public function testACloseFromTheBrowserIsAnsweredWithTheSameCode(): void
    {
        $this->feed(Frames::close(1001, 'going away'));

        try {
            $this->socket->receive(1.0);
            self::fail('a close frame ends the connection');
        } catch (PhycodeException $error) {
            self::assertSame('the browser closed the connection (code 1001)', $error->getMessage());
        }
        $reply = Frames::client($this->peer);
        self::assertSame(0x8, $reply['opcode']);
        self::assertTrue($reply['masked']);
        self::assertSame(1001, $reply['code']);
        self::assertFalse($this->socket->isOpen());
    }

    public function testACloseWithoutACodeIsAnsweredWithNormalClosure(): void
    {
        $this->feed(Frames::server(0x8));

        try {
            $this->socket->receive(1.0);
            self::fail('a close frame ends the connection');
        } catch (PhycodeException $error) {
            self::assertSame('the browser closed the connection (code 1005)', $error->getMessage());
        }
        self::assertSame(1000, Frames::client($this->peer)['code']);
    }

    public function testClosingSendsACloseFrameAndWaitsForTheAnswer(): void
    {
        $this->feed(Frames::server(0x1, 'late'), Frames::close(1000));

        $this->socket->close();

        $frame = Frames::client($this->peer);
        self::assertSame([0x8, true, 1000], [$frame['opcode'], $frame['masked'], $frame['code']]);
        self::assertFalse($this->socket->isOpen());
        self::assertNull(Frames::client($this->peer), 'the connection is shut after the close handshake');
    }

    public function testClosingGivesUpWaitingForAPeerThatSaysNothing(): void
    {
        $started = microtime(true);

        $this->socket->close();

        self::assertFalse($this->socket->isOpen());
        self::assertLessThan(3.0, microtime(true) - $started);
        $this->socket->close();
    }

    public function testNothingIsSentAfterTheCloseFrame(): void
    {
        $this->feed(Frames::close(1000));
        try {
            $this->socket->receive(1.0);
        } catch (PhycodeException) {
        }

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('the connection to the browser is closed');

        $this->socket->send('too late');
    }

    public static function violations(): iterable
    {
        yield 'a masked frame from the server' => [Frames::server(0x1, 'x', masked: true), 1002, 'masked'];
        yield 'a reserved bit' => [Frames::server(0x1, 'x', reserved: 4), 1002, 'reserved bits'];
        yield 'an opcode that is not defined' => [Frames::server(0x3, 'x'), 1002, 'opcode'];
        yield 'a control opcode that is not defined' => [Frames::server(0xB, 'x'), 1002, 'opcode'];
        yield 'a fragmented ping' => [Frames::server(0x9, 'x', fin: false), 1002, 'control frame'];
        yield 'a ping longer than 125 bytes' => [Frames::server(0x9, str_repeat('x', 126)), 1002, 'control frame'];
        yield 'a length that could be shorter' => [Frames::server(0x1, 'short', lengthBytes: 2), 1002, 'shortest form'];
        yield 'a long length that could be shorter' => [Frames::server(0x1, str_repeat('x', 200), lengthBytes: 8), 1002, 'shortest form'];
        yield 'a continuation with nothing to continue' => [Frames::server(0x0, 'x'), 1002, 'nothing to continue'];
        yield 'a new message inside a fragmented one' => [Frames::server(0x1, 'a', fin: false) . Frames::server(0x1, 'b'), 1002, 'before the last one ended'];
        yield 'a close with a body of one byte' => [Frames::server(0x8, 'x'), 1002, 'one-byte body'];
        yield 'a binary message' => [Frames::server(0x2, 'x'), 1003, 'binary'];
        yield 'text that is not UTF-8' => [Frames::server(0x1, "caf\xE9"), 1007, 'UTF-8'];
        yield 'text cut in the middle of a character' => [Frames::server(0x1, "caf\xC3"), 1007, 'UTF-8'];
        yield 'a length with the top bit set' => ["\x81\x7F\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF", 1002, 'shortest form'];
    }

    #[DataProvider('violations')]
    public function testAProtocolViolationDropsTheConnectionWithTheRightCode(string $bytes, int $code, string $reason): void
    {
        $this->feed($bytes);

        try {
            $this->socket->receive(1.0);
            self::fail('the connection should have been dropped');
        } catch (PhycodeException $error) {
            self::assertStringStartsWith('the connection to the browser was dropped, because ', $error->getMessage());
            self::assertStringContainsString($reason, $error->getMessage());
        }
        self::assertFalse($this->socket->isOpen());
        $frame = Frames::client($this->peer);
        self::assertSame(0x8, $frame['opcode']);
        self::assertSame($code, $frame['code']);
    }

    public function testAnOversizeMessageIsRefusedAsSoonAsItsLengthIsKnown(): void
    {
        $this->open(1000);
        $this->feed(substr(Frames::server(0x1, str_repeat('x', 5000)), 0, 40));

        try {
            $this->socket->receive(1.0);
            self::fail('the message is larger than the limit');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('a message is larger than 1000 bytes', $error->getMessage());
        }
        self::assertSame(1009, Frames::client($this->peer)['code']);
    }

    public function testFragmentsThatTogetherExceedTheLimitAreRefused(): void
    {
        $this->open(1000);
        $this->feed(Frames::server(0x1, str_repeat('x', 600), fin: false), Frames::server(0x0, str_repeat('y', 600)));

        try {
            $this->socket->receive(1.0);
            self::fail('the message is larger than the limit');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('larger than 1000 bytes', $error->getMessage());
        }
        self::assertSame(1009, Frames::client($this->peer)['code']);
    }

    public function testAMessageExactlyAtTheLimitIsAccepted(): void
    {
        $this->open(1000);
        $this->feed(Frames::server(0x1, str_repeat('x', 1000)));

        self::assertSame(1000, strlen((string) $this->socket->receive(1.0)));
    }

    public function testAConnectionThatEndsWithoutAGoodbyeIsAnError(): void
    {
        $this->feed(Frames::server(0x1, 'last'));
        fclose($this->peer);

        self::assertSame('last', $this->socket->receive(1.0));
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('the browser closed the connection without saying goodbye');

        $this->socket->receive(1.0);
    }

    public function testAnAddressThatIsNotAWebSocketAddressIsRefusedAtOnce(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('cannot connect to http://127.0.0.1:9222/');

        WebSocket::connect('http://127.0.0.1:9222/');
    }

    public function testNothingListeningMeansACleanError(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($probe, false), (int) strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage("cannot connect to the browser at 127.0.0.1:{$port}");

        WebSocket::connect("ws://127.0.0.1:{$port}/devtools/browser/x", 1.0);
    }
}
