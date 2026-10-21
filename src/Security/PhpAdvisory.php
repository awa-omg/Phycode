<?php

declare(strict_types=1);

namespace Phycode\Security;

final class PhpAdvisory
{
    public const CVES = 'CVE-2026-109618 and CVE-2026-109619';

    public const FIXED = [];

    private const OLDEST_SUPPORTED = '8.2';

    public static function problem(string $version, array $fixed = self::FIXED): ?string
    {
        if (version_compare($version, self::OLDEST_SUPPORTED, '<')) {
            return 'PHP ' . $version . ' is end of life, so no release will fix ' . self::CVES . '; move to PHP 8.4 or newer';
        }

        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        if (!isset($fixed[$branch])) {
            return 'PHP ' . $version . ' has no fixed release recorded for ' . self::CVES . '; install the PHP security release of 21 October 2026';
        }

        if (version_compare($version, $fixed[$branch], '<')) {
            return 'PHP ' . $version . ' is older than ' . $fixed[$branch] . ', the first release with the fix for ' . self::CVES;
        }

        return null;
    }
}
