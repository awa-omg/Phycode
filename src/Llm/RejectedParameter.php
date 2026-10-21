<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Support\Json;

final class RejectedParameter
{
    private const PATTERNS = [
        "/Unsupported parameter: '(?<p>[\\w.]+)'(?:.*?Use '(?<use>[\\w.]+)' instead)?/is",
        "/Unknown parameter: '(?<p>[\\w.]+)'/i",
        "/Unsupported value: '(?<p>[\\w.]+)'/i",
        '/Unrecognized request arguments? supplied: (?<p>[\w.]+)/i',
        "/property '(?<p>[\\w.]+)' is unsupported/i",
        "/'(?<p>[\\w.]+)' is not supported with this model/i",
        '/"loc"\s*:\s*\["body"\s*,\s*"(?<p>[\w.]+)"\s*\]/',
    ];

    public static function inReply(string $body): ?array
    {
        $error = (Json::object($body) ?? [])['error'] ?? null;
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : $body;
        $read = self::in($message);
        if (is_array($error) && is_string($error['param'] ?? null) && $error['param'] !== '') {
            return ['param' => explode('.', $error['param'])[0], 'use' => $read['use'] ?? null];
        }

        return $read ?? self::in($body);
    }

    public static function in(string $message): ?array
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $message, $match) === 1) {
                $use = isset($match['use']) && $match['use'] !== '' ? $match['use'] : null;

                return ['param' => explode('.', $match['p'])[0], 'use' => $use];
            }
        }

        return null;
    }
}
