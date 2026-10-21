<?php

declare(strict_types=1);

namespace Phycode\Rpc;

use Phycode\Security\ConfirmRequest;
use Phycode\Security\Decision;
use Phycode\Support\Cancellation;
use Phycode\Support\Text;
use Phycode\Ui\Ui;

final class RpcUi implements Ui
{
    private const LINE_CHARS = 200;
    private const WAIT_SECONDS = 0.5;

    private const REFUSED = [
        'list_models', 'set_model', 'favorite', 'custom_model', 'add_provider', 'set_provider_key', 'remove_provider', 'detect_endpoints',
        'config_set', 'session_switch', 'session_new', 'set_effort', 'set_context', 'compact', 'deepresearch',
        'add_search_provider', 'update_search_provider', 'remove_search_provider', 'set_search_provider',
    ];

    private $primary = null;
    private $cancelSession = null;
    private array $held = [];
    private bool $started = false;
    private bool $turning = false;
    private bool $cancelled = false;
    private bool $quit = false;
    private int $toolIds = 0;
    private int $subagentIds = 0;
    private int $confirmIds = 0;
    private int $askIds = 0;
    private bool $polling = false;
    private $tick = null;
    private $relay = null;
    private $interrupted = null;
    private array $openTools = [];
    private array $openSubagents = [];
    private array $parents = [];

    public function __construct(private readonly JsonLines $channel)
    {
    }

    public function attach(callable $primary, callable $cancelSession): void
    {
        $this->primary = $primary;
        $this->cancelSession = $cancelSession;
    }

    public function attachServer(callable $tick, callable $relay, ?callable $interrupted = null): void
    {
        $this->tick = $tick;
        $this->relay = $relay;
        $this->interrupted = $interrupted;
    }

    public function start(array $ready): void
    {
        Cancellation::setPoll(fn () => $this->poll());
        $this->channel->send($ready);
        $this->started = true;
        foreach ($this->held as $event) {
            $this->channel->send($event);
        }
        $this->held = [];
    }

    public function beginTurn(): void
    {
        $this->turning = true;
        $this->cancelled = false;
        $this->send(['type' => 'turn_start']);
    }

    public function endTurn(string $status, ?string $reason, array $changes, array $usage, ?string $branch): void
    {
        $this->turning = false;
        $this->send([
            'type' => 'turn_end', 'status' => $status, 'reason' => $reason, 'changes' => $changes,
            'usage' => $usage, 'branch' => $branch,
        ]);
    }

    public function quitRequested(): bool
    {
        return $this->quit;
    }

    public function wasCancelled(): bool
    {
        return $this->cancelled;
    }

    public function tick(): void
    {
        $this->poll();
    }

    public function interrupt(): void
    {
        $this->cancelled = true;
        if ($this->cancelSession !== null) {
            ($this->cancelSession)();
        }
        if ($this->interrupted !== null) {
            ($this->interrupted)();
        }
    }

    public function text(string $chunk): void
    {
        $this->poll();
        $this->send(['type' => 'text', 'agent' => $this->primary !== null ? ($this->primary)() : '', 'chunk' => $chunk]);
    }

    public function output(string $text): void
    {
        $this->poll();
        $this->send(['type' => 'output', 'text' => $text]);
    }

    public function panel(string $title, string $text): void
    {
        $this->poll();
        $this->send(['type' => 'panel', 'title' => $title, 'kind' => 'text', 'text' => $text]);
    }

    public function listPanel(string $title, array $items, string $hint = ''): void
    {
        $this->poll();
        $this->send(['type' => 'panel', 'title' => $title, 'kind' => 'list', 'items' => $items, 'hint' => $hint]);
    }

    public function toolStart(string $agent, int $depth, string $tool, string $summary): void
    {
        $this->poll();
        $id = 't' . ++$this->toolIds;
        $this->openTools[$depth][] = ['id' => $id, 'key' => $tool];
        $this->send([
            'type' => 'tool_start', 'id' => $id, 'agent' => $agent, 'depth' => $depth, 'tool' => $tool,
            'summary' => Text::oneLine($summary, self::LINE_CHARS),
        ]);
    }

    public function toolEnd(string $agent, int $depth, string $tool, bool $ok, string $summary, ?array $diff = null): void
    {
        $this->poll();
        $this->openTools[$depth] ??= [];
        $id = self::pop($this->openTools[$depth], $tool) ?? 't' . ++$this->toolIds;
        $this->send([
            'type' => 'tool_end', 'id' => $id, 'ok' => $ok, 'summary' => Text::oneLine($summary, self::LINE_CHARS), 'diff' => $diff,
        ]);
    }

    public function subagentStart(string $parent, string $agent, string $task): void
    {
        $this->poll();
        $id = 's' . ++$this->subagentIds;
        $this->openSubagents[] = ['id' => $id, 'key' => $agent];
        $this->parents[] = $parent;
        $this->send([
            'type' => 'subagent_start', 'id' => $id, 'parent' => $parent, 'agent' => $agent,
            'task' => Text::oneLine($task, self::LINE_CHARS),
        ]);
    }

    public function subagentEnd(string $parent, string $agent, string $status, string $summary): void
    {
        $this->poll();
        $id = self::pop($this->openSubagents, $agent) ?? 's' . ++$this->subagentIds;
        array_pop($this->parents);
        $this->send([
            'type' => 'subagent_end', 'id' => $id, 'agent' => $agent, 'status' => $status,
            'summary' => Text::oneLine($summary, self::LINE_CHARS),
        ]);
    }

    public function notice(string $level, string $message): void
    {
        $this->poll();
        $this->send(['type' => 'notice', 'level' => $level, 'message' => $message]);
    }

    public function event(array $event): void
    {
        $this->poll();
        $this->send($event);
    }

    public function progress(string $id, string $step, string $detail, bool $done): void
    {
        $this->event([
            'type' => 'progress', 'id' => $id, 'step' => Text::oneLine($step, self::LINE_CHARS),
            'detail' => Text::oneLine($detail, self::LINE_CHARS), 'done' => $done,
        ]);
    }

    public function confirm(ConfirmRequest $request): Decision
    {
        if ($this->cancelled) {
            return Decision::Deny;
        }
        $id = 'c' . ++$this->confirmIds;
        $this->send([
            'type' => 'confirm', 'id' => $id, 'tool' => $request->tool, 'category' => $request->category->value,
            'agent' => $request->agent, 'depth' => $request->depth, 'summary' => $request->summary, 'preview' => $request->preview,
        ]);

        while (!$this->cancelled) {
            $command = $this->channel->next(self::WAIT_SECONDS);
            if ($command === null) {
                if ($this->channel->ended()) {
                    $this->interrupt();
                }
                $this->sweep();
                continue;
            }
            if (($command['type'] ?? null) === 'confirm_reply' && ($command['id'] ?? null) === $id) {
                return self::decision($command['decision'] ?? null);
            }
            $this->act($command);
        }

        return Decision::Deny;
    }

    public function ask(string $question, array $options, bool $allowFreeText): ?string
    {
        if ($this->cancelled) {
            return null;
        }
        $id = 'a' . ++$this->askIds;
        $options = array_values($options);
        $this->send(['type' => 'ask', 'id' => $id, 'question' => $question, 'options' => $options, 'allowFreeText' => $allowFreeText]);

        while (!$this->cancelled) {
            $command = $this->channel->next(self::WAIT_SECONDS);
            if ($command === null) {
                if ($this->channel->ended()) {
                    $this->interrupt();
                }
                $this->sweep();
                continue;
            }
            if (($command['type'] ?? null) === 'ask_reply' && ($command['id'] ?? null) === $id) {
                return self::answer($command['answer'] ?? null, $options, $allowFreeText);
            }
            $this->act($command);
        }

        return null;
    }

    public function interactive(): bool
    {
        return true;
    }

    private function poll(): void
    {
        if ($this->polling) {
            return;
        }
        $this->polling = true;
        try {
            if ($this->turning) {
                while (($command = $this->channel->next(0.0)) !== null) {
                    $this->act($command);
                }
                if ($this->channel->ended()) {
                    $this->interrupt();
                }
            }
            $this->runTick();
        } finally {
            $this->polling = false;
        }
    }

    private function sweep(): void
    {
        if ($this->polling) {
            return;
        }
        $this->polling = true;
        try {
            $this->runTick();
        } finally {
            $this->polling = false;
        }
    }

    private function runTick(): void
    {
        if ($this->tick !== null) {
            ($this->tick)();
        }
    }

    private function act(array $command): void
    {
        $was = $this->polling;
        $this->polling = true;
        try {
            $type = $command['type'] ?? null;
            if ($type === 'interrupt') {
                $this->interrupt();
            } elseif ($type === 'quit') {
                $this->quit = true;
                $this->interrupt();
            } elseif ($type === 'ask_reply' || $type === 'confirm_reply') {
                return;
            } elseif ($this->relay !== null && ($this->relay)($command)) {
                return;
            } elseif ($type === 'input') {
                $this->send(['type' => 'notice', 'level' => 'warn', 'message' => 'a turn is already running']);
            } elseif (in_array($type, self::REFUSED, true)) {
                $this->send(['type' => 'notice', 'level' => 'warn', 'message' => 'a turn is running; try again when it finishes']);
            }
        } finally {
            $this->polling = $was;
        }
    }

    private function send(array $event): void
    {
        $boundary = in_array($event['type'] ?? null, ['subagent_start', 'subagent_end'], true);
        if ($this->parents !== [] && !$boundary) {
            $event['parent'] = $this->parents[array_key_last($this->parents)];
        }
        if ($this->started) {
            $this->channel->send($event);
        } else {
            $this->held[] = $event;
        }
    }

    private static function pop(array &$open, string $key): ?string
    {
        for ($index = count($open) - 1; $index >= 0; $index--) {
            if ($open[$index]['key'] === $key) {
                $id = $open[$index]['id'];
                array_splice($open, $index, 1);

                return $id;
            }
        }

        return null;
    }

    private static function answer(mixed $value, array $options, bool $allowFreeText): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $answer = trim($value);
        if ($answer === '' || (!$allowFreeText && !in_array($answer, $options, true))) {
            return null;
        }

        return $answer;
    }

    private static function decision(mixed $value): Decision
    {
        return is_string($value) ? (Decision::tryFrom($value) ?? Decision::Deny) : Decision::Deny;
    }
}
