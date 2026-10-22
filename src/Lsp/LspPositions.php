<?php

declare(strict_types=1);

namespace Phycode\Lsp;

final class LspPositions
{
    public static function toUtf16Units(string $line, int $characters): int
    {
        $prefix = mb_substr($line, 0, max(0, $characters), 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function toCharacters(string $line, int $units): int
    {
        $prefix = substr(mb_convert_encoding($line, 'UTF-16LE', 'UTF-8'), 0, max(0, $units) * 2);

        return mb_strlen(mb_convert_encoding($prefix, 'UTF-8', 'UTF-16LE'), 'UTF-8');
    }
}
