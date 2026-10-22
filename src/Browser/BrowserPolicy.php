<?php

declare(strict_types=1);

namespace Phycode\Browser;

use Phycode\Web\SsrfGuard;
use Phycode\Web\WebException;

final class BrowserPolicy
{
    private const NON_NETWORK_SCHEMES = ['data', 'blob', 'about'];
    private const MAX_REMEMBERED = 1000;

    private array $verdicts = [];

    public function __construct(private readonly array $allowHosts, private readonly ?\Closure $resolver = null)
    {
    }

    public function listsHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        foreach ($this->allowHosts as $listed) {
            if ($listed === $host || (str_starts_with($listed, '*.') && str_ends_with($host, substr($listed, 1)) && strlen($host) > strlen($listed) - 1)) {
                return true;
            }
        }

        return false;
    }

    public function refusal(string $url, bool $subresource = false): ?string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        if ($subresource && in_array($scheme, self::NON_NETWORK_SCHEMES, true)) {
            return null;
        }
        $port = is_array($parts) ? ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) : 80;
        $key = $scheme . '://' . strtolower(is_array($parts) ? ($parts['host'] ?? '') : '') . ':' . $port;
        if (array_key_exists($key, $this->verdicts)) {
            return $this->verdicts[$key];
        }
        $verdict = $this->judge($url, $port);
        if ($verdict === null || !str_starts_with($verdict, 'cannot resolve')) {
            $this->verdicts = count($this->verdicts) >= self::MAX_REMEMBERED ? [] : $this->verdicts;
            $this->verdicts[$key] = $verdict;
        }

        return $verdict;
    }

    private function judge(string $url, int $port): ?string
    {
        $entries = array_map(static fn (string $host): string => (str_contains($host, ':') ? "[{$host}]" : $host) . ':' . $port, $this->allowHosts);
        try {
            $target = (new SsrfGuard($entries, $this->resolver))->check($url);
        } catch (WebException $error) {
            return $error->getMessage();
        }
        foreach ($target['addresses'] as $address) {
            if (self::neverReachable($address)) {
                return "refused: {$target['host']} is {$address}, a link-local or unspecified address, and cloud metadata services live there; no setting allows it";
            }
        }

        return null;
    }

    private static function neverReachable(string $ip): bool
    {
        $packed = (string) inet_pton($ip);
        $embedsIpv4 = str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8));
        if (strlen($packed) === 16 && $embedsIpv4) {
            $packed = substr($packed, 12);
        }
        if (strlen($packed) === 4) {
            return $packed[0] === "\0" || str_starts_with($packed, "\xa9\xfe");
        }

        return $packed === str_repeat("\0", 16) || (ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80);
    }
}
