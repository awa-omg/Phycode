<?php

declare(strict_types=1);

namespace Phycode\Web;

use Phycode\Support\Json;
use Phycode\Support\Text;

final class SearchResults
{
    public static function limit(int $limit): int
    {
        if ($limit < 1) {
            throw new WebException('the result limit must be at least 1');
        }

        return $limit;
    }

    public static function body(Reply $reply, string $provider): array
    {
        if ($reply->status < 200 || $reply->status > 299) {
            throw new WebException("{$provider} search failed: HTTP {$reply->status}");
        }
        $data = Json::object($reply->body);
        if ($data === null) {
            throw new WebException("{$provider} search returned no JSON object");
        }

        return $data;
    }

    public static function list(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public static function collect(array $results, int $limit, \Closure $snippet): array
    {
        $found = [];
        foreach ($results as $result) {
            if (count($found) >= $limit) {
                break;
            }
            if (!is_array($result) || !is_string($result['url'] ?? null)) {
                continue;
            }
            $url = trim($result['url']);
            if (preg_match('~^https?://~i', $url) !== 1) {
                continue;
            }
            $title = self::plain($result['title'] ?? null);
            $found[] = [
                'title' => Text::oneLine($title !== '' ? $title : $url, 200),
                'url' => $url,
                'snippet' => Text::oneLine(self::plain($snippet($result)), 500),
            ];
        }

        return $found;
    }

    private static function plain(mixed $value): string
    {
        return is_string($value) ? html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }
}
