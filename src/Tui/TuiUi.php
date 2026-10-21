<?php

declare(strict_types=1);

namespace Phycode\Tui;

use Phycode\Security\ConfirmRequest;
use Phycode\Security\Decision;
use Phycode\Support\Text;
use Phycode\Ui\PanelText;
use Phycode\Ui\Ui;

final class TuiUi implements Ui
{
    private array $transcript = [];
    private ?string $prompt = null;
    private bool $interrupted = false;
    private $repaint = null;
    private $pump = null;

    public function __construct(private readonly Terminal $terminal)
    {
    }

    public function attach(callable $repaint, callable $pump): void
    {
        $this->repaint = $repaint;
        $this->pump = $pump;
    }

    public function transcript(): array
    {
        return $this->transcript;
    }

    public function prompt(): ?string
    {
        return $this->prompt;
    }

    public function addUser(string $text): void
    {
        $this->add('user', '> ' . $text);
    }

    public function requestInterrupt(): void
    {
        $this->interrupted = true;
    }

    public function resetInterrupt(): void
    {
        $this->interrupted = false;
    }

    public function text(string $chunk): void
    {
        $chunk = Screen::sanitize($chunk);
        $last = array_key_last($this->transcript);
        if ($last !== null && $this->transcript[$last]['style'] === 'assistant') {
            $this->transcript[$last]['text'] .= $chunk;
            $this->refresh();
        } else {
            $this->add('assistant', $chunk);
        }
        $this->pumpKeys();
    }

    public function output(string $text): void
    {
        $this->add('plain', $text);
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
        $this->add('tool', $this->indent($agent, $depth) . '> ' . Text::oneLine($summary, 200));
    }

    public function toolEnd(string $agent, int $depth, string $tool, bool $ok, string $summary, ?array $diff = null): void
    {
        $this->add('tool', $this->indent($agent, $depth) . ($ok ? '  ok: ' : '  error: ') . Text::oneLine($summary, 200));
    }

    public function subagentStart(string $parent, string $agent, string $task): void
    {
        $this->add('sub', "+ subagent {$agent}: " . Text::oneLine($task, 200));
    }

    public function subagentEnd(string $parent, string $agent, string $status, string $summary): void
    {
        $this->add('sub', "- subagent {$agent} {$status}: " . Text::oneLine($summary, 200));
    }

    public function notice(string $level, string $message): void
    {
        $this->add($level, "phycode {$level}: {$message}");
    }

    public function confirm(ConfirmRequest $request): Decision
    {
        $who = $request->depth > 0 ? " (subagent {$request->agent})" : '';
        $this->prompt = "Allow {$request->tool}{$who}? {$request->summary}   [y] once  [a] session  [n] no";
        $this->refresh();
        try {
            while (!$this->interrupted) {
                foreach (Keys::decode($this->terminal->read(0.2)) as $key) {
                    if ($key['name'] === 'char' && in_array(strtolower($key['text']), ['y', 'a', 'n'], true)) {
                        return match (strtolower($key['text'])) {
                            'y' => Decision::AllowOnce,
                            'a' => Decision::AllowSession,
                            default => Decision::Deny,
                        };
                    }
                    if ($key['name'] === 'escape' || $key['name'] === 'ctrl-c') {
                        return Decision::Deny;
                    }
                }
            }

            return Decision::Deny;
        } finally {
            $this->prompt = null;
            $this->refresh();
        }
    }

    public function ask(string $question, array $options, bool $allowFreeText): ?string
    {
        return null;
    }

    public function interactive(): bool
    {
        return true;
    }

    private function add(string $style, string $text): void
    {
        $this->transcript[] = ['style' => $style, 'text' => Screen::sanitize($text)];
        $this->refresh();
    }

    private function indent(string $agent, int $depth): string
    {
        return str_repeat('  ', $depth) . ($depth > 0 ? "[{$agent}] " : '');
    }

    private function refresh(): void
    {
        if ($this->repaint !== null) {
            ($this->repaint)();
        }
    }

    private function pumpKeys(): void
    {
        if ($this->pump !== null) {
            ($this->pump)();
        }
    }
}
