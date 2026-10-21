<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Exec\ProcessRunner;
use Phycode\Security\SecretFiles;
use Phycode\Security\Workspace;
use Phycode\Support\Text;

final class GrepTool extends BaseTool
{
    private const MAX_LINES = 100;
    private const MAX_FALLBACK_LINES = 1000;
    private const IGNORED = ['.git', '.phycode', 'node_modules', 'vendor', '__pycache__'];

    public function name(): string
    {
        return 'grep';
    }

    public function description(): string
    {
        return 'Search file contents. Literal text by default; set regex to true for a regular expression. Returns path:line: text.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Read;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string', 'description' => 'Text or regular expression to find'],
                'path' => ['type' => 'string', 'description' => 'Folder or file relative to the project root. Default: .'],
                'glob' => ['type' => 'string', 'description' => 'Only search files whose name matches, for example *.php'],
                'ignore_case' => ['type' => 'boolean', 'description' => 'Ignore letter case'],
                'regex' => ['type' => 'boolean', 'description' => 'Treat pattern as a regular expression'],
            ],
            'required' => ['pattern'],
        ];
    }

    public function forcesApproval(array $args): bool
    {
        return is_string($args['path'] ?? null) && SecretFiles::guarded($args['path']);
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $pattern = Args::string($args, 'pattern');
        if (trim($pattern) === '') {
            return ToolResult::fail('pattern is empty');
        }
        $path = Args::string($args, 'path', false);
        $glob = Args::string($args, 'glob', false);
        $ignoreCase = Args::bool($args, 'ignore_case', false);
        $regex = Args::bool($args, 'regex', false);

        $target = $context->workspace->resolve($path === '' ? '.' : $path);
        $relative = $context->workspace->relative($target);
        if (SecretFiles::guarded($relative) && !SecretFiles::guarded($path)) {
            return ToolResult::fail("refused: {$path} leads to {$relative}, which usually holds secrets or phycode's own files; search {$relative} by its own name, which asks first");
        }
        $root = $context->workspace->root;

        $ripgrep = ProcessRunner::which('rg');
        if ($ripgrep !== null) {
            $argv = [$ripgrep, '--line-number', '--no-heading', '--color', 'never', '--max-count', '50', '--max-filesize', '1M',
                '--glob', '!.git', '--glob', '!.phycode', '--glob', '!node_modules', '--glob', '!vendor'];
            if ($ignoreCase) {
                $argv[] = '--ignore-case';
            }
            if (!$regex) {
                $argv[] = '--fixed-strings';
            }
            if ($glob !== '') {
                $argv[] = '--glob';
                $argv[] = $glob;
            }
            $argv[] = '--regexp';
            $argv[] = $pattern;
            $argv[] = '--';
            $argv[] = $relative;
            $result = $context->processes->run($argv, $root, 30, $context->limits->toolOutputBytes * 4, $context->cancel);
            if ($result->exitCode === 1 && $result->interrupted === null) {
                return ToolResult::ok('no matches');
            }
            if (!$result->succeeded()) {
                return ToolResult::fail(trim($result->stderr) !== '' ? trim($result->stderr) : 'search failed');
            }
            $lines = array_values(array_filter(
                array_map(static fn (string $line): string => (string) preg_replace('#^\./#', '', $line), explode("\n", rtrim($result->stdout))),
                static fn (string $line): bool => $line !== '',
            ));
        } else {
            if ($regex && @preg_match(self::regex($pattern, $ignoreCase), '') === false) {
                return ToolResult::fail('invalid regular expression');
            }
            $lines = $this->phpSearch($target, $context->workspace, $pattern, $regex, $ignoreCase, $glob);
        }

        $skipped = 0;
        if (!SecretFiles::matches($path)) {
            [$lines, $skipped] = SecretFiles::withoutSecrets($lines);
        }
        if ($lines === []) {
            return ToolResult::ok($skipped > 0 ? "no matches outside the files that usually hold secrets ({$skipped} lines left out)" : 'no matches');
        }
        $total = count($lines);
        $lines = array_map(static fn (string $line): string => Text::oneLine($line, 300), array_slice($lines, 0, self::MAX_LINES));
        $footer = $total > self::MAX_LINES ? "\n[... more matches; narrow the pattern or the path]" : '';
        $footer .= $skipped > 0 ? "\n[{$skipped} matches in files that usually hold secrets (.env, keys) are left out]" : '';

        return ToolResult::ok(implode("\n", $lines) . $footer, ['matches' => $total]);
    }

    private function phpSearch(string $target, Workspace $workspace, string $pattern, bool $regex, bool $ignoreCase, string $glob): array
    {
        $files = is_file($target)
            ? [new \SplFileInfo($target)]
            : new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $entry): bool => !in_array($entry->getFilename(), self::IGNORED, true),
            ));

        $lines = [];
        foreach ($files as $file) {
            if ($file->isLink() || !$file->isFile() || $file->getSize() > 1048576) {
                continue;
            }
            if ($glob !== '' && !fnmatch($glob, $file->getFilename())) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            if (Text::isBinary(substr($content, 0, 8192))) {
                continue;
            }
            $name = $workspace->relative($file->getPathname());
            foreach (explode("\n", $content) as $number => $line) {
                $matched = $regex
                    ? preg_match(self::regex($pattern, $ignoreCase), $line) === 1
                    : ($ignoreCase ? stripos($line, $pattern) !== false : str_contains($line, $pattern));
                if ($matched) {
                    $lines[] = $name . ':' . ($number + 1) . ': ' . $line;
                    if (count($lines) >= self::MAX_FALLBACK_LINES) {
                        return $lines;
                    }
                }
            }
        }

        return $lines;
    }

    private static function regex(string $pattern, bool $ignoreCase): string
    {
        return '/' . str_replace('/', '\/', $pattern) . '/' . ($ignoreCase ? 'iu' : 'u');
    }
}
