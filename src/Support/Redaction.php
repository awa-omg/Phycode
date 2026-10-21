<?php

declare(strict_types=1);

namespace Phycode\Support;

final class Redaction
{
    private const MARK = '[redacted]';

    public static function exact(string $text, array $secrets, int $minimum = 8): string
    {
        foreach ($secrets as $secret) {
            if (strlen($secret) >= $minimum) {
                $text = str_replace($secret, self::MARK, $text);
            }
        }

        return $text;
    }

    public static function text(string $message, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, self::MARK, $message);
            }
        }

        $message = preg_replace('~\b([a-z][a-z0-9+.-]*://)[^\s/?#]+@~i', '$1', $message) ?? $message;
        $message = preg_replace('/\b((?:proxy-)?authorization|(?:x-)?api[-_]?key)(["\']?)(\s*[:=]\s*)(["\']?)(?:[a-z]+\s+)?[^\s,;"\'&]+/i', '$1$2$3$4' . self::MARK, $message) ?? $message;
        $message = preg_replace('/\bBearer\s+[^\s,;"\']+/i', 'Bearer ' . self::MARK, $message) ?? $message;

        return preg_replace('/\bsk-[A-Za-z0-9_-]{8,}/', self::MARK, $message) ?? $message;
    }

    public static function endpoint(string $url): string
    {
        if (str_starts_with($url, 'unix://')) {
            return $url;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if (!is_string($scheme) || !is_string($host)) {
            return '[invalid URL]';
        }

        return $scheme . '://' . $host . (is_int($port) ? ":{$port}" : '');
    }
}
