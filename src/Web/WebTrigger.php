<?php

declare(strict_types=1);

namespace Phycode\Web;

final class WebTrigger
{
    private const QUERY_CHARS = 500;
    private const URL_PATTERN = '~https?://[^\s<>"\'`()\[\]{}]+~iu';
    private const SEARCH_PATTERN = '~\(\s*(?:web\s+search|busqueda\s+web)\s*(?::([^()\n]*))?\)~u';
    private const FETCH_PATTERN = '~\(\s*web\s+fetch\s*:?([^()\n]*)\)~u';
    private const SEARCH_PHRASE = '~\b(?:web\s*search|busqueda\s+(?:web|en\s+(?:la\s+)?(?:web|internet))|busca(?:lo|la|r)?\s+(?:en|por)\s+(?:la\s+)?(?:web|internet|google)|search\s+(?:the\s+)?(?:web|internet|online)|look\s+(?:it\s+)?up\s+online)\b~u';
    private const FETCH_PHRASE = '~\b(?:web\s*fetch|fetch\s+(?:the\s+)?(?:url|page|link))\b~u';
    private const READ_VERB = '~\b(?:lee|leer|abre|abrir|visita|visitar|revisa|revisar|resume|resumir|analiza|analizar|extrae|extraer|read|open|visit|summari[sz]e|check)\b~u';

    private const PLAIN = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ċ' => 'c',
        'ď' => 'd', 'đ' => 'd',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ğ' => 'g', 'ĝ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĺ' => 'l', 'ľ' => 'l', 'ļ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n', 'ņ' => 'n',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o',
        'ŕ' => 'r', 'ř' => 'r', 'ŗ' => 'r',
        'ś' => 's', 'š' => 's', 'ş' => 's',
        'ť' => 't', 'ţ' => 't',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ź' => 'z', 'ž' => 'z', 'ż' => 'z',
    ];

    public static function detect(string $text): ?array
    {
        $text = mb_scrub($text, 'UTF-8');
        $chars = self::chars($text);
        $visible = self::chars(self::maskCode($text));
        $folded = implode('', array_map(self::fold(...), $visible));

        $searches = self::find(self::SEARCH_PATTERN, $folded);
        $fetches = array_values(array_filter(
            self::find(self::FETCH_PATTERN, $folded),
            static fn (array $match): bool => $match['inner'] === null
                || self::onlyUrls(mb_substr($folded, $match['inner'][0], $match['inner'][1] - $match['inner'][0])),
        ));
        $visibleText = implode('', $visible);
        $search = $searches !== [] || self::phrased(self::SEARCH_PHRASE, $folded);
        $fetch = $fetches !== [] || (preg_match(self::URL_PATTERN, $visibleText) === 1
            && (self::phrased(self::FETCH_PHRASE, $folded) || self::phrased(self::READ_VERB, $folded)));
        if (!$search && !$fetch) {
            return null;
        }

        $query = null;
        foreach ($searches as $match) {
            if ($match['inner'] === null) {
                continue;
            }
            $value = trim(implode('', array_slice($chars, $match['inner'][0], $match['inner'][1] - $match['inner'][0])));
            if ($value !== '') {
                $query = mb_substr($value, 0, self::QUERY_CHARS);
                break;
            }
        }

        $urls = [];
        if ($fetch) {
            preg_match_all(self::URL_PATTERN, $visibleText, $found);
            foreach ($found[0] as $url) {
                $url = rtrim($url, '.,;:!?');
                if (!in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        $removed = [];
        foreach ([...$searches, ...$fetches] as $match) {
            $start = $match['start'];
            while ($start > 0 && ($chars[$start - 1] === ' ' || $chars[$start - 1] === "\t")) {
                $start--;
            }
            for ($i = $start; $i < $match['end']; $i++) {
                $removed[$i] = true;
            }
        }
        $rest = '';
        foreach ($chars as $index => $char) {
            if (!isset($removed[$index])) {
                $rest .= $char;
            }
        }

        $labels = [];
        if ($search) {
            $labels[] = 'web search ON';
        }
        if ($fetch) {
            $labels[] = 'web fetch ON';
        }

        return [
            'search' => $search,
            'fetch' => $fetch,
            'query' => $query,
            'urls' => $urls,
            'chip' => implode(', ', $labels) . ' · this turn',
            'text' => trim($rest),
        ];
    }

    private static function phrased(string $pattern, string $folded): bool
    {
        if (preg_match_all($pattern, $folded, $all, PREG_OFFSET_CAPTURE) === false) {
            return false;
        }
        foreach ($all[0] as $match) {
            $at = $match[1];
            while ($at > 0 && ($folded[$at - 1] === ' ' || $folded[$at - 1] === "\t")) {
                $at--;
            }
            if ($at === 0 || $folded[$at - 1] !== '(') {
                return true;
            }
        }

        return false;
    }

    private static function find(string $pattern, string $folded): array
    {
        if (preg_match_all($pattern, $folded, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $found = [];
        foreach ($all as $match) {
            $start = self::offset($folded, $match[0][1]);
            $inner = null;
            if (isset($match[1]) && $match[1][1] >= 0) {
                $innerStart = self::offset($folded, $match[1][1]);
                $inner = [$innerStart, $innerStart + mb_strlen($match[1][0])];
            }
            $found[] = ['start' => $start, 'end' => $start + mb_strlen($match[0][0]), 'inner' => $inner];
        }

        return $found;
    }

    private static function offset(string $folded, int $byte): int
    {
        return mb_strlen(substr($folded, 0, $byte));
    }

    private static function maskCode(string $text): string
    {
        $text = (string) preg_replace_callback(
            '#^[ \t]*(`{3,}|~{3,})[^\n]*\n.*?(?:^[ \t]*\1[`~]*[ \t]*$|\z)#ms',
            static fn (array $match): string => self::blank($match[0]),
            $text,
        );

        return (string) preg_replace_callback('~``.+?``|`[^`\n]+`~', static fn (array $match): string => self::blank($match[0]), $text);
    }

    private static function blank(string $span): string
    {
        return str_repeat(' ', mb_strlen($span));
    }

    private static function chars(string $text): array
    {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function fold(string $char): string
    {
        $lower = mb_strtolower($char);
        if (mb_strlen($lower) !== 1) {
            return $char;
        }

        return self::PLAIN[$lower] ?? $lower;
    }

    private static function onlyUrls(string $inner): bool
    {
        foreach (preg_split('/\s+/', trim($inner), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('~^https?://\S+$~', $token) !== 1) {
                return false;
            }
        }

        return true;
    }
}
