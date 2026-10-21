<?php

declare(strict_types=1);

namespace Phycode\Web;

final class SearxngBackend implements SearchBackend
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly Transport $transport,
    ) {
    }

    public function search(string $query, int $limit): array
    {
        $limit = SearchResults::limit($limit);
        $url = rtrim($this->baseUrl, '/') . '/search?' . http_build_query(['q' => $query, 'format' => 'json'], '', '&', PHP_QUERY_RFC3986);
        $reply = $this->transport->get($url, ['Accept: application/json'], ['application/json']);
        if ($reply->status === 403) {
            throw new WebException('SearXNG search failed: HTTP 403. Add json to search.formats in the settings.yml of the instance');
        }
        $data = SearchResults::body($reply, 'SearXNG');

        return SearchResults::collect(
            SearchResults::list($data['results'] ?? null),
            $limit,
            static fn (array $result): mixed => $result['content'] ?? '',
        );
    }
}
