<?php

declare(strict_types=1);

namespace Phycode\Tui;

final class Keys
{
    private const CSI_NAMES = ['A' => 'up', 'B' => 'down', 'C' => 'right', 'D' => 'left', 'H' => 'home', 'F' => 'end'];
    private const TILDE_NAMES = ['1' => 'home', '7' => 'home', '4' => 'end', '8' => 'end', '3' => 'delete', '5' => 'pageup', '6' => 'pagedown'];

    public static function decode(string $bytes): array
    {
        $keys = [];
        $length = strlen($bytes);
        $i = 0;
        while ($i < $length) {
            $byte = $bytes[$i];
            if ($byte === "\e") {
                [$name, $used] = self::escape($bytes, $i);
                $keys[] = ['name' => $name, 'text' => ''];
                $i += $used;
                continue;
            }
            $control = match ($byte) {
                "\r", "\n" => 'enter',
                "\x7f", "\x08" => 'backspace',
                "\x03" => 'ctrl-c',
                "\x04" => 'ctrl-d',
                "\x15" => 'ctrl-u',
                "\x0c" => 'ctrl-l',
                default => null,
            };
            if ($control !== null) {
                $keys[] = ['name' => $control, 'text' => ''];
                $i++;
                continue;
            }
            if (ord($byte) < 0x20) {
                $keys[] = ['name' => 'unknown', 'text' => ''];
                $i++;
                continue;
            }
            $size = self::characterSize(ord($byte));
            $character = substr($bytes, $i, $size);
            $keys[] = strlen($character) === $size && mb_check_encoding($character, 'UTF-8')
                ? ['name' => 'char', 'text' => $character]
                : ['name' => 'unknown', 'text' => ''];
            $i += $size;
        }

        return $keys;
    }

    private static function escape(string $bytes, int $start): array
    {
        $length = strlen($bytes);
        if ($start + 1 >= $length || ($bytes[$start + 1] !== '[' && $bytes[$start + 1] !== 'O')) {
            return ['escape', 1];
        }
        $j = $start + 2;
        $parameters = '';
        while ($j < $length && str_contains('0123456789;', $bytes[$j])) {
            $parameters .= $bytes[$j];
            $j++;
        }
        if ($j >= $length) {
            return ['unknown', $length - $start];
        }
        $final = $bytes[$j];
        $used = $j - $start + 1;
        if ($final === '~') {
            return [self::TILDE_NAMES[explode(';', $parameters)[0]] ?? 'unknown', $used];
        }

        return [self::CSI_NAMES[$final] ?? 'unknown', $used];
    }

    private static function characterSize(int $lead): int
    {
        return match (true) {
            $lead >= 0xF0 && $lead <= 0xF7 => 4,
            $lead >= 0xE0 && $lead <= 0xEF => 3,
            $lead >= 0xC0 && $lead <= 0xDF => 2,
            default => 1,
        };
    }
}
