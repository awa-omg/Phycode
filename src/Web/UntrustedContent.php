<?php

declare(strict_types=1);

namespace Phycode\Web;

use Phycode\Support\Redaction;

final class UntrustedContent
{
    private const TAG = 'untrusted_web_content';

    public static function wrap(string $source, string $text): string
    {
        $source = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $source));
        $attribute = htmlspecialchars(Redaction::text($source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_replace('~<(/?)' . self::TAG . '~i', '&lt;${1}' . self::TAG, $text) ?? $text;

        return '<' . self::TAG . " source=\"{$attribute}\">\n{$body}\n</" . self::TAG . '>';
    }
}
