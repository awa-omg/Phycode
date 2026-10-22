<?php

declare(strict_types=1);

namespace Phycode\Lsp;

use Phycode\Security\PathException;
use Phycode\Security\SecretFiles;
use Phycode\Security\Workspace;
use Phycode\Support\Text;

final class LspReport
{
    private const MAX_SOURCE_BYTES = 5242880;
    private const DEFINITION_LINES = 3;
    private const MAX_DEFINITIONS = 10;
    private const MAX_REFERENCES = 50;
    private const MAX_SYMBOLS = 200;
    private const MAX_WORKSPACE_SYMBOLS = 50;
    private const MAX_DIAGNOSTICS = 100;
    private const LINE_WIDTH = 200;
    private const SEVERITIES = [1 => 'error', 2 => 'warning', 3 => 'info', 4 => 'hint'];
    private const SYMBOL_KINDS = [
        1 => 'file', 2 => 'module', 3 => 'namespace', 4 => 'package', 5 => 'class', 6 => 'method', 7 => 'property', 8 => 'field',
        9 => 'constructor', 10 => 'enum', 11 => 'interface', 12 => 'function', 13 => 'variable', 14 => 'constant', 15 => 'string',
        16 => 'number', 17 => 'boolean', 18 => 'array', 19 => 'object', 20 => 'key', 21 => 'null', 22 => 'enum member',
        23 => 'struct', 24 => 'event', 25 => 'operator', 26 => 'type parameter',
    ];

    private array $sources = [];

    public function __construct(private readonly Workspace $workspace, private readonly int $maxBytes)
    {
    }

    public function definitions(mixed $result): string
    {
        return $this->capped($this->places($result), self::MAX_DEFINITIONS, function (array $place): string {
            $label = $this->label($place);
            $lines = $this->sourceLines($place);
            if ($lines === null || $place['line'] === null) {
                return $label;
            }
            $shown = [];
            for ($index = $place['line']; $index < min(count($lines), $place['line'] + self::DEFINITION_LINES); $index++) {
                $shown[] = sprintf('%6d  %s', $index + 1, self::fit($lines[$index]));
            }

            return $label . "\n" . implode("\n", $shown);
        });
    }

    public function references(mixed $result): string
    {
        $places = $this->places($result);
        if ($places === []) {
            return '';
        }
        $body = $this->capped($places, self::MAX_REFERENCES, function (array $place): string {
            $text = $this->sourceLine($place);

            return $this->label($place) . ($text === null ? '' : ': ' . Text::oneLine($text, 160));
        });

        return count($places) . ' references:' . "\n" . $body;
    }

    public function hover(mixed $result): string
    {
        $text = trim($this->markup(is_array($result) ? ($result['contents'] ?? null) : null));

        return Text::clip($text, $this->maxBytes);
    }

    public function documentSymbols(mixed $result): string
    {
        $lines = [];
        $this->collectSymbols(is_array($result) ? $result : [], 0, $lines);

        return $this->capped($lines, self::MAX_SYMBOLS, static fn (string $line): string => $line);
    }

    public function workspaceSymbols(mixed $result): string
    {
        $symbols = array_values(array_filter(
            is_array($result) ? $result : [],
            static fn (mixed $symbol): bool => is_array($symbol) && is_string($symbol['name'] ?? null) && is_string($symbol['location']['uri'] ?? null),
        ));

        return $this->capped($symbols, self::MAX_WORKSPACE_SYMBOLS, function (array $symbol): string {
            $place = $this->place($symbol['location']['uri'], $symbol['location']['range'] ?? null);

            return self::kind($symbol) . ' ' . Text::oneLine($symbol['name'], 120) . self::container($symbol) . '  ' . $this->label($place);
        });
    }

    public function diagnostics(mixed $items, string $absolute): string
    {
        $path = $this->workspace->relative($absolute);
        $entries = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_string($item['message'] ?? null)) {
                $severity = $item['severity'] ?? 1;
                $entries[] = ['severity' => is_int($severity) && isset(self::SEVERITIES[$severity]) ? $severity : 1] + $item;
            }
        }
        if ($entries === []) {
            return '';
        }
        usort($entries, static fn (array $a, array $b): int => [$a['severity'], $a['range']['start']['line'] ?? 0, $a['range']['start']['character'] ?? 0] <=> [$b['severity'], $b['range']['start']['line'] ?? 0, $b['range']['start']['character'] ?? 0]);
        $counts = [];
        foreach ($entries as $entry) {
            $name = self::SEVERITIES[$entry['severity']];
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        $summary = implode(' ', array_map(static fn (string $name, int $count): string => "{$name}={$count}", array_keys($counts), $counts));
        $body = $this->capped($entries, self::MAX_DIAGNOSTICS, function (array $entry) use ($absolute): string {
            $place = $this->place(LspFiles::uri($absolute), $entry['range'] ?? null);
            $tag = trim((is_string($entry['source'] ?? null) ? $entry['source'] : '') . ' ' . (is_scalar($entry['code'] ?? null) ? $entry['code'] : ''));

            return $this->position($place) . ' ' . self::SEVERITIES[$entry['severity']] . ($tag === '' ? '' : " [{$tag}]") . ' ' . Text::oneLine($entry['message'], 300);
        });

        return count($entries) . " diagnostics in {$path} ({$summary}):\n" . $body;
    }

    private function capped(array $items, int $maxCount, \Closure $render): string
    {
        $blocks = [];
        $bytes = 0;
        foreach ($items as $item) {
            if (count($blocks) >= $maxCount) {
                break;
            }
            $block = $render($item);
            if ($blocks !== [] && $bytes + strlen($block) + 1 > $this->maxBytes) {
                break;
            }
            $blocks[] = $block;
            $bytes += strlen($block) + 1;
        }
        $more = count($items) - count($blocks);

        return implode("\n", $blocks) . ($more > 0 ? "\n[{$more} more not shown; ask about a narrower position, symbol or query]" : '');
    }

    private function places(mixed $result): array
    {
        if (!is_array($result)) {
            return [];
        }
        $places = [];
        foreach (array_is_list($result) ? $result : [$result] as $item) {
            $uri = is_array($item) ? ($item['targetUri'] ?? $item['uri'] ?? null) : null;
            if (is_string($uri)) {
                $place = $this->place($uri, $item['targetSelectionRange'] ?? $item['range'] ?? null);
                $places[$place['path'] . ':' . ($place['line'] ?? -1) . ':' . $place['character']] = $place;
            }
        }
        usort($places, static fn (array $a, array $b): int => [$a['path'], $a['line'] ?? -1, $a['character']] <=> [$b['path'], $b['line'] ?? -1, $b['character']]);

        return $places;
    }

    private function place(string $uri, mixed $range): array
    {
        $absolute = LspFiles::path($uri);
        $line = $range['start']['line'] ?? null;
        $character = $range['start']['character'] ?? 0;

        return [
            'uri' => $uri,
            'absolute' => $absolute,
            'path' => $absolute === null ? $uri : $this->workspace->relative($absolute),
            'line' => is_int($line) ? $line : null,
            'character' => is_int($character) ? $character : 0,
        ];
    }

    private function label(array $place): string
    {
        return $place['line'] === null ? $place['path'] : $place['path'] . ':' . $this->position($place);
    }

    private function position(array $place): string
    {
        $text = $this->sourceLine($place);
        $column = ($text === null ? $place['character'] : LspPositions::toCharacters($text, $place['character'])) + 1;

        return ($place['line'] ?? 0) + 1 . ':' . $column;
    }

    private function sourceLine(array $place): ?string
    {
        $lines = $this->sourceLines($place);

        return $lines === null || $place['line'] === null ? null : ($lines[$place['line']] ?? null);
    }

    private function sourceLines(array $place): ?array
    {
        $absolute = $place['absolute'];
        if ($absolute === null) {
            return null;
        }
        if (!array_key_exists($absolute, $this->sources)) {
            $this->sources[$absolute] = $this->load($absolute);
        }

        return $this->sources[$absolute];
    }

    private function load(string $absolute): ?array
    {
        try {
            $real = $this->workspace->resolve($absolute);
        } catch (PathException) {
            return null;
        }
        if (SecretFiles::guarded($this->workspace->relative($real)) || !is_file($real) || filesize($real) > self::MAX_SOURCE_BYTES) {
            return null;
        }
        $text = (string) file_get_contents($real);

        return Text::isBinary(substr($text, 0, 8192)) ? null : LspFiles::lines($text);
    }

    private function markup(mixed $contents): string
    {
        if (is_string($contents)) {
            return $contents;
        }
        if (!is_array($contents)) {
            return '';
        }
        if (array_is_list($contents)) {
            return implode("\n\n", array_filter(array_map(fn (mixed $part): string => trim($this->markup($part)), $contents), static fn (string $part): bool => $part !== ''));
        }
        $value = is_string($contents['value'] ?? null) ? $contents['value'] : '';
        $language = $contents['language'] ?? null;

        return is_string($language) && $language !== '' ? "```{$language}\n{$value}\n```" : $value;
    }

    private function collectSymbols(array $symbols, int $depth, array &$lines): void
    {
        foreach ($symbols as $symbol) {
            if (!is_array($symbol) || !is_string($symbol['name'] ?? null)) {
                continue;
            }
            $line = $symbol['selectionRange']['start']['line'] ?? $symbol['range']['start']['line'] ?? $symbol['location']['range']['start']['line'] ?? null;
            $detail = is_string($symbol['detail'] ?? null) && trim($symbol['detail']) !== '' ? '  ' . Text::oneLine($symbol['detail'], 80) : '';
            $lines[] = sprintf('%6s  %s%s %s%s%s', is_int($line) ? $line + 1 : '?', str_repeat('  ', $depth), self::kind($symbol), Text::oneLine($symbol['name'], 120), self::container($symbol), $detail);
            if (is_array($symbol['children'] ?? null)) {
                $this->collectSymbols($symbol['children'], $depth + 1, $lines);
            }
        }
    }

    private static function kind(array $symbol): string
    {
        return self::SYMBOL_KINDS[$symbol['kind'] ?? 0] ?? 'symbol';
    }

    private static function container(array $symbol): string
    {
        $container = $symbol['containerName'] ?? null;

        return is_string($container) && $container !== '' ? ' (' . Text::oneLine($container, 80) . ')' : '';
    }

    private static function fit(string $line): string
    {
        return rtrim(mb_strimwidth($line, 0, self::LINE_WIDTH, '…', 'UTF-8'));
    }
}
