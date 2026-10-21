<?php

declare(strict_types=1);

namespace Phycode\Cli\Commands;

use Phycode\Support\PhycodeException;

final class CommandRegistry
{
    private const NAME = '/^\/[a-z][a-z0-9-]*(?: \S.*)?$/D';

    private array $providers = [];

    private array $entries = [];

    public function add(CommandProvider $provider): void
    {
        $entries = [];
        foreach ($provider->commands() as $command) {
            $name = $command['name'] ?? null;
            $description = $command['description'] ?? null;
            if (!is_string($name) || preg_match(self::NAME, $name) !== 1) {
                throw new PhycodeException('a command name starts with a slash and a letter, such as /review or /review [PATH]');
            }
            if (!is_string($description) || trim($description) === '') {
                throw new PhycodeException("the command {$name} needs a description");
            }
            $word = self::wordOf($name);
            if (isset($this->providers[$word]) || isset($entries[$word])) {
                throw new PhycodeException("the command {$word} is provided twice");
            }
            $entries[$word] = ['name' => $name, 'description' => trim($description), 'arguments' => str_contains($name, ' ')];
        }
        foreach ($entries as $word => $entry) {
            $this->providers[$word] = $provider;
            $this->entries[$word] = $entry;
        }
    }

    public function commands(): array
    {
        $commands = [];
        foreach ($this->entries as $entry) {
            $commands[] = ['name' => $entry['name'], 'description' => $entry['description']];
        }

        return $commands;
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    public function match(string $line): ?array
    {
        $line = trim($line);
        foreach ($this->entries as $word => $entry) {
            if ($line === $word) {
                return [$word, ''];
            }
            if ($entry['arguments'] && str_starts_with($line, $word . ' ')) {
                return [$word, trim(substr($line, strlen($word) + 1))];
            }
        }

        return null;
    }

    public function dispatch(string $name, string $args, CommandContext $ctx): void
    {
        if (!$this->has($name)) {
            throw new PhycodeException("no command named {$name}");
        }
        $this->providers[$name]->handle($name, trim($args), $ctx);
    }

    private static function wordOf(string $name): string
    {
        return explode(' ', $name, 2)[0];
    }
}
