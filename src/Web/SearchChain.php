<?php

declare(strict_types=1);

namespace Phycode\Web;

use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;

final class SearchChain
{
    public function __construct(private readonly array $backends)
    {
    }

    public function search(string $query, int $limit): array
    {
        $limit = SearchResults::limit($limit);
        $results = [];
        $seen = [];
        $errors = [];
        foreach ($this->backends as $backend) {
            try {
                $found = $backend->search($query, $limit);
            } catch (PhycodeException $error) {
                $errors[] = $error->getMessage();
                continue;
            }
            foreach ($found as $result) {
                $key = self::key($result['url']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $results[] = $result;
                if (count($results) === $limit) {
                    break 2;
                }
            }
        }

        return ['results' => $results, 'errors' => $errors];
    }

    public static function key(string $url): string
    {
        return rtrim(WebUrl::withoutFragment(trim($url)), '/');
    }

    public static function fromSettings(array $search, Transport $transport, ?Vault $vault = null): self
    {
        $backend = match ($search['backend']) {
            'none' => null,
            'searxng' => new SearxngBackend((string) $search['url'], $transport),
            'brave' => self::lazy(static fn (): SearchBackend => new BraveBackend(SearchKeys::read($search, $vault), $transport, ...self::endpoint($search['url']))),
            'tavily' => self::lazy(static fn (): SearchBackend => new TavilyBackend(SearchKeys::read($search, $vault), $transport, ...self::endpoint($search['url']))),
            'exa' => self::lazy(static fn (): SearchBackend => new ExaBackend(SearchKeys::read($search, $vault), $transport, ...self::endpoint($search['url']))),
            'perplexity' => self::lazy(static fn (): SearchBackend => new PerplexityBackend(SearchKeys::read($search, $vault), $transport, ...self::endpoint($search['url']))),
            'ollama' => self::lazy(static fn (): SearchBackend => new OllamaBackend(SearchKeys::read($search, $vault), $transport, ...self::endpoint($search['url']))),
            'firecrawl' => self::lazy(static fn (): SearchBackend => new FirecrawlBackend(SearchKeys::read($search, $vault), $transport, ...self::endpoint($search['url']))),
            default => throw new WebException("unknown search backend {$search['backend']}"),
        };

        return new self($backend === null ? [] : [$backend]);
    }

    private static function endpoint(?string $url): array
    {
        return $url === null ? [] : [$url];
    }

    private static function lazy(\Closure $make): SearchBackend
    {
        return new class ($make) implements SearchBackend {
            private ?SearchBackend $backend = null;

            public function __construct(private readonly \Closure $make)
            {
            }

            public function search(string $query, int $limit): array
            {
                return ($this->backend ??= ($this->make)())->search($query, $limit);
            }
        };
    }
}
