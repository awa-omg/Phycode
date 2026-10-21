<?php

declare(strict_types=1);

namespace Phycode\Acp;

use Phycode\Security\ConfirmRequest;
use Phycode\Security\Decision;
use Phycode\Support\Text;
use Phycode\Tools\TodoList;
use Phycode\Ui\Ui;

final class AcpUi implements Ui
{
    private const LINE_CHARS = 200;
    private const KINDS = [
        'read' => 'read', 'list' => 'read', 'repo_map' => 'read', 'grep' => 'search', 'edit' => 'edit', 'write' => 'edit', 'move' => 'move', 'delete' => 'delete',
        'bash' => 'execute', 'run_code' => 'execute', 'ssh' => 'execute', 'ssh_copy' => 'execute', 'job' => 'execute', 'deploy' => 'execute',
        'web_fetch' => 'fetch', 'web_search' => 'fetch', 'git_read' => 'read', 'git_write' => 'edit', 'git_remote' => 'execute', 'gh_read' => 'fetch', 'gh_write' => 'execute',
        'todo' => 'think', 'task' => 'other',
    ];

    private ?string $sessionId = null;
    private array $held = [];
    private int $toolIds = 0;
    private array $open = [];
    private ?string $lastTool = null;
    private bool $cancelled = false;
    private ?\Closure $todos = null;

    public function __construct(private readonly AcpServer $server)
    {
    }

    public function bind(string $sessionId, \Closure $todos): void
    {
        $this->sessionId = $sessionId;
        $this->todos = $todos;
        foreach ($this->held as $update) {
            $this->server->update($sessionId, $update);
        }
        $this->held = [];
    }

    public function beginTurn(): void
    {
        $this->cancelled = false;
        $this->open = [];
    }

    public function markCancelled(): void
    {
        $this->cancelled = true;
    }

    public function wasCancelled(): bool
    {
        return $this->cancelled;
    }

    public function send(array $update): void
    {
        if ($this->sessionId === null) {
            $this->held[] = $update;

            return;
        }
        $this->server->update($this->sessionId, $update);
    }

    public function message(string $text, string $kind = 'agent_message_chunk'): void
    {
        $this->send(['sessionUpdate' => $kind, 'content' => ['type' => 'text', 'text' => $text]]);
    }

    public function text(string $chunk): void
    {
        $this->server->poll();
        $this->message($chunk);
    }

    public function output(string $text): void
    {
        $this->server->poll();
        $this->message($text . "\n");
    }

    public function panel(string $title, string $text): void
    {
        $this->server->poll();
        $this->message("**{$title}**\n\n{$text}\n");
    }

    public function listPanel(string $title, array $items, string $hint = ''): void
    {
        $this->server->poll();
        $lines = [];
        foreach ($items as $item) {
            $lines[] = '- ' . ($item['category'] ?? '') . ': ' . ($item['title'] ?? '') . (($item['detail'] ?? '') !== '' ? ' - ' . $item['detail'] : '');
        }
        $this->message("**{$title}**\n\n" . implode("\n", $lines) . ($hint !== '' ? "\n\n{$hint}" : '') . "\n");
    }

    public function toolStart(string $agent, int $depth, string $tool, string $summary): void
    {
        $this->server->poll();
        $id = 'call_' . ++$this->toolIds;
        $this->open[$depth][] = ['id' => $id, 'tool' => $tool];
        $this->lastTool = $id;
        $this->send([
            'sessionUpdate' => 'tool_call',
            'toolCallId' => $id,
            'name' => $tool,
            'title' => Text::oneLine($summary, self::LINE_CHARS),
            'kind' => self::KINDS[$tool] ?? (str_starts_with($tool, 'mcp') ? 'other' : 'other'),
            'status' => 'in_progress',
        ]);
    }

    public function toolEnd(string $agent, int $depth, string $tool, bool $ok, string $summary, ?array $diff = null): void
    {
        $this->server->poll();
        $id = null;
        foreach (array_reverse(array_keys($this->open[$depth] ?? [])) as $index) {
            if ($this->open[$depth][$index]['tool'] === $tool) {
                $id = $this->open[$depth][$index]['id'];
                array_splice($this->open[$depth], $index, 1);
                break;
            }
        }
        $id ??= 'call_' . ++$this->toolIds;
        $this->send([
            'sessionUpdate' => 'tool_call_update',
            'toolCallId' => $id,
            'status' => $ok ? 'completed' : 'failed',
            'content' => [['type' => 'content', 'content' => ['type' => 'text', 'text' => Text::oneLine($summary, self::LINE_CHARS)]]],
        ]);
        if ($tool === 'todo' && $this->todos !== null) {
            $this->plan(($this->todos)());
        }
    }

    public function plan(TodoList $list): void
    {
        $this->send(['sessionUpdate' => 'plan', 'entries' => array_map(
            static fn (array $item): array => ['content' => $item['content'], 'priority' => 'medium', 'status' => $item['status']],
            $list->items(),
        )]);
    }

    public function subagentStart(string $parent, string $agent, string $task): void
    {
        $this->server->poll();
        $this->message("[{$agent}] " . Text::oneLine($task, self::LINE_CHARS) . "\n", 'agent_thought_chunk');
    }

    public function subagentEnd(string $parent, string $agent, string $status, string $summary): void
    {
        $this->server->poll();
        $this->message("[{$agent}] {$status}: " . Text::oneLine($summary, self::LINE_CHARS) . "\n", 'agent_thought_chunk');
    }

    public function notice(string $level, string $message): void
    {
        $this->server->poll();
        if ($level === 'info') {
            $this->message($message . "\n", 'agent_thought_chunk');

            return;
        }
        $this->message(($level === 'error' ? 'Error: ' : 'Warning: ') . $message . "\n");
    }

    public function confirm(ConfirmRequest $request): Decision
    {
        if ($this->cancelled || $this->sessionId === null) {
            return Decision::Deny;
        }
        $outcome = $this->server->requestPermission($this->sessionId, ['toolCallId' => $this->lastTool ?? 'call_0', 'title' => Text::oneLine($request->summary, self::LINE_CHARS)], [
            ['optionId' => 'allow-once', 'name' => 'Allow once', 'kind' => 'allow_once'],
            ['optionId' => 'allow-session', 'name' => 'Allow for this session', 'kind' => 'allow_always'],
            ['optionId' => 'reject-once', 'name' => 'Reject', 'kind' => 'reject_once'],
        ]);

        return match ($outcome) {
            'allow-once' => Decision::AllowOnce,
            'allow-session' => Decision::AllowSession,
            default => Decision::Deny,
        };
    }

    public function ask(string $question, array $options, bool $allowFreeText): ?string
    {
        return null;
    }

    public function interactive(): bool
    {
        return true;
    }
}
