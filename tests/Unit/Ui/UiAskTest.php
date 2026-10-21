<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ui;

use Phycode\Tui\TuiUi;
use Phycode\Ui\ConsoleUi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UiAskTest extends TestCase
{
    public static function typed(): iterable
    {
        yield 'the number of an option' => ["2\n", ['yes', 'no'], false, 'no'];
        yield 'the text of an option' => ["yes\n", ['yes', 'no'], false, 'yes'];
        yield 'an option with spaces around it' => ["  no  \n", ['yes', 'no'], false, 'no'];
        yield 'an answer that is no option is asked again' => ["maybe\n1\n", ['yes', 'no'], false, 'yes'];
        yield 'a number out of range is asked again' => ["7\n2\n", ['yes', 'no'], false, 'no'];
        yield 'free text when it is allowed' => ["maybe\n", ['yes', 'no'], true, 'maybe'];
        yield 'free text without options' => ["Dana\n", [], true, 'Dana'];
        yield 'only Enter' => ["\n", ['yes'], false, null];
        yield 'the end of the input' => ['', ['yes'], false, null];
    }

    #[DataProvider('typed')]
    public function testTheAnswerIsReadFromTheInputOnATerminal(string $typed, array $options, bool $free, ?string $expected): void
    {
        [$ui] = self::console($typed, true);

        self::assertSame($expected, $ui->ask('Continue?', $options, $free));
    }

    public function testTheQuestionAndItsNumberedOptionsAreWrittenOnTheErrorStream(): void
    {
        [$ui, $err] = self::console("1\n", true);

        $ui->ask('Overwrite the file?', ['yes', 'no'], false);

        self::assertSame("Overwrite the file?\n  1) yes\n  2) no\n> ", self::written($err));
    }

    public function testWithoutAPersonAtTheInputTheQuestionIsNotAskedAndNothingIsRead(): void
    {
        [$ui, $err, $in] = self::console("1\n", false);

        self::assertNull($ui->ask('Overwrite the file?', ['yes', 'no'], false));
        self::assertSame('', self::written($err));
        self::assertSame("1\n", stream_get_contents($in), 'the input is left alone');
    }

    public function testAQuestionWithoutOptionsOrFreeTextIsNotAsked(): void
    {
        [$ui, $err] = self::console("x\n", true);

        self::assertNull($ui->ask('Which one?', [], false));
        self::assertSame('', self::written($err));
    }

    public function testTheFullScreenUiGivesNoAnswer(): void
    {
        $ui = (new \ReflectionClass(TuiUi::class))->newInstanceWithoutConstructor();

        self::assertNull($ui->ask('Continue?', ['yes'], true));
    }

    private static function console(string $input, bool $terminal): array
    {
        $in = fopen('php://memory', 'w+');
        fwrite($in, $input);
        rewind($in);
        $err = fopen('php://memory', 'w+');

        return [new ConsoleUi(fopen('php://memory', 'w+'), $err, $in, false, false, false, $terminal), $err, $in];
    }

    private static function written($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
