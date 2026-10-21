<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

use Phycode\Security\ConfirmRequest;
use Phycode\Security\Decision;
use Phycode\Ui\Ui;

final class RecordingUi implements Ui
{
    public array $events = [];

    public array $decisions = [];

    public array $requests = [];

    public array $diffs = [];

    public array $answers = [];

    public bool $terminal = true;

    public function text(string $chunk): void
    {
        $this->events[] = ['text', $chunk];
    }

    public function output(string $text): void
    {
        $this->events[] = ['output', $text];
    }

    public function panel(string $title, string $text): void
    {
        $this->events[] = ['panel', $title, $text];
    }

    public function listPanel(string $title, array $items, string $hint = ''): void
    {
        $this->events[] = ['list_panel', $title, $items, $hint];
    }

    public function toolStart(string $agent, int $depth, string $tool, string $summary): void
    {
        $this->events[] = ['tool_start', $agent, $tool];
    }

    public function toolEnd(string $agent, int $depth, string $tool, bool $ok, string $summary, ?array $diff = null): void
    {
        $this->events[] = ['tool_end', $tool, $ok];
        $this->diffs[] = ['tool' => $tool, 'diff' => $diff];
    }

    public function subagentStart(string $parent, string $agent, string $task): void
    {
        $this->events[] = ['subagent_start', $agent];
    }

    public function subagentEnd(string $parent, string $agent, string $status, string $summary): void
    {
        $this->events[] = ['subagent_end', $agent, $status];
    }

    public function notice(string $level, string $message): void
    {
        $this->events[] = ['notice', $level, $message];
    }

    public function confirm(ConfirmRequest $request): Decision
    {
        $this->events[] = ['confirm', $request->tool, $request->agent];
        $this->requests[] = $request;

        return array_shift($this->decisions) ?? Decision::Deny;
    }

    public function ask(string $question, array $options, bool $allowFreeText): ?string
    {
        $this->events[] = ['ask', $question, $options];

        return array_shift($this->answers);
    }

    public function interactive(): bool
    {
        return $this->terminal;
    }

    public function startedTools(): array
    {
        return array_values(array_map(static fn (array $event): string => (string) $event[2], array_filter(
            $this->events,
            static fn (array $event): bool => $event[0] === 'tool_start',
        )));
    }
}
