<?php

declare(strict_types=1);

namespace Phycode\Web;

final class HtmlMarkdown
{
    private const DROPPED = ['script', 'style', 'noscript', 'nav', 'footer', 'aside', 'head', 'template'];
    private const BLOCKS = [
        'address', 'article', 'body', 'caption', 'dd', 'details', 'dialog', 'div', 'dl', 'dt', 'fieldset', 'figcaption',
        'figure', 'form', 'header', 'html', 'main', 'p', 'section', 'summary', 'table',
    ];
    private const STRUCTURE = ['table', 'thead', 'tbody', 'tfoot', 'tr', 'ul', 'ol'];
    private const HEADINGS = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];
    private const MAX_DEPTH = 1000;
    private const MARKER = "\x00";

    private string $base;

    private int $depth = 0;

    private array $codeBlocks = [];

    private function __construct(string $url)
    {
        $this->base = $url;
    }

    public static function convert(string $html, string $baseUrl): array
    {
        return (new self($baseUrl))->run($html);
    }

    private function run(string $html): array
    {
        if ($html === '') {
            return ['title' => '', 'markdown' => ''];
        }
        $ascii = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadHTML($ascii, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return ['title' => '', 'markdown' => ''];
        }

        $title = self::oneLine($dom->getElementsByTagName('title')->item(0)?->textContent ?? '');
        foreach ($dom->getElementsByTagName('base') as $base) {
            $href = trim($base->getAttribute('href'));
            $resolved = $href === '' ? '' : WebUrl::resolve($this->base, $href);
            if (preg_match('~^https?://~i', $resolved) === 1) {
                $this->base = $resolved;
                break;
            }
        }

        $root = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;

        return ['title' => $title, 'markdown' => $this->tidy($root === null ? '' : $this->render($root, '', false))];
    }

    private function render(\DOMNode $node, string $indent, bool $pre): string
    {
        if ($node instanceof \DOMText) {
            if (!$pre && trim($node->data) === '' && self::isStructure($node->parentNode)) {
                return '';
            }

            return $pre ? self::clean($node->data) : self::collapse(self::clean($node->data));
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROPPED, true)) {
            return '';
        }
        if (isset(self::HEADINGS[$tag])) {
            $text = self::oneLine($this->children($node, $indent, false));

            return $text === '' ? '' : self::block(str_repeat('#', self::HEADINGS[$tag]) . ' ' . $text);
        }
        if (in_array($tag, self::BLOCKS, true)) {
            return self::block($this->children($node, $indent, $pre));
        }

        return match ($tag) {
            'br' => "\n",
            'hr' => self::block('---'),
            'blockquote' => self::block(self::quote($this->children($node, $indent, $pre))),
            'ul', 'ol' => $this->renderList($node, $indent),
            'li' => self::block($this->item($node, $indent, '- ')),
            'pre' => $this->codeBlock($node, $indent),
            'code' => $pre
                ? $this->children($node, $indent, true)
                : self::inlineCode(self::oneLine($this->children($node, $indent, false))),
            'a' => $pre ? $this->children($node, $indent, true) : $this->link($node, $indent),
            'tr' => $this->row($node, $indent),
            default => $this->children($node, $indent, $pre),
        };
    }

    private function children(\DOMNode $node, string $indent, bool $pre): string
    {
        if ($this->depth >= self::MAX_DEPTH) {
            return '';
        }
        $this->depth++;
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->render($child, $indent, $pre);
        }
        $this->depth--;

        return $text;
    }

    private function renderList(\DOMElement $node, string $indent): string
    {
        $ordered = strtolower($node->tagName) === 'ol';
        $number = max(1, (int) $node->getAttribute('start'));
        $items = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'li') {
                $items .= $this->item($child, $indent, $ordered ? $number++ . '. ' : '- ');
            } else {
                $items .= $this->render($child, $indent, false);
            }
        }

        return $indent === '' ? self::block($items) : $items . "\n";
    }

    private function item(\DOMElement $node, string $indent, string $marker): string
    {
        $content = trim($this->children($node, $indent . str_repeat(' ', strlen($marker)), false), " \t\n");

        return "\n" . $indent . $marker . $content;
    }

    private function codeBlock(\DOMElement $node, string $indent): string
    {
        $language = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement
                && strtolower($child->tagName) === 'code'
                && preg_match('/(?:^|\s)language-([\w+#.-]+)/', $child->getAttribute('class'), $match) === 1) {
                $language = $match[1];
                break;
            }
        }
        $code = trim(str_replace(["\r\n", "\r"], "\n", $this->children($node, '', true)), "\n");
        $runs = preg_match_all('/`+/', $code, $matches) > 0 ? array_map('strlen', $matches[0]) : [0];
        $fence = str_repeat('`', max(3, max($runs) + 1));
        $lines = explode("\n", $fence . $language . "\n" . $code . "\n" . $fence);
        $this->codeBlocks[] = implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $indent . $line,
            $lines,
        ));

        return "\n\n" . self::MARKER . (count($this->codeBlocks) - 1) . self::MARKER . "\n\n";
    }

    private function link(\DOMElement $node, string $indent): string
    {
        $text = self::oneLine($this->children($node, $indent, false));
        $url = $this->absolute($node->getAttribute('href'));
        if ($url === null) {
            return $text;
        }
        if ($text === '') {
            return "<{$url}>";
        }
        $url = str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $url);

        return '[' . str_replace(['[', ']'], ['\[', '\]'], $text) . "]({$url})";
    }

    private function absolute(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || $href[0] === '#') {
            return null;
        }
        $url = WebUrl::resolve($this->base, $href);

        return preg_match('~^https?://~i', $url) === 1 ? $url : null;
    }

    private function row(\DOMElement $node, string $indent): string
    {
        $cells = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                $cells[] = self::oneLine($this->children($child, $indent, false));
            }
        }

        return $cells === [] ? '' : "\n" . $indent . implode(' | ', $cells);
    }

    private function tidy(string $markdown): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        $text = preg_replace(['/[ \t]+$/m', '/(?<=\S)[ \t]{2,}/', '/\n{3,}/'], ['', ' ', "\n\n"], $text) ?? $text;
        $text = trim($text, " \t\n");

        return preg_replace_callback(
            '/\x00(\d+)\x00/',
            fn (array $match): string => $this->codeBlocks[(int) $match[1]],
            $text,
        ) ?? $text;
    }

    private static function block(string $text): string
    {
        return "\n\n" . trim($text, " \t\n") . "\n\n";
    }

    private static function quote(string $text): string
    {
        return preg_replace('/^/m', '> ', trim($text, " \t\n")) ?? '';
    }

    private static function inlineCode(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return str_contains($text, '`') ? "`` {$text} ``" : "`{$text}`";
    }

    private static function isStructure(?\DOMNode $parent): bool
    {
        return $parent instanceof \DOMElement && in_array(strtolower($parent->tagName), self::STRUCTURE, true);
    }

    private static function oneLine(string $text): string
    {
        return trim(self::collapse($text));
    }

    private static function collapse(string $text): string
    {
        return preg_replace('/[ \t\r\n\f\x{00A0}]+/u', ' ', $text) ?? '';
    }

    private static function clean(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
    }
}
