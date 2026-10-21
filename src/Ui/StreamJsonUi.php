<?php

declare(strict_types=1);

namespace Phycode\Ui;

use Phycode\Security\ConfirmRequest;
use Phycode\Security\Decision;
use Phycode\Support\Json;
use Phycode\Support\Redaction;

final class StreamJsonUi implements Ui
{
    public function __construct(private $out, private array $secrets = [])
    {
    }

    public function useSecrets(array $secrets): void
    {
        $this->secrets = $secrets;
    }

    public function event(string $type, array $fields = []): void
    {
        $line = Redaction::exact(Json::encode(['type' => $type] + $fields), $this->secrets);
        fwrite($this->out, $line . "\n");
        fflush($this->out);
    }

    public function text(string $chunk): void
    {
        $this->event('text', ['text' => $chunk]);
    }

    public function output(string $text): void
    {
        $this->event('notice', ['level' => 'info', 'message' => $text]);
    }

    public function panel(string $title, string $text): void
    {
        $this->event('notice', ['level' => 'info', 'message' => "{$title}\n{$text}"]);
    }

    public function listPanel(string $title, array $items, string $hint = ''): void
    {
        $this->event('notice', ['level' => 'info', 'message' => $title]);
    }

    public function toolStart(string $agent, int $depth, string $tool, string $summary): void
    {
        $this->event('tool_start', ['agent' => $agent, 'depth' => $depth, 'tool' => $tool, 'summary' => $summary]);
    }

    public function toolEnd(string $agent, int $depth, string $tool, bool $ok, string $summary, ?array $diff = null): void
    {
        $this->event('tool_end', ['agent' => $agent, 'depth' => $depth, 'tool' => $tool, 'ok' => $ok, 'summary' => $summary]);
    }

    public function subagentStart(string $parent, string $agent, string $task): void
    {
        $this->event('subagent_start', ['parent' => $parent, 'agent' => $agent, 'task' => $task]);
    }

    public function subagentEnd(string $parent, string $agent, string $status, string $summary): void
    {
        $this->event('subagent_end', ['parent' => $parent, 'agent' => $agent, 'status' => $status, 'summary' => $summary]);
    }

    public function notice(string $level, string $message): void
    {
        $this->event('notice', ['level' => $level, 'message' => $message]);
    }

    public function confirm(ConfirmRequest $request): Decision
    {
        $this->event('notice', ['level' => 'warn', 'message' => "not approved, nobody can answer in this mode: {$request->tool} {$request->summary}"]);

        return Decision::Deny;
    }

    public function ask(string $question, array $options, bool $allowFreeText): ?string
    {
        return null;
    }

    public function interactive(): bool
    {
        return false;
    }
}
