<?php

declare(strict_types=1);

namespace Phycode\Skills;

use Phycode\Support\Files;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;
use Symfony\Component\Yaml\Yaml;

final class SkillWriter
{
    private const FOLDER_MODE = 0700;
    private const FILE_MODE = 0600;
    private const SNIPPET_LINES = 15;
    private const SNIPPET_BYTES = 1200;
    private const BINARY_SAMPLE = 8192;

    private readonly string $root;
    private readonly string $trash;
    private readonly SkillLog $log;

    public function __construct(string $configDir)
    {
        $this->root = SkillCatalog::userFolder($configDir);
        $this->trash = rtrim($configDir, '/') . '/trash';
        $this->log = new SkillLog($this->root);
    }

    public function create(string $name, string $description, string $body): SkillChange
    {
        SkillRules::checkName($name);
        $description = trim($description);
        SkillRules::checkDescription($description);
        $text = "---\n" . Yaml::dump(['name' => $name, 'description' => $description]) . "---\n\n" . trim($body) . "\n";
        SkillRules::checkSkillFile($text, $name);
        SkillScanner::check($text);
        $folder = $this->root . '/' . $name;
        if (file_exists($folder) || is_link($folder)) {
            throw new SkillException("a skill named {$name} already exists; use patch to change it");
        }

        return new SkillChange('create', $name, $folder . '/' . SkillRules::SKILL_FILE, "{$name}/" . SkillRules::SKILL_FILE, null, $text);
    }

    public function patch(string $name, string $file, string $old, string $new, bool $replaceAll): SkillChange
    {
        if ($old === '') {
            throw new SkillException('old_string is empty; use write_file to create or replace a whole file');
        }
        if ($old === $new) {
            throw new SkillException('old_string and new_string are identical; nothing to change');
        }
        $isSkillFile = self::isSkillFile($file);
        [$path, $relative] = $isSkillFile ? $this->skillFile($name) : $this->existingFile($name, $file);
        $text = self::read($path, $relative);
        $change = self::replace($text, $old, $new, $replaceAll);
        if ($change['count'] === 0) {
            throw new SkillException("old_string not found in {$relative}. Copy the exact text, including indentation. The file reads:\n" . self::snippet($text, $old));
        }
        if ($change['count'] > 1 && !$replaceAll) {
            throw new SkillException("old_string matches {$change['count']} places in {$relative}; add surrounding lines to make it unique, or set replace_all to true");
        }
        if ($isSkillFile) {
            SkillRules::checkSkillFile($change['updated'], $name);
        } else {
            SkillRules::checkFileSize($change['updated']);
        }
        SkillScanner::check($change['updated']);

        return new SkillChange('patch', $name, $path, $relative, $text, $change['updated'], $change['count'], $change['line']);
    }

    public function writeFile(string $name, string $file, string $content): SkillChange
    {
        [$path, $relative] = $this->filePath($name, $file);
        if (is_dir($path)) {
            throw new SkillException("{$relative} is a folder");
        }
        if (str_contains($content, "\0")) {
            throw new SkillException('content has a NUL byte: a skill file is text');
        }
        SkillRules::checkFileSize($content);
        SkillScanner::check($content);
        $before = null;
        if (is_file($path)) {
            $before = self::textOf($path);
            if ($before === null) {
                throw new SkillException("{$relative} is binary or larger than 1 MB; remove it first, so that its content is kept in the trash");
            }
        }

        return new SkillChange('write_file', $name, $path, $relative, $before, $content);
    }

    public function removeFile(string $name, string $file): SkillChange
    {
        [$path, $relative] = $this->filePath($name, $file);
        if (!is_file($path)) {
            throw new SkillException("no file {$file} in skill {$name}");
        }

        return new SkillChange('remove_file', $name, $path, $relative, self::textOf($path), null);
    }

    public function archive(string $name): SkillChange
    {
        $folder = $this->skillFolder($name);
        $path = $folder . '/' . SkillRules::SKILL_FILE;

        return new SkillChange('archive', $name, $path, "{$name}/" . SkillRules::SKILL_FILE, is_link($path) ? null : self::textOf($path), null);
    }

    public function merge(string $into, array $from, string $content): SkillChange
    {
        SkillRules::checkName($into);
        if (count($from) < 2) {
            throw new SkillException('merge needs at least two skills in from, to join into one');
        }
        $listed = [];
        foreach ($from as $name) {
            SkillRules::checkName($name);
            if ($name === $into) {
                throw new SkillException("{$name} is the skill that merge writes, so it cannot be in from");
            }
            if (isset($listed[$name])) {
                throw new SkillException("{$name} is listed twice in from");
            }
            $listed[$name] = true;
            $folder = $this->root . '/' . $name;
            if (!is_dir($folder) || is_link($folder)) {
                throw new SkillException("{$name} is not a skill saved by phycode, so merge cannot archive it");
            }
        }
        $folder = $this->root . '/' . $into;
        if (is_link($folder) || (file_exists($folder) && !is_dir($folder))) {
            throw new SkillException("{$into} is not a real folder of skills");
        }
        SkillRules::checkSkillFile($content, $into);
        SkillScanner::check($content);
        $path = $folder . '/' . SkillRules::SKILL_FILE;
        $before = is_file($path) ? self::read($path, "{$into}/" . SkillRules::SKILL_FILE) : null;

        return new SkillChange('merge', $into, $path, "{$into}/" . SkillRules::SKILL_FILE, $before, $content, from: $from);
    }

    public function restore(string $name): SkillChange
    {
        SkillRules::checkName($name);
        if (file_exists($this->root . '/' . $name) || is_link($this->root . '/' . $name)) {
            throw new SkillException("a skill named {$name} already exists; restore never overwrites it");
        }
        $source = $this->latestArchive($name);
        if ($source === null) {
            throw new SkillException("no archived copy of {$name} in the trash");
        }

        return new SkillChange(
            'restore',
            $name,
            $this->root . "/{$name}/" . SkillRules::SKILL_FILE,
            "{$name}/" . SkillRules::SKILL_FILE,
            null,
            self::textOf($source . '/' . SkillRules::SKILL_FILE),
            source: $source,
        );
    }

    public function apply(SkillChange $change): string
    {
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd-His-u') . '-' . bin2hex(random_bytes(3));
        $message = match ($change->action) {
            'create' => $this->makeSkill($change),
            'patch', 'write_file' => $this->overwrite($change, $stamp),
            'remove_file' => $this->trashFile($change, $stamp),
            'archive' => $this->archiveSkill($change, $stamp),
            'merge' => $this->join($change, $stamp),
            'restore' => $this->restoreSkill($change),
        };
        try {
            $this->log->record($change->action, $change->skill, $change->from);
        } catch (SkillException $error) {
            $message .= ' (the change is kept, but the registry was not written: ' . $error->getMessage() . ')';
        }

        return $message;
    }

    private function makeSkill(SkillChange $change): string
    {
        self::makeFolders($this->root);
        $folder = dirname($change->path);
        if (!@mkdir($folder, self::FOLDER_MODE)) {
            throw new SkillException(is_dir($folder)
                ? "a skill named {$change->skill} already exists; use patch to change it"
                : "cannot create the folder {$folder}");
        }
        $text = (string) $change->after;
        try {
            self::write($change->path, $text, self::FILE_MODE);
        } catch (SkillException $error) {
            @rmdir($folder);

            throw $error;
        }

        return "created {$change->path} (" . self::counted($text) . ')';
    }

    private function overwrite(SkillChange $change, string $stamp): string
    {
        $text = (string) $change->after;
        $kept = null;
        $mode = self::FILE_MODE;
        if ($change->before !== null) {
            $kept = $this->keep($change, $stamp);
            $mode = (int) fileperms($change->path) & 0777;
        }
        self::makeFolders(dirname($change->path));
        self::write($change->path, $text, $mode);

        if ($kept === null) {
            return "created {$change->path} (" . self::counted($text) . ')';
        }
        if ($change->action === 'patch') {
            return "patched {$change->path}: replaced {$change->occurrences} occurrence(s), first at line {$change->line}; the previous text is kept at {$kept}";
        }

        return "overwrote {$change->path} (" . self::counted($text) . "); the previous content is kept at {$kept}";
    }

    private function trashFile(SkillChange $change, string $stamp): string
    {
        $trashed = $this->trashPath($stamp, $change->relative);
        self::makeFolders(dirname($trashed));
        if (!@rename($change->path, $trashed)) {
            throw new SkillException("cannot move {$change->relative} to the trash; nothing was removed");
        }

        return "removed {$change->path}; it is kept at {$trashed}";
    }

    private function archiveSkill(SkillChange $change, string $stamp): string
    {
        $trashed = $this->trashPath($stamp, $change->skill);
        self::makeFolders(dirname($trashed));
        if (!@rename(dirname($change->path), $trashed)) {
            throw new SkillException("cannot move skill {$change->skill} to the trash; nothing was removed");
        }

        return "archived skill {$change->skill}: its folder is kept at {$trashed}, and it is no longer listed or loaded";
    }

    private function join(SkillChange $change, string $stamp): string
    {
        $folderExisted = is_dir(dirname($change->path));
        $created = $change->before === null;
        $mode = self::FILE_MODE;
        $kept = null;
        $moved = [];
        try {
            if (!$created) {
                $kept = $this->keep($change, $stamp);
                $mode = (int) fileperms($change->path) & 0777;
            }
            self::makeFolders(dirname($change->path));
            self::write($change->path, (string) $change->after, $mode);
            foreach ($change->from as $name) {
                $trashed = $this->trashPath($stamp, $name);
                self::makeFolders(dirname($trashed));
                if (!@rename($this->root . '/' . $name, $trashed)) {
                    throw new SkillException("cannot move {$name} to the trash");
                }
                $moved[$name] = $trashed;
            }
        } catch (SkillException $error) {
            $problem = $this->undoJoin($change, $created, $folderExisted, $mode, $moved, $stamp);
            throw new SkillException($error->getMessage() . '; nothing was joined' . ($problem === null ? '' : " ({$problem})"));
        }

        return 'joined ' . implode(', ', $change->from) . " into {$change->path}; archived: " . implode(', ', $moved)
            . ($kept === null ? '' : "; the previous SKILL.md is kept at {$kept}");
    }

    private function undoJoin(SkillChange $change, bool $created, bool $folderExisted, int $mode, array $moved, string $stamp): ?string
    {
        $problems = [];
        foreach (array_reverse($moved, true) as $name => $trashed) {
            if (!@rename($trashed, $this->root . '/' . $name)) {
                $problems[] = "{$name} stays in {$trashed}";
            }
        }
        try {
            if ($created) {
                $from = $folderExisted ? $change->path : dirname($change->path);
                $to = $folderExisted
                    ? $this->trashPath($stamp, $change->skill . '/' . SkillRules::SKILL_FILE)
                    : $this->trashPath($stamp, $change->skill);
                self::makeFolders(dirname($to));
                if (!@rename($from, $to)) {
                    $problems[] = "the new SKILL.md of {$change->skill} stays in place";
                }
            } else {
                self::write($change->path, (string) $change->before, $mode);
            }
        } catch (SkillException $error) {
            $problems[] = $error->getMessage();
        }

        return $problems === [] ? null : implode('; ', $problems);
    }

    private function restoreSkill(SkillChange $change): string
    {
        self::makeFolders($this->root);
        if (!@rename((string) $change->source, dirname($change->path))) {
            throw new SkillException("cannot move {$change->skill} back from the trash; nothing was restored");
        }

        return "restored {$change->path} from {$change->source}";
    }

    private function keep(SkillChange $change, string $stamp): string
    {
        $copy = $this->trashPath($stamp, $change->relative);
        self::makeFolders(dirname($copy));
        self::write($copy, (string) $change->before, self::FILE_MODE);

        return $copy;
    }

    private function trashPath(string $stamp, string $relative): string
    {
        $this->assertTrash();

        return $this->trash . '/' . $stamp . '/skills/' . $relative;
    }

    private function latestArchive(string $name): ?string
    {
        $this->assertTrash();
        $stamps = is_dir($this->trash) ? (scandir($this->trash) ?: []) : [];
        rsort($stamps);
        foreach ($stamps as $stamp) {
            $folder = $this->trash . '/' . $stamp . '/skills/' . $name;
            if ($stamp !== '.' && $stamp !== '..' && is_dir($folder) && !is_link($folder) && is_file($folder . '/' . SkillRules::SKILL_FILE)) {
                return $folder;
            }
        }

        return null;
    }

    private function assertTrash(): void
    {
        if (is_link($this->trash)) {
            throw new SkillException("{$this->trash} is a symbolic link; phycode keeps its own files only in real folders");
        }
    }

    private function skillFolder(string $name): string
    {
        SkillRules::checkName($name);
        $folder = $this->root . '/' . $name;
        if (is_link($folder)) {
            throw new SkillException("skill {$name} is a symbolic link; skill_manage changes only real folders");
        }
        if (!is_dir($folder)) {
            throw new SkillException("no skill named {$name}; create it first with create");
        }

        return $folder;
    }

    private function skillFile(string $name): array
    {
        $path = $this->skillFolder($name) . '/' . SkillRules::SKILL_FILE;
        if (is_link($path)) {
            throw new SkillException("the SKILL.md of {$name} is a symbolic link; skill_manage changes only real files");
        }
        if (!is_file($path)) {
            throw new SkillException("skill {$name} has no SKILL.md");
        }

        return [$path, "{$name}/" . SkillRules::SKILL_FILE];
    }

    private function existingFile(string $name, string $file): array
    {
        [$path, $relative] = $this->filePath($name, $file);
        if (!is_file($path)) {
            throw new SkillException("no file {$file} in skill {$name}; write_file creates one");
        }

        return [$path, $relative];
    }

    private function filePath(string $name, string $file): array
    {
        SkillRules::checkFilePath($file);
        $folder = $this->skillFolder($name);
        $target = $folder . '/' . $file;
        $probe = $folder;
        foreach (explode('/', $file) as $part) {
            $probe .= '/' . $part;
            if (is_link($probe)) {
                throw new SkillException("{$file} goes through a symbolic link; skill_manage changes only real files and folders");
            }
            if (!file_exists($probe)) {
                break;
            }
        }
        $parent = dirname($target);
        while (!is_dir($parent)) {
            $parent = dirname($parent);
        }
        $real = realpath($parent);
        $base = realpath($folder);
        if ($real === false || $base === false || ($real !== $base && !str_starts_with($real, $base . '/'))) {
            throw new SkillException("{$file} would be outside the skill {$name}");
        }

        return [$target, "{$name}/{$file}"];
    }

    private static function read(string $path, string $relative): string
    {
        $text = self::textOf($path);
        if ($text === null) {
            throw new SkillException("{$relative} cannot be changed: it is not a text file of 1 MB or less");
        }

        return $text;
    }

    private static function textOf(string $path): ?string
    {
        if (!is_file($path) || (int) filesize($path) > SkillRules::FILE_MAX_BYTES) {
            return null;
        }
        $text = @file_get_contents($path);

        return is_string($text) && !Text::isBinary(substr($text, 0, self::BINARY_SAMPLE)) ? $text : null;
    }

    private static function replace(string $text, string $old, string $new, bool $replaceAll): array
    {
        $needle = $old;
        $replacement = $new;
        if (str_contains($text, "\r\n")) {
            $needle = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $needle));
            $replacement = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $replacement));
        }
        $count = substr_count($text, $needle);
        if ($count === 0) {
            return ['count' => 0, 'line' => 0, 'updated' => $text];
        }
        $position = (int) strpos($text, $needle);

        return [
            'count' => $count,
            'line' => substr_count(substr($text, 0, $position), "\n") + 1,
            'updated' => $replaceAll
                ? str_replace($needle, $replacement, $text)
                : substr_replace($text, $replacement, $position, strlen($needle)),
        ];
    }

    private static function snippet(string $text, string $old): string
    {
        $lines = explode("\n", $text);
        $first = '';
        foreach (explode("\n", $old) as $line) {
            if (trim($line) !== '') {
                $first = trim($line);
                break;
            }
        }
        $start = 0;
        foreach ($lines as $index => $line) {
            if ($first !== '' && str_contains($line, $first)) {
                $start = max(0, $index - 2);
                break;
            }
        }
        $rows = [];
        for ($index = $start; $index < min(count($lines), $start + self::SNIPPET_LINES); $index++) {
            $rows[] = sprintf('%6d  %s', $index + 1, rtrim($lines[$index], "\r"));
        }

        return Text::headTail(implode("\n", $rows), self::SNIPPET_BYTES);
    }

    private static function write(string $path, string $text, int $mode): void
    {
        try {
            Files::writeAtomic($path, $text, $mode);
        } catch (PhycodeException $error) {
            throw new SkillException($error->getMessage());
        }
    }

    private static function makeFolders(string $folder): void
    {
        if (!is_dir($folder) && !@mkdir($folder, self::FOLDER_MODE, true) && !is_dir($folder)) {
            throw new SkillException("cannot create the folder {$folder}");
        }
    }

    private static function counted(string $text): string
    {
        $lines = Files::lineCount($text);

        return $lines . ($lines === 1 ? ' line' : ' lines');
    }

    private static function isSkillFile(string $file): bool
    {
        return $file === '' || $file === SkillRules::SKILL_FILE;
    }
}
