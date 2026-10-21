<?php

declare(strict_types=1);

namespace Phycode\Skills;

use Phycode\Agent\Frontmatter;
use Phycode\Gentle\RegistryRules;
use Phycode\Support\PhycodeException;

final class SkillRules
{
    public const SKILL_FILE = 'SKILL.md';
    public const FOLDERS = ['references', 'templates', 'scripts', 'assets'];
    public const SKILL_MAX_BYTES = 16384;
    public const FILE_MAX_BYTES = 1048576;
    public const DESCRIPTION_MAX = 1024;

    private const NAME = '/\A[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?\z/';
    private const PATH_MAX = 512;

    public static function checkName(string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1 || str_contains($name, '--')) {
            throw new SkillException('a skill name is 1 to 64 characters: lowercase letters, digits and single hyphens, not at the start or the end, and no double hyphen');
        }
        if (RegistryRules::excluded($name)) {
            throw new SkillException("{$name} is a reserved name: gentle-ai's registry leaves out skills with that name");
        }
    }

    public static function checkDescription(string $description): void
    {
        $length = mb_strlen($description, 'UTF-8');
        if ($length < 1 || $length > self::DESCRIPTION_MAX || preg_match('/[\x00-\x1F\x7F]/', $description) === 1) {
            throw new SkillException('the description must be one line of 1 to 1024 characters');
        }
    }

    public static function checkSkillFile(string $text, string $name): void
    {
        if (strlen($text) > self::SKILL_MAX_BYTES) {
            throw new SkillException('SKILL.md is larger than 16 KB: keep the instructions short and put long material in references/');
        }
        if (!str_starts_with($text, "---\n") && !str_starts_with($text, "---\r\n")) {
            throw new SkillException('SKILL.md must start with its frontmatter: a first line with ---');
        }
        try {
            [$fields, $body] = Frontmatter::split($text, self::SKILL_FILE);
        } catch (PhycodeException $error) {
            throw new SkillException($error->getMessage());
        }
        if (($fields['name'] ?? null) !== $name) {
            throw new SkillException("the frontmatter name must be {$name}, the name of the skill folder");
        }
        $description = $fields['description'] ?? null;
        if (!is_string($description)) {
            throw new SkillException('the frontmatter needs a description, as text');
        }
        self::checkDescription(trim($description));
        if (trim($body) === '') {
            throw new SkillException('the body of SKILL.md is empty: write the instructions after the frontmatter');
        }
    }

    public static function checkFilePath(string $path): void
    {
        $parts = explode('/', $path);
        $valid = $path !== ''
            && strlen($path) <= self::PATH_MAX
            && count($parts) >= 2
            && in_array($parts[0], self::FOLDERS, true)
            && !str_contains($path, '\\');
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/[\x00-\x1F\x7F]/', $part) === 1) {
                $valid = false;
            }
        }
        if (!$valid) {
            throw new SkillException('file_path must be a file under references/, templates/, scripts/ or assets/, such as references/api.md, with no "..", no absolute path and no empty part');
        }
    }

    public static function checkFileSize(string $content): void
    {
        if (strlen($content) > self::FILE_MAX_BYTES) {
            throw new SkillException('the file is larger than 1 MB: split it into several files');
        }
    }
}
