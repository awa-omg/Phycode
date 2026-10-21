<?php

declare(strict_types=1);

namespace Phycode\Ui;

use Phycode\Security\ConfirmRequest;
use Phycode\Security\Decision;
use Phycode\Support\Text;

final class ConsoleUi implements Ui
{
    private const FALLBACK_COLUMNS = 120;

    private readonly bool $terminal;

    private readonly ?bool $inputTerminal;

    private bool $midLine = false;

    public function __construct(
        private $out,
        private $err,
        private $in,
        private readonly bool $streamText,
        private readonly bool $color,
        ?bool $terminal = null,
        ?bool $inputTerminal = null,
    ) {
        $this->terminal = $terminal ?? posix_isatty($err);
        $this->inputTerminal = $inputTerminal;
    }

    public function text(string $chunk): void
    {
        if ($this->streamText) {
            $this->write($this->out, TerminalText::clean($chunk));
        }
    }

    public function output(string $text): void
    {
        $this->write($this->out, TerminalText::clean($text) . "\n");
    }

    public function panel(string $title, string $text): void
    {
        $this->output(PanelText::text($title, $text));
    }

    public function listPanel(string $title, array $items, string $hint = ''): void
    {
        $this->output(PanelText::list($title, $items, $hint));
    }

    public function toolStart(string $agent, int $depth, string $tool, string $summary): void
    {
        $this->openLine(self::fitLine(self::prefix($agent, $depth), '> ' . $summary, $this->columns()));
    }

    public function toolEnd(string $agent, int $depth, string $tool, bool $ok, string $summary, ?array $diff = null): void
    {
        $head = self::prefix($agent, $depth) . '  ';
        $this->line(self::fitLine($head, ($ok ? 'ok: ' : 'error: ') . $summary, $this->columns()), dim: true);
    }

    public function subagentStart(string $parent, string $agent, string $task): void
    {
        $this->line("+ subagent {$agent}: " . Text::oneLine($task, 160), bold: true);
    }

    public function subagentEnd(string $parent, string $agent, string $status, string $summary): void
    {
        $this->line("- subagent {$agent} {$status}: " . Text::oneLine($summary, 160), bold: true);
    }

    public function notice(string $level, string $message): void
    {
        $this->line("phycode {$level}: {$message}", dim: $level === 'info');
    }

    public function confirm(ConfirmRequest $request): Decision
    {
        $head = self::prefix($request->agent, $request->depth);
        $question = self::wrapInFull($head . self::approvalText($request->tool, $request->summary), $this->columns());
        if ($this->midLine) {
            $this->write($this->err, $this->terminal ? "\r\033[2K" : "\n");
        }
        $this->write($this->err, $question . "\n  [y] once  [a] always this session  [n] no: ");
        $this->midLine = true;
        $line = fgets($this->in);
        $this->midLine = $line === false;
        $answer = strtolower(trim((string) $line));

        return match ($answer) {
            'y', 'yes' => Decision::AllowOnce,
            'a', 'always' => Decision::AllowSession,
            default => Decision::Deny,
        };
    }

    public function ask(string $question, array $options, bool $allowFreeText): ?string
    {
        if (!$this->interactive() || ($options === [] && !$allowFreeText)) {
            return null;
        }
        $options = array_values($options);
        if ($this->midLine) {
            $this->write($this->err, $this->terminal ? "\r\033[2K" : "\n");
        }
        $this->write($this->err, TerminalText::clean($question) . "\n");
        foreach ($options as $index => $option) {
            $this->write($this->err, '  ' . ($index + 1) . ') ' . TerminalText::clean($option) . "\n");
        }
        while (true) {
            $this->write($this->err, '> ');
            $this->midLine = true;
            $line = fgets($this->in);
            $this->midLine = $line === false;
            if ($line === false) {
                return null;
            }
            $answer = trim($line);
            if ($answer === '') {
                return null;
            }
            if (ctype_digit($answer) && (int) $answer >= 1 && (int) $answer <= count($options)) {
                return $options[(int) $answer - 1];
            }
            if (in_array($answer, $options, true) || $allowFreeText) {
                return $answer;
            }
            $this->write($this->err, 'phycode: answer with a number from 1 to ' . count($options) . "\n");
        }
    }

    public function interactive(): bool
    {
        return $this->inputTerminal ?? posix_isatty($this->in);
    }

    public static function approvalText(string $tool, string $summary): string
    {
        return 'Allow ' . (str_starts_with($summary, $tool) ? $summary : "{$tool}: {$summary}") . '?';
    }

    public static function fitLine(string $head, string $text, ?int $columns): string
    {
        return $head . Text::oneLine(TerminalText::clean($text), max(1, ($columns ?? self::FALLBACK_COLUMNS) - mb_strlen($head)));
    }

    public static function wrapInFull(string $text, ?int $columns): string
    {
        $text = str_replace("\t", '    ', mb_scrub(TerminalText::clean($text), 'UTF-8'));

        return implode("\n", TerminalText::wrap($text, $columns ?? self::FALLBACK_COLUMNS));
    }

    private static function prefix(string $agent, int $depth): string
    {
        return str_repeat('  ', $depth) . ($depth > 0 ? '[' . TerminalText::clean($agent) . '] ' : '');
    }

    private function columns(): ?int
    {
        if (!$this->terminal) {
            return null;
        }
        $size = trim((string) shell_exec('stty size 2>/dev/null'));

        return preg_match('/^\d+ (\d+)$/', $size, $match) === 1 && (int) $match[1] > 0 ? (int) $match[1] : null;
    }

    private function openLine(string $message): void
    {
        $this->endOpenLine();
        $this->write($this->err, $message);
        $this->midLine = true;
    }

    private function line(string $message, bool $dim = false, bool $bold = false): void
    {
        $this->endOpenLine();
        $style = '';
        if ($this->color && $dim) {
            $style = "\033[2m";
        } elseif ($this->color && $bold) {
            $style = "\033[1m";
        }
        $reset = $style !== '' ? "\033[0m" : '';
        $this->write($this->err, $style . TerminalText::clean($message) . $reset . "\n");
    }

    private function endOpenLine(): void
    {
        if ($this->midLine) {
            $this->write($this->err, "\n");
            $this->midLine = false;
        }
    }

    private function write($stream, string $text): void
    {
        fwrite($stream, $text);
        fflush($stream);
    }
}
