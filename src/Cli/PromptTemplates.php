<?php

declare(strict_types=1);

namespace Phycode\Cli;

use Phycode\Security\PathException;
use Phycode\Support\Files;

final class PromptTemplates
{
    private const ARGUMENTS = '$ARGUMENTS';
    private const NAME = '/^[A-Za-z0-9-]+$/D';

    private array $texts = [];

    private array $warnings = [];

    public function __construct(string $root, array $taken)
    {
        try {
            $folder = Files::runtimeFolder($root, 'prompts');
        } catch (PathException) {
            return;
        }
        if (!is_dir($folder)) {
            return;
        }
        foreach (scandir($folder) ?: [] as $file) {
            $path = $folder . '/' . $file;
            if (!str_ends_with($file, '.md') || is_dir($path)) {
                continue;
            }
            $name = substr($file, 0, -3);
            $reason = self::leftOutBecause($name, $path, $taken);
            if ($reason !== null) {
                $this->warnings[] = "the prompt template {$file} is not used: {$reason}";
            } else {
                $this->texts[$name] = trim((string) file_get_contents($path));
            }
        }
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function prompt(string $line): ?string
    {
        foreach ($this->texts as $name => $text) {
            $word = '/' . $name;
            if ($line === $word) {
                return self::fill($text, '');
            }
            if (str_starts_with($line, $word . ' ')) {
                return self::fill($text, trim(substr($line, strlen($word) + 1)));
            }
        }

        return null;
    }

    public function commands(): array
    {
        $commands = [];
        foreach (array_keys($this->texts) as $name) {
            $commands[] = ['name' => "/{$name} [ARGUMENTS]", 'description' => "prompt template from .phycode/prompts/{$name}.md"];
        }

        return $commands;
    }

    private static function leftOutBecause(string $name, string $path, array $taken): ?string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return 'its name may only have letters, digits and hyphens';
        }
        if (in_array('/' . $name, $taken, true)) {
            return "/{$name} is already a command";
        }
        if (is_link($path)) {
            return 'it is a symbolic link';
        }

        return trim((string) file_get_contents($path)) === '' ? 'it is empty' : null;
    }

    private static function fill(string $text, string $arguments): string
    {
        if (str_contains($text, self::ARGUMENTS)) {
            return str_replace(self::ARGUMENTS, $arguments, $text);
        }

        return $arguments === '' ? $text : "{$text}\n\n{$arguments}";
    }
}
