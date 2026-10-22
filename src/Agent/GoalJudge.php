<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Support\Cancellation;
use Phycode\Support\Json;
use Phycode\Support\Text;

final class GoalJudge
{
    private const SYSTEM = 'You decide whether a goal has been met, from the report of a coding agent. '
        . 'Be strict: if the report gives no evidence that the goal holds (a command that ran and what it showed, a file that was changed), the goal is not met, and the reason says what evidence is missing. '
        . 'Reply with one JSON object and nothing else: {"met": true or false, "reason": "one sentence"}.';

    public static function verdict(ChatClient $client, string $condition, string $report, array $changed, Cancellation $cancel, ?\Closure $spent = null): array
    {
        $files = $changed === [] ? 'none' : implode(', ', array_slice($changed, 0, 30));
        $response = $client->chat([
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' => "Goal: {$condition}\n\nFiles changed in this turn: {$files}\n\nThe last message of the agent:\n" . Text::clip($report, 6000)],
        ], [], new ChatOptions(null, $cancel));
        if ($spent !== null) {
            $spent($response->usage);
        }

        return self::parse($response->content);
    }

    public static function parse(string $reply): array
    {
        if (preg_match('/\{.*\}/s', $reply, $match) !== 1) {
            return [false, 'the judge gave no verdict'];
        }
        $verdict = Json::object($match[0]);
        if ($verdict === null || !is_bool($verdict['met'] ?? null)) {
            return [false, 'the judge gave no verdict'];
        }
        $reason = is_string($verdict['reason'] ?? null) && trim($verdict['reason']) !== '' ? Text::oneLine($verdict['reason'], 300) : ($verdict['met'] ? 'the goal holds' : 'the judge gave no reason');

        return [$verdict['met'], $reason];
    }
}
