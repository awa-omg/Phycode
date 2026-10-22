<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Session\SessionSearch;
use Phycode\Support\PhycodeException;

final class SessionSearchTool extends BaseTool
{
    public function __construct(private readonly SessionSearch $search)
    {
    }

    public function name(): string
    {
        return 'session_search';
    }

    public function description(): string
    {
        return 'Search the earlier sessions of this project for what was said: a decision, an error, the name of a file. Every word of the query must appear in the same message. '
            . 'Returns the sessions that match, newest first, each with the id the user can open with /sessions, its title and a short excerpt. The current session may be among them.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Words to look for, case does not matter'],
                'limit' => ['type' => 'integer', 'description' => 'How many sessions to return, from 1 to 10 (default 5)'],
            ],
            'required' => ['query'],
        ];
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Read;
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return $policy->depth === 0;
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        try {
            $query = Args::string($args, 'query');
            $limit = Args::int($args, 'limit', 1, 10, 5);
        } catch (PhycodeException $error) {
            return ToolResult::fail($error->getMessage());
        }
        $found = $this->search->find($query, $limit);
        if ($found === []) {
            return ToolResult::ok('no earlier session matches', ['sessions' => 0]);
        }
        $lines = [];
        foreach ($found as $session) {
            $lines[] = sprintf('%s  %s  "%s"  (%d %s)', $session['id'], substr($session['updatedAt'], 0, 16), $session['title'], $session['matches'], $session['matches'] === 1 ? 'message' : 'messages');
            foreach ($session['snippets'] as $snippet) {
                $lines[] = "  {$snippet['role']}: {$snippet['text']}";
            }
        }

        return ToolResult::ok(implode("\n", $lines), ['sessions' => count($found)]);
    }
}
