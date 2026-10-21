<?php

declare(strict_types=1);

namespace Phycode\Web;

use Phycode\Support\Json;

final class FirecrawlBackend implements SearchBackend
{
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly string $apiKey,
        private readonly Transport $transport,
        private readonly string $endpoint = 'https://api.firecrawl.dev/v2/search',
    ) {
    }

    public function search(string $query, int $limit): array
    {
        $limit = SearchResults::limit($limit);
        $body = Json::encode(['query' => $query, 'limit' => min($limit, self::MAX_LIMIT)]);
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey];
        $data = SearchResults::body($this->transport->post($this->endpoint, $headers, $body, ['application/json']), 'Firecrawl');
        $found = is_array($data['data'] ?? null) ? $data['data'] : [];

        return SearchResults::collect(
            SearchResults::list($found['web'] ?? null),
            $limit,
            static fn (array $result): mixed => $result['description'] ?? '',
        );
    }
}
