<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Browser;

final class Frames
{
    public static function server(int $opcode, string $payload = '', bool $fin = true, int $reserved = 0, ?int $lengthBytes = null, bool $masked = false): string
    {
        $length = strlen($payload);
        $form = $lengthBytes ?? ($length < 126 ? 0 : ($length < 65536 ? 2 : 8));
        $first = chr(($fin ? 0x80 : 0) | ($reserved << 4) | $opcode);
        $mask = $masked ? "\x01\x02\x03\x04" : '';
        $second = $masked ? 0x80 : 0;
        $head = match ($form) {
            0 => chr($second | $length),
            2 => chr($second | 126) . pack('n', $length),
            default => chr($second | 127) . pack('J', $length),
        };

        return $first . $head . $mask . ($masked ? $payload ^ str_repeat($mask, intdiv($length, 4) + 1) : $payload);
    }

    public static function close(int $code, string $reason = ''): string
    {
        return self::server(0x8, pack('n', $code) . $reason);
    }

    public static function client($stream): ?array
    {
        $head = self::exactly($stream, 2);
        if ($head === null) {
            return null;
        }
        $length = ord($head[1]) & 0x7F;
        $form = $length < 126 ? 0 : ($length === 126 ? 2 : 8);
        if ($form === 2) {
            $length = unpack('n', (string) self::exactly($stream, 2))[1];
        } elseif ($form === 8) {
            $length = unpack('J', (string) self::exactly($stream, 8))[1];
        }
        $masked = (ord($head[1]) & 0x80) !== 0;
        $mask = $masked ? (string) self::exactly($stream, 4) : '';
        $payload = (string) self::exactly($stream, $length);
        $raw = $payload;
        if ($masked) {
            $payload ^= str_repeat($mask, intdiv($length, 4) + 1);
        }

        return [
            'fin' => (ord($head[0]) & 0x80) !== 0,
            'opcode' => ord($head[0]) & 0x0F,
            'masked' => $masked,
            'mask' => $mask,
            'form' => $form,
            'raw' => $raw,
            'payload' => $payload,
            'code' => strlen($payload) >= 2 ? unpack('n', $payload)[1] : null,
        ];
    }

    private static function exactly($stream, int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                return $data === '' && $length > 0 ? null : $data;
            }
            $data .= $chunk;
        }

        return $data;
    }
}
