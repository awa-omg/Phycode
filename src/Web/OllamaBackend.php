<?php

declare(strict_types=1);

namespace Phycode\Web;

use Phycode\Support\Json;

final class OllamaBackend implements SearchBackend
{
    private const MAX_RESULTS = 10;

    public function __construct(
        private readonly string $apiKey,
        private readonly Transport $transport,
        private readonly string $endpoint = 'https://ollama.com/api/web_search',
    ) {
    }

    public function search(string $query, int $limit): array
    {
        $limit = SearchResults::limit($limit);
        $body = Json::encode(['query' => $query, 'max_results' => min($limit, self::MAX_RESULTS)]);
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey];
        $data = SearchResults::body($this->transport->post($this->endpoint, $headers, $body, ['application/json']), 'Ollama');

        return SearchResults::collect(
            SearchResults::list($data['results'] ?? null),
            $limit,
            static fn (array $result): mixed => $result['content'] ?? '',
        );
    }
}
