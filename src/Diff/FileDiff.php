<?php

declare(strict_types=1);

namespace Phycode\Diff;

final class FileDiff
{
    public const CONTEXT = 'ctx';
    public const ADDED = 'add';
    public const REMOVED = 'del';

    public static function of(string $path, array $hunks = [], bool $binary = false): array
    {
        $added = 0;
        $removed = 0;
        foreach ($hunks as $hunk) {
            foreach ($hunk['lines'] as $line) {
                if ($line['kind'] === self::ADDED) {
                    $added++;
                } elseif ($line['kind'] === self::REMOVED) {
                    $removed++;
                }
            }
        }

        return ['path' => $path, 'added' => $added, 'removed' => $removed, 'binary' => $binary, 'hunks' => $hunks];
    }
}
