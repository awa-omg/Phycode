<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Review;

use Phycode\Exec\ProcessRunner;
use Phycode\Tests\Support\Kit;

final class ReviewRepo
{
    public static function create(array $files): string
    {
        $root = Kit::folder('review-repo');
        self::initialize($root, $files);

        return $root;
    }

    public static function initialize(string $root, array $files): void
    {
        self::git($root, ['init', '-q', '-b', 'main']);
        foreach ($files as $path => $content) {
            Kit::write($root . '/' . $path, $content);
        }
        self::git($root, ['add', '-A']);
        self::commit($root, 'initial');
    }

    public static function commit(string $root, string $message): void
    {
        self::git($root, ['add', '-A']);
        self::git($root, ['-c', 'user.name=Review Test', '-c', 'user.email=review@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', $message]);
    }

    public static function git(string $root, array $argv): string
    {
        $result = (new ProcessRunner())->run(['git', ...$argv], $root, 30, 1048576);
        if (!$result->succeeded()) {
            throw new \RuntimeException('git ' . implode(' ', $argv) . ' failed: ' . $result->stderr);
        }

        return $result->stdout;
    }
}
