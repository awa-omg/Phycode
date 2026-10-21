<?php

declare(strict_types=1);

namespace Phycode\Web;

final class WebUrl
{
    public static function safe(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '[invalid URL]';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($parts['scheme']) . '://' . $parts['host'] . $port . ($parts['path'] ?? '');
    }

    public static function withoutFragment(string $url): string
    {
        $hash = strpos($url, '#');

        return $hash === false ? $url : substr($url, 0, $hash);
    }

    public static function resolve(string $base, string $reference): string
    {
        $reference = self::withoutFragment(trim($reference));
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference) === 1) {
            return $reference;
        }
        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new WebException('invalid base URL');
        }
        $scheme = strtolower($parts['scheme']);
        $authority = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($reference, '//')) {
            return $scheme . ':' . $reference;
        }
        if ($reference === '') {
            return $authority . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        $question = strpos($reference, '?');
        $path = $question === false ? $reference : substr($reference, 0, $question);
        $query = $question === false ? null : substr($reference, $question + 1);
        if ($path === '') {
            $path = $parts['path'] ?? '/';
        } elseif (!str_starts_with($path, '/')) {
            $basePath = $parts['path'] ?? '/';
            $path = substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $path;
        }

        return $authority . self::removeDotSegments($path) . ($query === null ? '' : '?' . $query);
    }

    private static function removeDotSegments(string $path): string
    {
        $output = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($segment === '.' || $segment === '..') {
                if ($segment === '..' && count($output) > 1) {
                    array_pop($output);
                }
                if ($index === $last) {
                    $output[] = '';
                }

                continue;
            }
            $output[] = $segment;
        }

        return implode('/', $output);
    }
}
