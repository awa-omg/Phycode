<?php

declare(strict_types=1);

namespace Phycode\RepoMap;

use Phycode\Exec\ProcessRunner;
use Phycode\Security\SecretFiles;
use Phycode\Support\Text;

final class RepoMap
{
    public const MAX_FILES = 5000;
    public const MAX_FILE_BYTES = 524288;
    public const MAX_SCAN_BYTES = 40_000_000;
    private const MAX_SYMBOLS_PER_FILE = 40;
    private const SKIPPED_FOLDERS = ['.git', '.phycode', '.claude', 'node_modules', 'vendor', 'dist', 'build', 'target', '__pycache__', '.venv', 'venv', '.next', '.cache', 'coverage'];

    private array $known = [];

    public function __construct(private readonly string $root, private readonly ?ProcessRunner $processes = null)
    {
    }

    public function build(string $focus = '', string $path = '.', int $tokens = 1500): array
    {
        [$paths, $skipped] = $this->files($path);
        $index = $this->index($paths);
        [$score, $weight] = $this->scores($index, $focus);

        $order = array_keys($index);
        usort($order, static fn (string $a, string $b): int => [$score[$b], $a] <=> [$score[$a], $b]);

        $budget = max(500, $tokens) * 4;
        $perFile = max(4, min(self::MAX_SYMBOLS_PER_FILE, (int) round(sqrt($budget / 60))));
        $lines = [];
        $used = 0;
        $shown = 0;
        foreach ($order as $file) {
            $block = self::render($file, $index[$file], $perFile, $weight);
            if ($shown > 0 && $used + strlen($block) > $budget) {
                break;
            }
            $lines[] = $block;
            $used += strlen($block);
            $shown++;
        }
        $symbols = array_sum(array_map('count', $index));
        $more = count($order) - $shown;
        $head = sprintf('Repository map of %s: %d files define %d things%s. Most used first.', $path === '.' ? 'the project' : $path, count($order), $symbols, $focus !== '' ? ", focused on \"{$focus}\"" : '');
        $tail = ($more > 0 ? "\n... {$more} more files with definitions are not shown; give a query to focus on a subject, a path to look into a folder, or more tokens." : '')
            . ($skipped > 0 ? "\n({$skipped} files were left out: too large, binary, or past the limit of " . self::MAX_FILES . ' files.)' : '');

        return ['text' => $head . "\n\n" . implode("\n", $lines) . $tail, 'files' => count($order), 'shown' => $shown, 'symbols' => $symbols, 'skipped' => $skipped];
    }

    private function files(string $path): array
    {
        $prefix = trim($path, '/.') === '' ? '' : rtrim(trim($path, '/'), '/') . '/';
        $all = $this->listFiles();
        $paths = [];
        $skipped = 0;
        foreach ($all as $file) {
            if ($prefix !== '' && !str_starts_with($file, $prefix)) {
                continue;
            }
            if (SymbolExtractor::languageOf($file) === null || SecretFiles::matches($file)) {
                continue;
            }
            if (count($paths) >= self::MAX_FILES) {
                $skipped++;
                continue;
            }
            $paths[] = $file;
        }

        return [$paths, $skipped];
    }

    private function listFiles(): array
    {
        if ($this->processes !== null && file_exists($this->root . '/.git')) {
            $result = $this->processes->run(['git', '-C', $this->root, 'ls-files', '-co', '--exclude-standard', '-z'], $this->root, 30, 16 * 1024 * 1024);
            if ($result->succeeded()) {
                $files = array_values(array_filter(explode("\0", $result->stdout), static fn (string $file): bool => $file !== ''));
                sort($files, SORT_STRING);

                return $files;
            }
        }

        return $this->walk();
    }

    private function walk(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $entry): bool => !$entry->isLink() && !($entry->isDir() && in_array($entry->getFilename(), self::SKIPPED_FOLDERS, true)),
        ));
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $files[] = substr($entry->getPathname(), strlen($this->root) + 1);
            }
            if (count($files) > self::MAX_FILES * 4) {
                break;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function index(array $paths): array
    {
        $index = [];
        $bytes = 0;
        foreach ($paths as $file) {
            $absolute = $this->root . '/' . $file;
            if (is_link($absolute) || !is_file($absolute)) {
                continue;
            }
            $size = (int) filesize($absolute);
            $key = $size . ':' . (int) filemtime($absolute);
            if (isset($this->known[$file]) && $this->known[$file]['key'] === $key) {
                $symbols = $this->known[$file]['symbols'];
            } else {
                if ($size > self::MAX_FILE_BYTES || $size === 0 || $bytes + $size > self::MAX_SCAN_BYTES) {
                    continue;
                }
                $text = (string) file_get_contents($absolute);
                if (Text::isBinary(substr($text, 0, 8192))) {
                    continue;
                }
                $bytes += $size;
                $symbols = SymbolExtractor::extract($file, $text);
                $this->known[$file] = ['key' => $key, 'symbols' => $symbols];
            }
            if ($symbols !== []) {
                $index[$file] = $symbols;
            }
        }

        return $index;
    }

    private function scores(array $index, string $focus): array
    {
        $definers = [];
        foreach ($index as $file => $symbols) {
            foreach ($symbols as $symbol) {
                if (strlen($symbol['name']) >= 3) {
                    $definers[$symbol['name']][$file] = true;
                }
            }
        }
        $users = [];
        foreach (array_keys($index) as $file) {
            $text = (string) @file_get_contents($this->root . '/' . $file);
            if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]{2,}/', $text, $matches) === false) {
                continue;
            }
            foreach (array_keys(array_flip($matches[0])) as $word) {
                if (isset($definers[$word]) && !isset($definers[$word][$file])) {
                    $users[$word] = ($users[$word] ?? 0) + 1;
                }
            }
        }

        $weight = [];
        foreach ($definers as $name => $files) {
            $weight[(string) $name] = log(1 + ($users[$name] ?? 0) / max(1, count($files)));
        }
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($focus)) ?: [], static fn (string $word): bool => strlen($word) >= 2));
        $score = [];
        foreach ($index as $file => $symbols) {
            $total = 0.0;
            foreach ($symbols as $symbol) {
                $name = $symbol['name'];
                $total += ($weight[$name] ?? 0.0) * (in_array($symbol['kind'], ['method', 'function'], true) ? 0.5 : 1.0);
            }
            $total += 0.05 * sqrt(count($symbols));
            $lower = mb_strtolower($file);
            $about = 0;
            foreach ($words as $word) {
                $about += str_contains($lower, $word) ? 20 : 0;
                $about += min(15, 5 * count(array_filter($symbols, static fn (array $symbol): bool => str_contains(mb_strtolower($symbol['name']), $word))));
            }
            $total += $about > 0 ? 10000 + $about : 0;
            $score[$file] = $total;
        }

        return [$score, $weight];
    }

    private static function render(string $file, array $symbols, int $limit, array $weight): string
    {
        $total = count($symbols);
        if ($total > $limit) {
            $rank = static fn (array $symbol): float => ($weight[$symbol['name']] ?? 0.0) + ($symbol['kind'] === 'method' || $symbol['kind'] === 'function' ? 0.0 : 100.0);
            $ranked = array_keys($symbols);
            usort($ranked, static fn (int $a, int $b): int => [$rank($symbols[$b]), $a] <=> [$rank($symbols[$a]), $b]);
            $keep = array_flip(array_slice($ranked, 0, $limit));
            $symbols = array_values(array_filter($symbols, static fn (int $index): bool => isset($keep[$index]), ARRAY_FILTER_USE_KEY));
        }
        $names = array_column(array_filter($symbols, static fn (array $symbol): bool => $symbol['kind'] !== 'method'), 'name');
        $children = [];
        $top = [];
        foreach ($symbols as $symbol) {
            if ($symbol['kind'] === 'method' && $symbol['parent'] !== null && in_array($symbol['parent'], $names, true)) {
                $children[$symbol['parent']][] = $symbol;
            } else {
                $top[] = $symbol;
            }
        }

        $lines = [$file];
        foreach ($top as $symbol) {
            $lines[] = '  ' . ($symbol['kind'] === 'method' ? "{$symbol['parent']}.{$symbol['sig']}" : $symbol['sig']) . ' :' . $symbol['line'];
            foreach ($children[$symbol['name']] ?? [] as $method) {
                $lines[] = '    ' . $method['sig'] . ' :' . $method['line'];
            }
        }
        if ($total > count($symbols)) {
            $lines[] = '  ... ' . ($total - count($symbols)) . ' more';
        }

        return implode("\n", $lines);
    }
}
