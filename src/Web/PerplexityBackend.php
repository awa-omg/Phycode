<?php

declare(strict_types=1);

namespace Phycode\Web;

use Phycode\Support\Json;

final class PerplexityBackend implements SearchBackend
{
    private const MAX_RESULTS = 20;

    public function __construct(
        private readonly string $apiKey,
        private readonly Transport $transport,
        private readonly string $endpoint = 'https://api.perplexity.ai/search',
    ) {
    }

    public function search(string $query, int $limit): array
    {
        $limit = SearchResults::limit($limit);
        $body = Json::encode(['query' => $query, 'max_results' => min($limit, self::MAX_RESULTS)]);
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey];
        $data = SearchResults::body($this->transport->post($this->endpoint, $headers, $body, ['application/json']), 'Perplexity');

        return SearchResults::collect(
            SearchResults::list($data['results'] ?? null),
            $limit,
            static fn (array $result): mixed => $result['snippet'] ?? '',
        );
    }
}
