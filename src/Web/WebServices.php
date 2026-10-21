<?php

declare(strict_types=1);

namespace Phycode\Web;

use Phycode\Support\Vault;

final readonly class WebServices
{
    public function __construct(
        public SearchChain $search,
        public Fetcher $fetcher,
        public bool $searchOn,
    ) {
    }

    public static function fromConfig(array $web, string $cacheDir, Vault $vault): self
    {
        $hosts = self::hostPorts($web['allow_hosts']);
        $fetchTransport = new Transport(new SsrfGuard($hosts), $web['fetch']['timeout_seconds'], $web['fetch']['max_bytes']);
        $searchTransport = new Transport(
            new SsrfGuard([...$hosts, ...self::endpoint($web['search']['url'])]),
            $web['fetch']['timeout_seconds'],
            $web['fetch']['max_bytes'],
        );
        $cache = $web['cache_minutes'] > 0 ? new Cache($cacheDir, $web['cache_minutes']) : null;

        return new self(
            SearchChain::fromSettings($web['search'], $searchTransport, $vault),
            new Fetcher($fetchTransport, 5, $cache),
            $web['search']['backend'] !== 'none',
        );
    }

    private static function hostPorts(array $hosts): array
    {
        $pairs = [];
        foreach ($hosts as $host) {
            foreach ([443, 80] as $port) {
                $pairs[] = "{$host}:{$port}";
            }
        }

        return $pairs;
    }

    private static function endpoint(?string $url): array
    {
        $parts = $url === null ? false : parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return [];
        }
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80);

        return [str_contains($host, ':') ? "[{$host}]:{$port}" : "{$host}:{$port}"];
    }
}
