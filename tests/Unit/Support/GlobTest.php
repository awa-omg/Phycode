<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Support;

use Phycode\Support\Glob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GlobTest extends TestCase
{
    public static function cases(): iterable
    {
        yield 'name in any folder' => ['*.php', 'src/Deep/Foo.php', true];
        yield 'name at the top' => ['*.php', 'Foo.php', true];
        yield 'other extension' => ['*.php', 'src/Foo.phpt', false];
        yield 'star stays in one folder' => ['src/*.php', 'src/Deep/Foo.php', false];
        yield 'star in one folder' => ['src/*.php', 'src/Foo.php', true];
        yield 'double star any depth' => ['src/**/*.php', 'src/A/B/Foo.php', true];
        yield 'double star zero folders' => ['src/**/*.php', 'src/Foo.php', true];
        yield 'double star first' => ['**/Foo.php', 'Foo.php', true];
        yield 'double star first deep' => ['**/Foo.php', 'a/b/Foo.php', true];
        yield 'double star at the end' => ['src/**', 'src/a/b.txt', true];
        yield 'double star wrong root' => ['src/**/*.php', 'lib/Foo.php', false];
        yield 'question mark' => ['f?o.txt', 'foo.txt', true];
        yield 'question mark is not a slash' => ['a?b', 'a/b', false];
        yield 'alternatives' => ['*.{js,ts}', 'x/app.ts', true];
        yield 'alternatives miss' => ['*.{js,ts}', 'x/app.css', false];
        yield 'character class' => ['file[0-9].log', 'file7.log', true];
        yield 'negated class' => ['file[!0-9].log', 'file7.log', false];
        yield 'negated class match' => ['file[!0-9].log', 'filex.log', true];
        yield 'dots are literal' => ['a.b', 'axb', false];
        yield 'leading dot slash' => ['./src/*.php', 'src/Foo.php', true];
        yield 'regex characters are literal' => ['a+b(1).txt', 'a+b(1).txt', true];
        yield 'escaped star' => ['a\*b', 'a*b', true];
        yield 'escaped star is not a wildcard' => ['a\*b', 'axb', false];
        yield 'unclosed bracket is literal' => ['a[b', 'a[b', true];
        yield 'unclosed brace is literal' => ['a{b', 'a{b', true];
        yield 'empty class bracket' => ['[]', '[]', true];
    }

    #[DataProvider('cases')]
    public function testAPatternMatchesPathsTheWayShellsDo(string $pattern, string $path, bool $expected): void
    {
        self::assertSame($expected, Glob::matches($pattern, $path));
    }

    public function testAnEmptyOrHugeOrNastyPatternMatchesNothing(): void
    {
        self::assertFalse(Glob::valid(''));
        self::assertFalse(Glob::valid(str_repeat('a', 201)));
        self::assertFalse(Glob::valid(str_repeat('**/', 7) . 'x'));
        self::assertFalse(Glob::valid("a\0b"));
        self::assertFalse(Glob::matches('', 'a'));
        self::assertTrue(Glob::valid(str_repeat('**/', 6) . 'x'));
    }

    public function testAHugePathWithManyDoubleStarsEndsQuicklyWithoutAMatch(): void
    {
        $started = microtime(true);
        $result = Glob::matches('**/**/**/**/**/**/zzz', str_repeat('a/', 400) . 'b');

        self::assertFalse($result);
        self::assertLessThan(2.0, microtime(true) - $started);
    }
}
