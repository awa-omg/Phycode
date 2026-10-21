<?php

declare(strict_types=1);

namespace Phycode\Exec;

final class Locale
{
    public static function variables(): array
    {
        return PHP_OS_FAMILY === 'Darwin'
            ? ['LANG' => 'C', 'LC_CTYPE' => 'UTF-8']
            : ['LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8'];
    }
}
