<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ui;

use Phycode\Security\ConfirmRequest;
use Phycode\Security\Decision;
use Phycode\Tools\ToolCategory;
use Phycode\Ui\ConsoleUi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsoleUiTest extends TestCase
{
    public static function fittedLines(): iterable
    {
        yield 'short line is kept' => ['  ', 'ok: 0', 80, '  ok: 0'];
        yield 'long line is cut to the width' => ['  ', 'ok: ' . str_repeat('x', 100), 40, '  ok: ' . str_repeat('x', 33) . '…'];
        yield 'unknown width cuts at 120 characters' => ['', str_repeat('x', 300), null, str_repeat('x', 119) . '…'];
        yield 'line breaks and tabs become spaces' => ['', "one\n  two\tthree", 80, 'one two three'];
        yield 'the head is never cut' => ['  [explore]   ', 'error: ' . str_repeat('y', 50), 30, '  [explore]   error: ' . str_repeat('y', 8) . '…'];
        yield 'characters are counted, not bytes' => ['', str_repeat('ñ', 50), 20, str_repeat('ñ', 19) . '…'];
    }

    #[DataProvider('fittedLines')]
    public function testLinesFitTheTerminalWidth(string $head, string $text, ?int $columns, string $line): void
    {
        self::assertSame($line, ConsoleUi::fitLine($head, $text, $columns));
    }

    public static function approvalQuestions(): iterable
    {
        yield 'summary already names the tool' => ['bash', 'bash: pwd', 'Allow bash: pwd?'];
        yield 'run_code summary' => ['run_code', 'run_code python', 'Allow run_code python?'];
        yield 'summary without the tool name' => ['git_write', 'git commit -m x', 'Allow git_write: git commit -m x?'];
    }

    #[DataProvider('approvalQuestions')]
    public function testApprovalNamesTheToolAndSummaryOnce(string $tool, string $summary, string $question): void
    {
        self::assertSame($question, ConsoleUi::approvalText($tool, $summary));
    }

    public static function answers(): iterable
    {
        yield 'y' => ["y\n", Decision::AllowOnce];
        yield 'yes in capitals' => ["YES\n", Decision::AllowOnce];
        yield 'a' => ["a\n", Decision::AllowSession];
        yield 'always' => ["always\n", Decision::AllowSession];
        yield 'n' => ["n\n", Decision::Deny];
        yield 'only Enter' => ["\n", Decision::Deny];
        yield 'anything else' => ["maybe\n", Decision::Deny];
        yield 'end of input' => ['', Decision::Deny];
    }

    #[DataProvider('answers')]
    public function testTheAnswerDecidesTheApproval(string $typed, Decision $expected): void
    {
        [$ui] = self::console($typed);

        self::assertSame($expected, $ui->confirm(self::request('bash: pwd')));
    }

    public function testOnATerminalTheApprovalReplacesTheCallLine(): void
    {
        [$ui, $err] = self::console("y\n", terminal: true);

        $ui->toolStart('build', 0, 'bash', 'bash: pwd');
        $ui->confirm(self::request('bash: pwd'));
        fwrite($err, "y\n");
        $ui->toolEnd('build', 0, 'bash', true, 'exit code: 0 /root/phycode');

        self::assertSame([
            'Allow bash: pwd?',
            '  [y] once  [a] always this session  [n] no: y',
            '  ok: exit code: 0 /root/phycode',
            '',
        ], self::visibleRows(self::written($err)));
    }

    public function testWithoutATerminalTheCallLineStaysAboveTheApproval(): void
    {
        [$ui, $err] = self::console("n\n");

        $ui->toolStart('build', 0, 'bash', 'bash: pwd');
        $ui->confirm(self::request('bash: pwd'));
        fwrite($err, "n\n");
        $ui->toolEnd('build', 0, 'bash', false, 'the user denied this action');

        self::assertSame([
            '> bash: pwd',
            'Allow bash: pwd?',
            '  [y] once  [a] always this session  [n] no: n',
            '  error: the user denied this action',
            '',
        ], self::visibleRows(self::written($err)));
    }

    public function testResultsAreDimAndCallLinesAreNot(): void
    {
        [$ui, $err] = self::console('', color: true);

        $ui->toolStart('build', 0, 'bash', 'bash: pwd');
        $ui->toolEnd('build', 0, 'bash', true, 'exit code: 0');

        self::assertSame("> bash: pwd\n\033[2m  ok: exit code: 0\033[0m\n", self::written($err));
    }

    public function testSubagentCallsAreIndentedUnderTheAgentName(): void
    {
        [$ui, $err] = self::console('');

        $ui->toolStart('explore', 1, 'grep', 'grep: TODO');
        $ui->toolEnd('explore', 1, 'grep', false, 'no matches');

        self::assertSame(['  [explore] > grep: TODO', '  [explore]   error: no matches', ''], self::visibleRows(self::written($err)));
    }

    public function testPanelsArePrintedAsPlainTextOnStdout(): void
    {
        $out = fopen('php://memory', 'w+');
        $ui = new ConsoleUi($out, fopen('php://memory', 'w+'), fopen('php://memory', 'w+'), false, false, false);

        $ui->panel('Status', "version  0.1.0\nprofile  local\n");
        $ui->listPanel('Skills', [
            ['category' => 'Project', 'title' => 'go-testing', 'detail' => 'Go tests.', 'status' => ''],
            ['category' => 'Global', 'title' => 'api', 'detail' => 'REST', 'status' => ''],
            ['category' => 'Project', 'title' => 'web', 'detail' => 'Search', 'status' => 'ok', 'badge' => 'in use'],
        ], 'two skills');

        self::assertSame(
            "Status\nversion  0.1.0\nprofile  local\n"
            . "Skills\nProject\n  go-testing  Go tests.\n  [ok] web" . str_repeat(' ', 9) . "Search  (in use)\n"
            . "Global\n  api  REST\n\ntwo skills\n",
            self::written($out),
        );
    }

    private static function console(string $input, bool $terminal = false, bool $color = false): array
    {
        $in = fopen('php://memory', 'w+');
        fwrite($in, $input);
        rewind($in);
        $err = fopen('php://memory', 'w+');

        return [new ConsoleUi(fopen('php://memory', 'w+'), $err, $in, false, $color, $terminal), $err];
    }

    private static function request(string $summary): ConfirmRequest
    {
        return new ConfirmRequest('bash', ToolCategory::Exec, 'build', 0, $summary);
    }

    private static function written($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private static function visibleRows(string $raw): array
    {
        return array_map(
            static fn (string $row): string => (string) preg_replace('/^.*\r\x1b\[2K/', '', $row),
            explode("\n", $raw),
        );
    }
}
