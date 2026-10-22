<?php

declare(strict_types=1);

namespace Phycode\Skills;

use Phycode\Agent\Frontmatter;
use Phycode\Gentle\GentleAi;
use Phycode\Gentle\RegistryRules;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class SkillCatalog
{
    public const PROJECT = 'Project';
    public const GLOBAL = 'Global';
    public const PHYCODE = 'phycode';
    public const GENTLE_AI = 'gentle-ai';

    public const BODY_LINES = 40;

    private const FOLDERS = ['.agents/skills', '.claude/skills'];
    private const READ_BYTES = 65536;
    private const LOCK_BYTES = 1048576;

    public static function discover(string $cwd, ?string $home, ?GentleAi $gentle, ?string $gentleBin = null, array &$notes = [], ?string $userFolder = null, array $pluginFolders = []): array
    {
        $items = [];
        $skipped = 0;
        $seen = [];
        $sources = self::lockedSources($cwd);
        foreach (self::places($cwd, $home, $userFolder, $pluginFolders) as [$category, $folder]) {
            foreach (self::scan($folder, $skipped) as $skill) {
                $real = realpath($skill['path']) ?: $skill['path'];
                if (isset($seen[$real])) {
                    continue;
                }
                $seen[$real] = true;
                $source = $category === self::PROJECT ? ($sources[$skill['name']] ?? $sources[$skill['folder']] ?? '') : '';
                $items[] = [
                    'category' => $category,
                    'title' => $skill['name'],
                    'detail' => $source !== '' ? $source : $skill['description'],
                    'status' => '',
                    'path' => $skill['path'],
                ];
            }
        }

        if ($skipped > 0) {
            $notes[] = "skipped {$skipped} " . ($skipped === 1 ? 'folder' : 'folders') . ' without a valid SKILL.md (name and description are required)';
        }
        if ($gentle !== null) {
            $skills = (new SkillRegistry($gentle, $gentleBin, $cwd))->skills($reason);
            if ($reason !== null && $reason !== GentleAi::NOT_INSTALLED) {
                $notes[] = "the gentle-ai skills cannot be listed: {$reason}";
            }
            foreach ($skills as $skill) {
                $real = realpath($skill->path) ?: $skill->path;
                if (RegistryRules::excluded($skill->name) || isset($seen[$real])) {
                    continue;
                }
                $seen[$real] = true;
                $items[] = ['category' => self::GENTLE_AI, 'title' => $skill->name, 'detail' => $skill->description, 'status' => '', 'path' => $skill->path];
            }
        }

        return $items;
    }

    public static function userFolder(string $configDir): string
    {
        return rtrim($configDir, '/') . '/skills';
    }

    public static function projectPath(string $path, string $root, ?string $home): bool
    {
        $project = rtrim(realpath($root) ?: $root, '/');
        if ($project === '') {
            return false;
        }
        if ($home !== null && $home !== '' && rtrim(realpath($home) ?: $home, '/') === $project) {
            return false;
        }

        return str_starts_with(realpath($path) ?: $path, $project . '/');
    }

    public static function places(string $cwd, ?string $home, ?string $userFolder, array $pluginFolders = []): array
    {
        $places = [];
        foreach (self::FOLDERS as $folder) {
            $places[] = [self::PROJECT, $cwd . '/' . $folder];
        }
        if ($home !== null && $home !== '') {
            foreach (self::FOLDERS as $folder) {
                $places[] = [self::GLOBAL, $home . '/' . $folder];
            }
        }
        if ($userFolder !== null) {
            $places[] = [self::PHYCODE, $userFolder];
        }
        foreach ($pluginFolders as $folder) {
            $places[] = [self::PHYCODE, $folder];
        }

        return $places;
    }

    public static function folderSkills(string $folder): array
    {
        $skipped = 0;

        return self::scan($folder, $skipped);
    }

    public static function summary(string $path): ?array
    {
        $skill = self::read($path);

        return $skill === null ? null : ['name' => $skill['name'], 'description' => $skill['description']];
    }

    public static function none(): string
    {
        return "No skills are installed.\n\n"
            . "Install one for this project, from a repository:\n  npx skills add <owner/repo>\n\n"
            . "Install one for your user, in every project:\n  npx skills add <owner/repo> -g";
    }

    public static function document(string $path): ?string
    {
        $skill = self::read($path);
        if ($skill === null) {
            return null;
        }

        $lines = ["name: {$skill['name']}", "description: {$skill['description']}", "path: {$path}"];
        if ($skill['body'] !== '') {
            $lines[] = '';
            $lines[] = $skill['body'];
        }
        if ($skill['more']) {
            $lines[] = '[... more lines not shown]';
        }

        return implode("\n", $lines);
    }

    private static function scan(string $folder, int &$skipped): array
    {
        $entries = is_dir($folder) ? @scandir($folder) : false;
        if ($entries === false) {
            return [];
        }

        $skills = [];
        foreach ($entries as $entry) {
            $directory = $folder . '/' . $entry;
            if ($entry === '.' || $entry === '..' || !is_dir($directory) || RegistryRules::excluded($entry)) {
                continue;
            }
            $skill = self::read($directory . '/SKILL.md');
            if ($skill === null) {
                $skipped++;
                continue;
            }
            $skills[] = ['folder' => $entry, 'name' => $skill['name'], 'description' => $skill['description'], 'path' => $directory . '/SKILL.md'];
        }

        return $skills;
    }

    private static function read(string $path): ?array
    {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            return null;
        }
        $size = (int) @filesize($path);
        $raw = (string) fread($handle, self::READ_BYTES);
        fclose($handle);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        try {
            [$fields, $body] = Frontmatter::split($raw, $path);
        } catch (PhycodeException) {
            return null;
        }
        $name = self::line($fields['name'] ?? null);
        $description = self::line($fields['description'] ?? null);
        if ($name === '' || $description === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($body, "\r\n")) ?: [];

        return [
            'name' => $name,
            'description' => $description,
            'body' => self::block(implode("\n", array_slice($lines, 0, self::BODY_LINES))),
            'more' => count($lines) > self::BODY_LINES || $size > self::READ_BYTES,
        ];
    }

    private static function lockedSources(string $cwd): array
    {
        $file = $cwd . '/skills-lock.json';
        if (!is_file($file) || (int) @filesize($file) > self::LOCK_BYTES) {
            return [];
        }
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? (Json::object($raw) ?? []) : [];
        $skills = is_array($data['skills'] ?? null) ? $data['skills'] : [];

        $sources = [];
        foreach ($skills as $name => $entry) {
            $source = is_array($entry) ? self::line($entry['source'] ?? null) : '';
            if ($source !== '') {
                $sources[(string) $name] = $source;
            }
        }

        return $sources;
    }

    private static function line(mixed $value): string
    {
        return is_string($value) ? Text::oneLine(self::plain($value), 300) : '';
    }

    private static function plain(string $text): string
    {
        return (string) preg_replace('/\p{Cc}+/u', ' ', mb_scrub($text, 'UTF-8'));
    }

    private static function block(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', mb_scrub($text, 'UTF-8'));
    }
}
