<?php

declare(strict_types=1);

namespace Phycode\Browser;

use Phycode\Support\PhycodeException;

final class WebSocket
{
    public const DEFAULT_MAX_MESSAGE_BYTES = 33_554_432;

    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const MAX_HANDSHAKE_BYTES = 16384;
    private const CONTINUATION = 0x0;
    private const TEXT = 0x1;
    private const BINARY = 0x2;
    private const CLOSE = 0x8;
    private const PING = 0x9;
    private const PONG = 0xA;
    private const MAX_CONTROL_PAYLOAD = 125;
    private const CLOSE_WAIT_SECONDS = 1.0;

    private string $buffer = '';
    private string $fragments = '';
    private bool $fragmenting = false;
    private bool $open = true;
    private bool $closeSent = false;

    private function __construct(private $stream, private readonly int $maxMessageBytes)
    {
        stream_set_chunk_size($this->stream, 65536);
    }

    public static function connect(string $url, float $timeoutSeconds = 5.0, int $maxMessageBytes = self::DEFAULT_MAX_MESSAGE_BYTES): self
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'ws' || !isset($parts['host'], $parts['port'])) {
            throw new PhycodeException("cannot connect to {$url}: a debugging address looks like ws://127.0.0.1:PORT/devtools/browser/ID");
        }
        $stream = @stream_socket_client("tcp://{$parts['host']}:{$parts['port']}", $code, $message, $timeoutSeconds);
        if ($stream === false) {
            throw new PhycodeException("cannot connect to the browser at {$parts['host']}:{$parts['port']}: {$message}");
        }
        $socket = new self($stream, $maxMessageBytes);
        $socket->handshake($parts['host'] . ':' . $parts['port'], ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''), $timeoutSeconds);

        return $socket;
    }

    public static function over($stream, int $maxMessageBytes = self::DEFAULT_MAX_MESSAGE_BYTES): self
    {
        return new self($stream, $maxMessageBytes);
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function send(string $text): void
    {
        if ($this->open && $this->closeSent) {
            throw new PhycodeException('the connection to the browser is closing');
        }
        $this->write(self::TEXT, $text);
    }

    public function receive(float $seconds): ?string
    {
        $deadline = microtime(true) + $seconds;
        while (true) {
            $frame = $this->nextFrame();
            if ($frame === null) {
                if (!$this->fill($deadline)) {
                    return null;
                }
                continue;
            }
            $message = $this->handle($frame['fin'], $frame['opcode'], $frame['payload']);
            if ($message !== null) {
                return $message;
            }
        }
    }

    public function close(int $code = 1000): void
    {
        if (!$this->open) {
            return;
        }
        if (!$this->closeSent) {
            $this->sendClose($code);
        }
        $deadline = microtime(true) + self::CLOSE_WAIT_SECONDS;
        try {
            while ($this->open && microtime(true) < $deadline && $this->receive($deadline - microtime(true)) !== null) {
            }
        } catch (PhycodeException) {
        }
        $this->shutdown();
    }

    private function handshake(string $authority, string $path, float $timeoutSeconds): void
    {
        $key = base64_encode(random_bytes(16));
        $request = "GET {$path} HTTP/1.1\r\nHost: {$authority}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
        $this->writeRaw($request);

        $deadline = microtime(true) + $timeoutSeconds;
        while (($end = strpos($this->buffer, "\r\n\r\n")) === false) {
            if (strlen($this->buffer) > self::MAX_HANDSHAKE_BYTES) {
                $this->shutdown();

                throw new PhycodeException('the browser answered the WebSocket handshake with more than 16 KB of headers');
            }
            if (!$this->fill($deadline)) {
                $this->shutdown();

                throw new PhycodeException("the browser did not answer the WebSocket handshake within {$timeoutSeconds}s");
            }
        }
        $head = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 4);

        $lines = explode("\r\n", $head);
        if (preg_match('~^HTTP/1\.1 101\b~', $lines[0]) !== 1) {
            $this->shutdown();

            throw new PhycodeException('the browser refused the WebSocket handshake: ' . substr($lines[0], 0, 120));
        }
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }
        $accept = base64_encode(sha1($key . self::GUID, true));
        $upgraded = strtolower($headers['upgrade'] ?? '') === 'websocket' && str_contains(strtolower($headers['connection'] ?? ''), 'upgrade');
        if (!$upgraded || ($headers['sec-websocket-accept'] ?? '') !== $accept) {
            $this->shutdown();

            throw new PhycodeException('the WebSocket handshake answer is wrong (Upgrade, Connection or Sec-WebSocket-Accept): this is not a browser debugging port');
        }
    }

    private function nextFrame(): ?array
    {
        $buffer = $this->buffer;
        if (strlen($buffer) < 2) {
            return null;
        }
        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $opcode = $first & 0x0F;
        $length = $second & 0x7F;
        $offset = 2;
        if ($length === 126 || $length === 127) {
            $offset = $length === 126 ? 4 : 10;
            if (strlen($buffer) < $offset) {
                return null;
            }
            $length = $length === 126 ? unpack('n', $buffer, 2)[1] : unpack('J', $buffer, 2)[1];
            if ($length < 0 || $length < ($offset === 4 ? 126 : 65536)) {
                $this->fail(1002, 'a frame length is not in its shortest form');
            }
        }
        if (($first & 0x70) !== 0) {
            $this->fail(1002, 'a frame sets reserved bits');
        }
        if (($second & 0x80) !== 0) {
            $this->fail(1002, 'a frame from the server is masked');
        }
        $control = ($opcode & 0x8) !== 0;
        if ($control && (($first & 0x80) === 0 || $length > self::MAX_CONTROL_PAYLOAD)) {
            $this->fail(1002, 'a control frame is fragmented or longer than 125 bytes');
        }
        if (!in_array($opcode, [self::CONTINUATION, self::TEXT, self::BINARY, self::CLOSE, self::PING, self::PONG], true)) {
            $this->fail(1002, 'a frame has an opcode that is not defined');
        }
        if (!$control && strlen($this->fragments) + $length > $this->maxMessageBytes) {
            $this->fail(1009, "a message is larger than {$this->maxMessageBytes} bytes");
        }
        if (strlen($buffer) < $offset + $length) {
            return null;
        }
        $this->buffer = substr($buffer, $offset + $length);

        return ['fin' => ($first & 0x80) !== 0, 'opcode' => $opcode, 'payload' => substr($buffer, $offset, $length)];
    }

    private function handle(bool $fin, int $opcode, string $payload): ?string
    {
        if ($opcode === self::PING) {
            if (!$this->closeSent) {
                $this->write(self::PONG, $payload);
            }

            return null;
        }
        if ($opcode === self::PONG) {
            return null;
        }
        if ($opcode === self::CLOSE) {
            $this->answerClose($payload);
        }
        if ($opcode === self::BINARY) {
            $this->fail(1003, 'the browser sent a binary message, and the debugging protocol is text');
        }
        if ($opcode === self::TEXT && $this->fragmenting) {
            $this->fail(1002, 'a new message starts before the last one ended');
        }
        if ($opcode === self::CONTINUATION && !$this->fragmenting) {
            $this->fail(1002, 'a continuation frame has nothing to continue');
        }
        $this->fragments .= $payload;
        $this->fragmenting = !$fin;
        if (!$fin) {
            return null;
        }
        $message = $this->fragments;
        $this->fragments = '';
        if (preg_match('//u', $message) !== 1) {
            $this->fail(1007, 'a text message is not valid UTF-8');
        }

        return $message;
    }

    private function answerClose(string $payload): never
    {
        if (strlen($payload) === 1) {
            $this->fail(1002, 'a close frame has a one-byte body');
        }
        $code = strlen($payload) >= 2 ? unpack('n', $payload)[1] : 1005;
        if (!$this->closeSent) {
            $this->sendClose($code === 1005 ? 1000 : $code);
        }
        $this->shutdown();

        throw new PhycodeException("the browser closed the connection (code {$code})");
    }

    private function fail(int $code, string $reason): never
    {
        if ($this->open && !$this->closeSent) {
            $this->sendClose($code);
        }
        $this->shutdown();

        throw new PhycodeException("the connection to the browser was dropped, because {$reason}");
    }

    private function sendClose(int $code): void
    {
        $this->closeSent = true;
        $this->write(self::CLOSE, pack('n', $code));
    }

    private function write(int $opcode, string $payload): void
    {
        if (!$this->open) {
            throw new PhycodeException('the connection to the browser is closed');
        }
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);
        $header .= match (true) {
            $length < 126 => chr(0x80 | $length),
            $length < 65536 => chr(0x80 | 126) . pack('n', $length),
            default => chr(0x80 | 127) . pack('J', $length),
        };
        $mask = random_bytes(4);
        $this->writeRaw($header . $mask . ($payload ^ str_repeat($mask, intdiv($length, 4) + 1)));
    }

    private function writeRaw(string $bytes): void
    {
        $written = @fwrite($this->stream, $bytes);
        if ($written !== strlen($bytes)) {
            $this->shutdown();

            throw new PhycodeException('cannot write to the browser: the connection is gone');
        }
    }

    private function fill(float $deadline): bool
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            return false;
        }
        $read = [$this->stream];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1.0) * 1_000_000));
        if ($ready === false) {
            return true;
        }
        if ($ready === 0) {
            return false;
        }
        $chunk = fread($this->stream, 65536);
        if ($chunk === false || $chunk === '') {
            $this->gone();
        }
        $this->buffer .= $chunk;

        return true;
    }

    private function gone(): never
    {
        $this->shutdown();

        throw new PhycodeException('the browser closed the connection without saying goodbye');
    }

    private function shutdown(): void
    {
        $this->open = false;
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
