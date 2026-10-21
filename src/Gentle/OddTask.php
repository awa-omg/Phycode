<?php

declare(strict_types=1);

namespace Phycode\Gentle;

use Phycode\Support\Files;
use Phycode\Support\PhycodeException;

final class OddTask
{
    private const FEATURE = '/^[a-z0-9][a-z0-9-]{0,62}$/D';

    public static function path(string $root, string $feature): string
    {
        if (preg_match(self::FEATURE, $feature) !== 1) {
            throw new PhycodeException('a feature name is lowercase letters, digits and dashes, starting with a letter or a digit, such as add-login');
        }

        return rtrim($root, '/') . "/odd/tasks/{$feature}.md";
    }

    public static function template(string $feature): string
    {
        return implode("\n", [
            "# ODD Task: {$feature}",
            '',
            'Objective: (one sentence)  Branch: feat/' . $feature . '  Strategy: ask-on-risk  Runner: (the test command)',
            '',
            '## Specs',
            'S1. "(the request, verbatim)"',
            '',
            '## Tasks',
            '- T1 | S1 | inline | commit (none yet)',
            '',
            '## Log',
            "L1 (verbatim request): /odd {$feature}",
            '',
        ]);
    }

    public static function create(string $root, string $feature, Approval $approve): string
    {
        $path = self::path($root, $feature);
        $relative = "odd/tasks/{$feature}.md";
        if (file_exists($path) || is_link($path)) {
            throw new PhycodeException("{$relative} exists already; nothing changed");
        }
        $folder = dirname($path);
        foreach ([dirname($folder), $folder] as $directory) {
            if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
                throw new PhycodeException(substr($directory, strlen(rtrim($root, '/')) + 1) . ' is a link or not a folder; nothing changed');
            }
        }
        $missing = is_dir($folder) ? '' : ' (the folder odd/tasks is made first)';
        if (!$approve("/odd {$feature}", "create {$path}{$missing} with this content:\n\n" . self::template($feature), "create {$relative}")) {
            return 'not approved: nothing was changed.';
        }
        if (!is_dir($folder)) {
            @mkdir($folder, 0755, true);
            if (!is_dir($folder)) {
                throw new PhycodeException('cannot create ' . $folder);
            }
        }
        Files::writeAtomic($path, self::template($feature), 0644);

        return "created {$relative}. Fill in the objective, the specs and the runner before the first write.";
    }
}
