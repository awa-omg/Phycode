<?php

declare(strict_types=1);

namespace Phycode\Web;

final class SsrfGuard
{
    private const IPV4 = [
        ['0.0.0.0', 32, 'an unspecified address'],
        ['0.0.0.0', 8, 'a reserved address'],
        ['10.0.0.0', 8, 'a private address'],
        ['100.64.0.0', 10, 'a reserved address'],
        ['127.0.0.0', 8, 'a loopback address'],
        ['169.254.0.0', 16, 'a link-local address'],
        ['172.16.0.0', 12, 'a private address'],
        ['192.0.0.0', 24, 'a reserved address'],
        ['192.0.2.0', 24, 'a reserved address'],
        ['192.88.99.0', 24, 'a reserved address'],
        ['192.168.0.0', 16, 'a private address'],
        ['198.18.0.0', 15, 'a reserved address'],
        ['198.51.100.0', 24, 'a reserved address'],
        ['203.0.113.0', 24, 'a reserved address'],
        ['224.0.0.0', 4, 'a multicast address'],
        ['240.0.0.0', 4, 'a reserved address'],
    ];

    private const IPV6 = [
        ['::', 128, 'an unspecified address'],
        ['::1', 128, 'a loopback address'],
        ['fe80::', 10, 'a link-local address'],
        ['fc00::', 7, 'a private address'],
        ['ff00::', 8, 'a multicast address'],
        ['2001:db8::', 32, 'a reserved address'],
        ['2001::', 23, 'a reserved address'],
        ['2002::', 16, 'a reserved address'],
    ];

    private const NAME = '~^[a-z0-9_]+(?:-+[a-z0-9_]+)*(?:\.[a-z0-9_]+(?:-+[a-z0-9_]+)*)*\.?$~iD';
    private const NUMERIC = '~^(?:0x[0-9a-f]*|[0-9]+)(?:\.(?:0x[0-9a-f]*|[0-9]+))*\.?$~iD';

    private array $allowed = [];

    private array $wildcards = [];

    public function __construct(array $allow = [], private readonly ?\Closure $resolver = null)
    {
        foreach ($allow as $entry) {
            $parts = parse_url('//' . $entry);
            if ($parts === false || !isset($parts['host'], $parts['port']) || isset($parts['path'], $parts['query'], $parts['user'], $parts['pass'])) {
                throw new WebException("allowlist entry {$entry} is not host:port");
            }
            if (str_starts_with($parts['host'], '*.')) {
                $this->wildcards[] = [strtolower(substr($parts['host'], 1)), $parts['port']];
                continue;
            }
            $this->allowed[self::key($parts['host'], $parts['port'])] = true;
        }
    }

    public function check(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw new WebException('invalid URL: it has a space, a backslash or a control character');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new WebException('invalid URL');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new WebException("{$scheme} URLs are refused; only http and https are allowed");
        }
        if (!isset($parts['host']) || $parts['host'] === '') {
            throw new WebException('invalid URL');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new WebException('URLs with credentials are refused');
        }

        $host = self::hostOf($parts['host']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $literal ? [$host] : $this->resolve($host);
        if ($addresses === []) {
            throw new WebException("cannot resolve {$host}");
        }

        if (!$this->allowedHost($host, $port)) {
            foreach ($addresses as $address) {
                $reason = self::blockedReason($address);
                if ($reason !== null) {
                    throw new WebException($literal
                        ? "refused: {$host} is {$reason}"
                        : "refused: {$host} resolves to {$address}, which is {$reason}");
                }
            }
        }

        $authority = (str_contains($host, ':') ? "[{$host}]" : $host) . (isset($parts['port']) ? ":{$port}" : '');
        $requested = "{$scheme}://{$authority}" . ($parts['path'] ?? '') . (isset($parts['query']) ? "?{$parts['query']}" : '');

        return ['url' => $requested, 'host' => $host, 'port' => $port, 'addresses' => $addresses];
    }

    private static function hostOf(string $raw): string
    {
        if (str_starts_with($raw, '[')) {
            $ip = substr($raw, 1, -1);
            if (!str_ends_with($raw, ']') || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new WebException("invalid IPv6 address {$raw}");
            }

            return $ip;
        }
        if (str_contains($raw, ':')) {
            throw new WebException("invalid IPv6 address {$raw}");
        }
        $name = $raw;
        if (preg_match('/[^\x00-\x7F]/', $name) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if (!is_string($ascii)) {
                throw new WebException("invalid host name {$raw}");
            }
            $name = $ascii;
        }
        if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $name;
        }
        if (preg_match(self::NUMERIC, $name) === 1) {
            throw new WebException("invalid IPv4 address {$raw}");
        }
        if (preg_match(self::NAME, $name) !== 1) {
            throw new WebException("invalid host name {$raw}");
        }

        return $name;
    }

    private function allowedHost(string $host, int $port): bool
    {
        if (isset($this->allowed[self::key($host, $port)])) {
            return true;
        }
        $name = strtolower($host);
        foreach ($this->wildcards as [$suffix, $allowedPort]) {
            if ($port === $allowedPort && strlen($name) > strlen($suffix) && str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function resolve(string $host): array
    {
        $found = $this->resolver !== null ? ($this->resolver)($host) : self::systemAddresses($host);

        return array_values(array_unique(array_filter(
            $found,
            static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false,
        )));
    }

    private static function systemAddresses(string $host): array
    {
        $found = @gethostbynamel($host);
        $addresses = is_array($found) ? $found : [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    private static function blockedReason(string $ip): ?string
    {
        $packed = (string) inet_pton($ip);
        if (strlen($packed) === 4) {
            return self::inTable($packed, self::IPV4);
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")
            || str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
            return self::inTable(substr($packed, 12), self::IPV4);
        }

        return self::inTable($packed, self::IPV6) ?? (self::within($packed, '2000::', 3) ? null : 'a reserved address');
    }

    private static function inTable(string $packed, array $table): ?string
    {
        foreach ($table as [$network, $bits, $reason]) {
            if (self::within($packed, $network, $bits)) {
                return $reason;
            }
        }

        return null;
    }

    private static function within(string $packed, string $network, int $bits): bool
    {
        $net = (string) inet_pton($network);
        if (strlen($net) !== strlen($packed)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    private static function key(string $host, int $port): string
    {
        $host = strtolower(trim($host, '[]'));

        return (str_contains($host, ':') ? "[{$host}]" : $host) . ":{$port}";
    }
}
