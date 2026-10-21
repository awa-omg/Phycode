<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ui;

use Phycode\Security\ConfirmRequest;
use Phycode\Security\Decision;
use Phycode\Tools\ToolCategory;
use Phycode\Ui\ConsoleUi;
use PHPUnit\Framework\TestCase;

final class ConsoleUiEscapesTest extends TestCase
{
    private const CLIPBOARD = "\e]52;c;SGVsbG8=\x07";
    private const CLEAR = "\e[2J";
    private const UP = "\e[1A";

    public function testModelTextKeepsItsTextAndLosesItsSequences(): void
    {
        [$ui, $out] = self::console(streamText: true);

        $ui->text('Done ' . self::CLIPBOARD . 'and' . self::CLEAR . ' ' . self::UP . 'fine');

        self::assertSame('Done and fine', self::written($out));
    }

    public function testToolOutputLosesItsSequences(): void
    {
        [$ui, $out] = self::console();

        $ui->output(self::CLEAR . 'table' . self::CLIPBOARD . "\nrow" . self::UP);

        self::assertSame("table\nrow\n", self::written($out));
    }

    public function testNoticesAndSubagentLinesLoseTheirSequences(): void
    {
        [$ui, , $err] = self::console();

        $ui->notice('warn' . self::CLEAR, 'a' . self::UP . 'b' . self::CLIPBOARD . 'c');
        $ui->subagentStart('build', 'explore' . self::UP, 'look' . self::CLEAR . ' around');
        $ui->subagentEnd('build', 'explore', 'done' . self::CLIPBOARD, 'found' . self::UP);

        self::assertSame("phycode warn: abc\n+ subagent explore: look around\n- subagent explore done: found\n", self::written($err));
    }

    public function testToolLinesLoseTheirSequencesAndKeepTheirResult(): void
    {
        [$ui, , $err] = self::console();

        $ui->toolStart('build', 0, 'bash', 'bash: echo' . self::CLIPBOARD . ' hi' . self::CLEAR);
        $ui->toolEnd('build', 0, 'bash', true, 'exit code: 0' . self::UP);

        self::assertSame("> bash: echo hi\n  ok: exit code: 0\n", self::written($err));
    }

    public function testAnApprovalQuestionLosesItsSequencesAndTheAnswerStillCounts(): void
    {
        [$ui, , $err] = self::console(input: "y\n");

        $decision = $ui->confirm(new ConfirmRequest('bash', ToolCategory::Exec, 'build', 0, 'printf x' . self::UP . self::CLEAR . ' | sh' . self::CLIPBOARD));

        self::assertSame(Decision::AllowOnce, $decision);
        self::assertSame("Allow bash: printf x | sh?\n  [y] once  [a] always this session  [n] no: ", self::written($err));
    }

    public function testAQuestionAndItsOptionsLoseTheirSequences(): void
    {
        [$ui, , $err] = self::console(input: "2\n", inputTerminal: true);

        self::assertSame('no', $ui->ask('Overwrite' . self::CLEAR . '?', ['yes' . self::UP, 'no'], false));
        self::assertSame("Overwrite?\n  1) yes\n  2) no\n> ", self::written($err));
    }

    private static function console(bool $streamText = false, string $input = '', bool $inputTerminal = false): array
    {
        $in = fopen('php://memory', 'w+');
        fwrite($in, $input);
        rewind($in);
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        return [new ConsoleUi($out, $err, $in, $streamText, false, false, $inputTerminal), $out, $err];
    }

    private static function written($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
