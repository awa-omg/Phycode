<?php

declare(strict_types=1);

namespace Phycode\Skills;

use Phycode\Support\PhycodeException;

final readonly class SkillLoader
{
    public const MAX_BYTES = 65536;

    private const NOTICE_BYTES = 128;

    public function __construct(
        private SkillRegistry $registry,
        private int $maxBytes = self::MAX_BYTES,
        private array $places = [],
        private string $root = '',
        private ?string $home = null,
    ) {
    }

    public function load(string $name): string
    {
        self::checkName($name);
        foreach ($this->places as [$category, $folder]) {
            if ($category === SkillCatalog::PROJECT) {
                continue;
            }
            foreach (SkillCatalog::folderSkills($folder) as $skill) {
                if ($skill['name'] === $name) {
                    return $this->read($skill['path']);
                }
            }
        }
        foreach ($this->registry->skills($reason) as $skill) {
            if ($skill->name === $name && ($this->root === '' || !SkillCatalog::projectPath($skill->path, $this->root, $this->home))) {
                return $this->read($skill->path);
            }
        }

        throw new PhycodeException("no skill named {$name}" . ($reason === null ? '' : " ({$reason})"));
    }

    public static function checkName(string $name): void
    {
        if (!self::validName($name)) {
            throw new PhycodeException('a skill name cannot be empty or contain a slash, a backslash, a dot or a NUL byte');
        }
    }

    public static function validName(string $name): bool
    {
        return $name !== '' && strpbrk($name, "/\\.\0") === false;
    }

    public static function unloadedLine(string $name): string
    {
        return "[skill {$name} unloaded to save context; load it again with skill(name) if needed]";
    }

    private function read(string $path): string
    {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new PhycodeException("cannot read the skill file {$path}");
        }
        $size = (int) filesize($path);
        $text = (string) fread($handle, $this->maxBytes);
        fclose($handle);

        if ($size <= $this->maxBytes) {
            return $text;
        }
        $kept = $this->maxBytes - self::NOTICE_BYTES;

        return mb_scrub(substr($text, 0, $kept), 'UTF-8') . "\n[... truncated, " . ($size - $kept) . ' bytes omitted]';
    }
}
