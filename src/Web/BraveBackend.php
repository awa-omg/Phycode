<?php

declare(strict_types=1);

namespace Phycode\Web;

final class BraveBackend implements SearchBackend
{
    private const MAX_COUNT = 20;

    public function __construct(
        private readonly string $apiKey,
        private readonly Transport $transport,
        private readonly string $endpoint = 'https://api.search.brave.com/res/v1/web/search',
    ) {
    }

    public function search(string $query, int $limit): array
    {
        $limit = SearchResults::limit($limit);
        $url = $this->endpoint . '?' . http_build_query(['q' => $query, 'count' => min($limit, self::MAX_COUNT)], '', '&', PHP_QUERY_RFC3986);
        $headers = ['Accept: application/json', 'X-Subscription-Token: ' . $this->apiKey];
        $data = SearchResults::body($this->transport->get($url, $headers, ['application/json']), 'Brave');
        $web = is_array($data['web'] ?? null) ? $data['web'] : [];

        return SearchResults::collect(
            SearchResults::list($web['results'] ?? null),
            $limit,
            static fn (array $result): mixed => $result['description'] ?? '',
        );
    }
}
