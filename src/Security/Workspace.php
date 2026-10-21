<?php

declare(strict_types=1);

namespace Phycode\Security;

final class Workspace
{
    private const PROTECTED = ['.git', '.phycode', '.claude'];

    public readonly string $root;
    public readonly array $readOnly;

    public function __construct(string $path, array $folders = [])
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new PathException("project folder does not exist: {$path}");
        }
        if ($real === '/') {
            throw new PathException('refusing to use / as the project folder');
        }
        $this->root = rtrim($real, '/');

        $readOnly = [];
        foreach ($folders as $folder) {
            $resolved = realpath($folder);
            if ($resolved === false || !is_dir($resolved)) {
                throw new PathException("read-only folder does not exist or is not a folder: {$folder}");
            }
            if ($resolved === '/') {
                throw new PathException('refusing to use / as a read-only folder');
            }
            if (self::within($this->root, $resolved) || self::within($resolved, $this->root)) {
                throw new PathException("read-only folder overlaps the project folder {$this->root}: {$folder}");
            }
            $readOnly[] = $resolved;
        }
        $this->readOnly = array_values(array_unique($readOnly));
    }

    public function resolve(string $path): string
    {
        $normalized = $this->inside($path);

        $probe = $normalized;
        $missing = [];
        while (!file_exists($probe) && !is_link($probe)) {
            array_unshift($missing, basename($probe));
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }

        $real = realpath($probe);
        if ($real === false) {
            throw new PathException("broken symbolic link or unreadable path: {$path}");
        }
        $folder = $this->readOnlyFolder($normalized) ?? $this->root;
        if (!self::within($real, $folder)) {
            $where = $folder === $this->root ? 'the project' : 'its read-only folder';
            throw new PathException("path leaves {$where} through a symbolic link: {$path}");
        }

        return $missing === [] ? $real : $real . '/' . implode('/', $missing);
    }

    public function relative(string $absolute): string
    {
        if ($absolute === $this->root) {
            return '.';
        }
        if (!self::within($absolute, $this->root)) {
            return $absolute;
        }

        return substr($absolute, strlen($this->root) + 1);
    }

    public function readOnlyFolder(string $absolute): ?string
    {
        foreach ($this->readOnly as $folder) {
            if (self::within($absolute, $folder)) {
                return $folder;
            }
        }

        return null;
    }

    public function resolveWritable(string $path): string
    {
        $this->assertWritable($this->inside($path));
        $real = $this->resolve($path);
        $this->assertWritable($real);

        return $real;
    }

    public function resolveEntry(string $path): string
    {
        $entry = $this->inside($path);
        if ($entry === $this->root) {
            throw new PathException('the project folder itself cannot be changed');
        }
        $this->assertWritable($entry);
        $real = $this->resolve($this->relative(dirname($entry))) . '/' . basename($entry);
        $this->assertWritable($real);

        return $real;
    }

    public function assertWritable(string $absolute): void
    {
        $folder = $this->readOnlyFolder($absolute);
        if ($folder !== null) {
            throw new PathException("read-only folder {$folder}: changes are refused, it can only be read");
        }
        $this->assertUnprotected($this->relative($absolute));
    }

    public function assertTreeUnprotected(string $absolute): void
    {
        try {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($entries as $entry) {
                if (in_array(strtolower($entry->getFilename()), self::PROTECTED, true)) {
                    throw new PathException('protected folder: ' . $this->relative($entry->getPathname()));
                }
            }
        } catch (\UnexpectedValueException $error) {
            throw new PathException('cannot check ' . $this->relative($absolute) . ' for protected folders: ' . $error->getMessage());
        }
    }

    public function assertNotNamed(string $text): void
    {
        foreach ($this->readOnly as $folder) {
            if (preg_match('#' . preg_quote($folder, '#') . '(?![\w.-])#', $text) === 1) {
                throw new PathException("read-only folder {$folder}: commands work in the project only; read it with read, list or grep");
            }
        }
    }

    private function inside(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new PathException('invalid path');
        }
        $candidate = str_starts_with($path, '/') ? $path : $this->root . '/' . $path;
        $normalized = self::normalize($candidate);
        if (!self::within($normalized, $this->root) && $this->readOnlyFolder($normalized) === null) {
            throw new PathException("path is outside the project {$this->root} and its read-only folders; use a path inside them, such as a relative path");
        }

        return $normalized;
    }

    private function assertUnprotected(string $relative): void
    {
        foreach (explode('/', $relative) as $part) {
            if (in_array(strtolower($part), self::PROTECTED, true)) {
                throw new PathException("protected folder: {$relative}");
            }
        }
    }

    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    private static function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
