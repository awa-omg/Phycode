<?php

declare(strict_types=1);

namespace Phycode\Session;

use Phycode\Support\Text;

final class SessionTree
{
    private const TITLE_CHARS = 48;

    public static function render(array $sessions, string $currentId): string
    {
        if ($sessions === []) {
            return 'no sessions yet';
        }
        $known = array_column($sessions, 'id');
        $children = [];
        foreach ($sessions as $session) {
            $parent = $session['parent'] ?? null;
            $children[$parent !== null && in_array($parent, $known, true) ? $parent : ''][] = $session;
        }
        foreach ($children as $parent => $group) {
            usort($group, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            $children[$parent] = $group;
        }
        $lines = [];
        $visited = [];
        foreach ($children[''] ?? [] as $root) {
            self::walk($root, $children, $currentId, '', null, $lines, $visited);
        }
        foreach ($sessions as $session) {
            if (!isset($visited[$session['id']])) {
                self::walk($session, $children, $currentId, '', null, $lines, $visited);
            }
        }

        return implode("\n", $lines);
    }

    private static function walk(array $session, array $children, string $currentId, string $prefix, ?bool $last, array &$lines, array &$visited): void
    {
        if (isset($visited[$session['id']])) {
            return;
        }
        $visited[$session['id']] = true;
        $title = $session['title'] === '' ? 'no message yet' : Text::oneLine($session['title'], self::TITLE_CHARS);
        $label = sprintf('%s  %s  (%d %s)%s', $session['id'], $title, $session['messages'], $session['messages'] === 1 ? 'message' : 'messages', $session['id'] === $currentId ? '  <- current' : '');
        $lines[] = $prefix . ($last === null ? '' : ($last ? '└─ ' : '├─ ')) . $label;
        $own = $children[$session['id']] ?? [];
        $next = $prefix . ($last === null ? '' : ($last ? '   ' : '│  '));
        foreach ($own as $index => $child) {
            self::walk($child, $children, $currentId, $next, $index === array_key_last($own), $lines, $visited);
        }
    }
}
