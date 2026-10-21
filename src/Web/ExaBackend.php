<?php

declare(strict_types=1);

namespace Phycode\Web;

use Phycode\Support\Json;

final class ExaBackend implements SearchBackend
{
    private const MAX_RESULTS = 100;

    public function __construct(
        private readonly string $apiKey,
        private readonly Transport $transport,
        private readonly string $endpoint = 'https://api.exa.ai/search',
    ) {
    }

    public function search(string $query, int $limit): array
    {
        $limit = SearchResults::limit($limit);
        $body = Json::encode(['query' => $query, 'numResults' => min($limit, self::MAX_RESULTS), 'contents' => ['highlights' => true]]);
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'x-api-key: ' . $this->apiKey];
        $data = SearchResults::body($this->transport->post($this->endpoint, $headers, $body, ['application/json']), 'Exa');

        return SearchResults::collect(
            SearchResults::list($data['results'] ?? null),
            $limit,
            static function (array $result): string {
                $highlights = array_filter(SearchResults::list($result['highlights'] ?? null), 'is_string');
                if ($highlights !== []) {
                    return implode(' ... ', $highlights);
                }

                return is_string($result['summary'] ?? null) ? $result['summary'] : '';
            },
        );
    }
}
