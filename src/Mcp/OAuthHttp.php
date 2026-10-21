<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Web\SsrfGuard;

final class OAuthHttp
{
    private const MAX_BYTES = 1048576;

    public static function check(string $url, string $what): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($host === '' || !($scheme === 'https' || ($scheme === 'http' && $local)) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new PhycodeException("{$what} is not an address that a login may use ({$url}): it must be https, with no user, password or fragment");
        }
    }

    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        self::check($url, 'the address');
        $target = self::guard($url)->check($url);
        $curl = curl_init($target['url']);
        if ($curl === false) {
            throw new PhycodeException('cannot start an HTTP request');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $received = [];
        $status = 0;
        $text = '';
        $pin = filter_var($target['host'], FILTER_VALIDATE_IP) === false
            ? [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:" . implode(',', $target['addresses'])]]
            : [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received, &$status): int {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $match) === 1) {
                    $status = (int) $match[1];
                    $received = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$text): int {
                $text .= $chunk;

                return strlen($text) > self::MAX_BYTES ? 0 : strlen($chunk);
            },
        ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => $body]) + $pin);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);
        if ($ok === false) {
            throw new PhycodeException('the request failed: ' . Redaction::text($error));
        }

        return ['status' => $status, 'headers' => $received, 'body' => $text];
    }

    private static function guard(string $url): SsrfGuard
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        if (is_array($parts) && ($parts['scheme'] ?? '') === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return new SsrfGuard([(str_contains($host, ':') ? "[{$host}]" : $host) . ':' . ($parts['port'] ?? 80)]);
        }

        return new SsrfGuard();
    }
}
