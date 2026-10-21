<?php

declare(strict_types=1);

namespace Phycode\Config;

final class SearchPresets
{
    public static function all(): array
    {
        return [
            ['backend' => 'brave', 'url' => 'https://api.search.brave.com/res/v1/web/search', 'keyRequired' => true, 'urlRequired' => false, 'urlMeans' => 'endpoint'],
            ['backend' => 'tavily', 'url' => 'https://api.tavily.com/search', 'keyRequired' => true, 'urlRequired' => false, 'urlMeans' => 'endpoint'],
            ['backend' => 'exa', 'url' => 'https://api.exa.ai/search', 'keyRequired' => true, 'urlRequired' => false, 'urlMeans' => 'endpoint'],
            ['backend' => 'perplexity', 'url' => 'https://api.perplexity.ai/search', 'keyRequired' => true, 'urlRequired' => false, 'urlMeans' => 'endpoint'],
            ['backend' => 'ollama', 'url' => 'https://ollama.com/api/web_search', 'keyRequired' => true, 'urlRequired' => false, 'urlMeans' => 'endpoint'],
            ['backend' => 'firecrawl', 'url' => 'https://api.firecrawl.dev/v2/search', 'keyRequired' => true, 'urlRequired' => false, 'urlMeans' => 'endpoint'],
            ['backend' => 'searxng', 'url' => null, 'keyRequired' => false, 'urlRequired' => true, 'urlMeans' => 'base'],
        ];
    }

    public static function of(string $backend): ?array
    {
        foreach (self::all() as $preset) {
            if ($preset['backend'] === $backend) {
                return $preset;
            }
        }

        return null;
    }
}
