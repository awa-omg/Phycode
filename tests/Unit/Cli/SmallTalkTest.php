<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\SmallTalk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmallTalkTest extends TestCase
{
    public static function messages(): iterable
    {
        yield 'hola' => ['hola', true];
        yield 'hola with punctuation' => ['¡Hola!', true];
        yield 'qué tal' => ['¿qué tal?', true];
        yield 'thanks' => ['Thanks.', true];
        yield 'greeting and a presence check' => ['hola, ¿estás ahí?', true];
        yield 'greeting and what it can do' => ['hola, ¿qué puedes hacer?', true];
        yield 'who it is' => ['¿quién eres?', true];
        yield 'greeting plus a file' => ['hola, lee README.md', false];
        yield 'greeting plus a question about files' => ['hola, ¿qué archivos hay?', false];
        yield 'a real task' => ['crea un archivo hello.txt', false];
        yield 'an ok with a request' => ['ok, hazlo', false];
        yield 'empty' => ['   ', false];
        yield 'punctuation only' => ['¿?', false];
    }

    #[DataProvider('messages')]
    public function testOnlyPureSmallTalkIsRecognised(string $message, bool $expected): void
    {
        self::assertSame($expected, SmallTalk::isGreeting($message));
    }
}
