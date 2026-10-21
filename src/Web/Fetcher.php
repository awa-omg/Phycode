<?php

declare(strict_types=1);

namespace Phycode\Web;

use Phycode\Support\Json;

final class Fetcher
{
    private const TYPES = ['text/html', 'text/plain', 'application/json', 'application/xhtml+xml'];
    private const REDIRECTS = [301, 302, 303, 307, 308];
    private const HEADERS = ['Accept: text/html, application/xhtml+xml, text/plain, application/json;q=0.9, */*;q=0.1'];

    public function __construct(
        private readonly Transport $transport,
        private readonly int $maxRedirects = 5,
        private readonly ?Cache $cache = null,
    ) {
    }

    public function fetch(string $url): array
    {
        $url = WebUrl::withoutFragment($url);
        $key = Cache::key('GET', $url);
        $cached = $this->cache?->get($key);
        $page = $cached === null ? null : Json::object($cached);
        if ($page !== null && self::isPage($page)) {
            return $page;
        }

        $page = $this->load($url);
        $this->cache?->put($key, Json::encode($page));

        return $page;
    }

    private function load(string $url): array
    {
        $current = $url;
        for ($redirects = 0; ; $redirects++) {
            $reply = $this->transport->get($current, self::HEADERS, self::TYPES);
            if (!in_array($reply->status, self::REDIRECTS, true)) {
                break;
            }
            if ($reply->location === null) {
                throw new WebException("HTTP {$reply->status} without a location");
            }
            if ($redirects >= $this->maxRedirects) {
                throw new WebException('too many redirects');
            }
            $current = WebUrl::withoutFragment(WebUrl::resolve($current, $reply->location));
        }
        if ($reply->status < 200 || $reply->status > 299) {
            throw new WebException("HTTP {$reply->status}");
        }

        return $this->page($current, $reply);
    }

    private function page(string $url, Reply $reply): array
    {
        $media = (string) $reply->mediaType();
        if ($media === 'text/html' || $media === 'application/xhtml+xml') {
            $html = HtmlMarkdown::convert(self::utf8($reply->body, $reply->charset() ?? self::metaCharset($reply->body)), $url);

            return ['title' => $html['title'], 'url' => $url, 'markdown' => $html['markdown'], 'truncated' => $reply->truncated];
        }
        $text = str_replace(["\r\n", "\r"], "\n", self::utf8($reply->body, $reply->charset()));

        return ['title' => '', 'url' => $url, 'markdown' => trim($text), 'truncated' => $reply->truncated];
    }

    private static function isPage(array $page): bool
    {
        return is_string($page['title'] ?? null)
            && is_string($page['url'] ?? null)
            && is_string($page['markdown'] ?? null)
            && is_bool($page['truncated'] ?? null);
    }

    private static function utf8(string $body, ?string $charset): string
    {
        if ($charset !== null && !in_array($charset, ['utf-8', 'utf8'], true)) {
            try {
                $converted = mb_convert_encoding($body, 'UTF-8', $charset);
                $body = is_string($converted) ? $converted : $body;
            } catch (\ValueError) {
            }
        }

        return mb_scrub($body, 'UTF-8');
    }

    private static function metaCharset(string $body): ?string
    {
        return preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([\w.:-]+)/i', substr($body, 0, 4096), $match) === 1
            ? strtolower($match[1])
            : null;
    }
}
