<?php

declare(strict_types=1);

namespace Phycode\Session;

use Phycode\Support\Redaction;
use Phycode\Support\Text;

final class SessionSearch
{
    public const SCANNED_SESSIONS = 100;
    public const MAX_TERMS = 8;
    public const SNIPPETS_PER_SESSION = 2;
    private const CONTEXT_CHARS = 110;

    public function __construct(private readonly SessionStore $store)
    {
    }

    public function find(string $query, int $limit): array
    {
        $terms = self::terms($query);
        if ($terms === []) {
            return [];
        }
        $found = [];
        foreach ($this->store->recent(self::SCANNED_SESSIONS) as $session) {
            $snippets = [];
            $matching = 0;
            foreach ($session['messages'] as $message) {
                if (!in_array($message['role'], ['user', 'assistant'], true) || $message['content'] === '') {
                    continue;
                }
                $position = self::firstPosition($message['content'], $terms);
                if ($position === null) {
                    continue;
                }
                $matching++;
                if (count($snippets) < self::SNIPPETS_PER_SESSION) {
                    $snippets[] = ['role' => $message['role'], 'text' => self::snippet($message['content'], $position)];
                }
            }
            $inTitle = self::firstPosition($session['title'], $terms) !== null;
            if ($matching > 0 || $inTitle) {
                $found[] = [
                    'id' => $session['id'],
                    'title' => $session['title'],
                    'updatedAt' => $session['updatedAt'],
                    'matches' => $matching,
                    'snippets' => $snippets,
                    'score' => $matching + ($inTitle ? 3 : 0),
                ];
            }
        }
        usort($found, static fn (array $a, array $b): int => [$b['score'], $b['updatedAt']] <=> [$a['score'], $a['updatedAt']]);

        return array_slice($found, 0, max(1, $limit));
    }

    private static function terms(string $query): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique(array_filter($words, static fn (string $word): bool => mb_strlen($word) >= 2))), 0, self::MAX_TERMS);
    }

    private static function firstPosition(string $text, array $terms): ?int
    {
        $lower = mb_strtolower($text);
        $first = null;
        foreach ($terms as $term) {
            $position = mb_strpos($lower, $term);
            if ($position === false) {
                return null;
            }
            $first = $first === null ? $position : min($first, $position);
        }

        return $first;
    }

    private static function snippet(string $text, int $position): string
    {
        $start = max(0, $position - self::CONTEXT_CHARS);
        $piece = mb_substr($text, $start, self::CONTEXT_CHARS * 2);

        return ($start > 0 ? '...' : '') . Text::oneLine(Redaction::text($piece), self::CONTEXT_CHARS * 2 + 3) . (mb_strlen($text) > $start + self::CONTEXT_CHARS * 2 ? '...' : '');
    }
}
