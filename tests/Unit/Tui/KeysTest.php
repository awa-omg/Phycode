<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tui;

use Phycode\Tui\Keys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeysTest extends TestCase
{
    public static function sequences(): iterable
    {
        yield 'plain text' => ['ok', [['name' => 'char', 'text' => 'o'], ['name' => 'char', 'text' => 'k']]];
        yield 'utf-8 character' => ["\u{e9}", [['name' => 'char', 'text' => "\u{e9}"]]];
        yield 'enter as CR' => ["\r", [['name' => 'enter', 'text' => '']]];
        yield 'enter as LF' => ["\n", [['name' => 'enter', 'text' => '']]];
        yield 'backspace as DEL' => ["\x7f", [['name' => 'backspace', 'text' => '']]];
        yield 'backspace as BS' => ["\x08", [['name' => 'backspace', 'text' => '']]];
        yield 'ctrl-c' => ["\x03", [['name' => 'ctrl-c', 'text' => '']]];
        yield 'ctrl-d' => ["\x04", [['name' => 'ctrl-d', 'text' => '']]];
        yield 'ctrl-u' => ["\x15", [['name' => 'ctrl-u', 'text' => '']]];
        yield 'ctrl-l' => ["\x0c", [['name' => 'ctrl-l', 'text' => '']]];
        yield 'lone escape' => ["\e", [['name' => 'escape', 'text' => '']]];
        yield 'up' => ["\e[A", [['name' => 'up', 'text' => '']]];
        yield 'down' => ["\e[B", [['name' => 'down', 'text' => '']]];
        yield 'right' => ["\e[C", [['name' => 'right', 'text' => '']]];
        yield 'left' => ["\e[D", [['name' => 'left', 'text' => '']]];
        yield 'home as H' => ["\e[H", [['name' => 'home', 'text' => '']]];
        yield 'end as F' => ["\e[F", [['name' => 'end', 'text' => '']]];
        yield 'home as tilde 1' => ["\e[1~", [['name' => 'home', 'text' => '']]];
        yield 'end as tilde 4' => ["\e[4~", [['name' => 'end', 'text' => '']]];
        yield 'delete' => ["\e[3~", [['name' => 'delete', 'text' => '']]];
        yield 'page up' => ["\e[5~", [['name' => 'pageup', 'text' => '']]];
        yield 'page down' => ["\e[6~", [['name' => 'pagedown', 'text' => '']]];
        yield 'up in application mode' => ["\eOA", [['name' => 'up', 'text' => '']]];
        yield 'modified arrow' => ["\e[1;5C", [['name' => 'right', 'text' => '']]];
        yield 'text around a key' => ["a\e[Ab", [['name' => 'char', 'text' => 'a'], ['name' => 'up', 'text' => ''], ['name' => 'char', 'text' => 'b']]];
        yield 'unknown control byte' => ["\x01", [['name' => 'unknown', 'text' => '']]];
    }

    #[DataProvider('sequences')]
    public function testDecodesEveryKnownSequence(string $bytes, array $expected): void
    {
        self::assertSame($expected, Keys::decode($bytes));
    }
}
