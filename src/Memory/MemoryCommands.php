<?php

declare(strict_types=1);

namespace Phycode\Memory;

use Phycode\Cli\Commands\CommandContext;
use Phycode\Cli\Commands\CommandProvider;

final class MemoryCommands implements CommandProvider
{
    private const TITLES = ['project' => 'This project', 'user' => 'Every project'];

    public function __construct(private readonly MemoryStore $store)
    {
    }

    public function commands(): array
    {
        return [['name' => '/memory [forget SCOPE/NAME]', 'description' => 'show the notes saved for the next sessions, or forget one']];
    }

    public function handle(string $name, string $args, CommandContext $ctx): void
    {
        if ($args === '') {
            $ctx->panel('Memory', $this->listing());

            return;
        }
        if (preg_match('~^forget\s+(project|user)/([a-z0-9][a-z0-9-]{0,59})$~D', $args, $match) !== 1) {
            $ctx->notice('error', 'usage: /memory, or /memory forget project/NAME or /memory forget user/NAME');

            return;
        }
        $ctx->notice('info', $this->store->forget($match[1], $match[2]) ? "forgot {$match[1]}/{$match[2]}" : "no note named {$match[1]}/{$match[2]}");
    }

    private function listing(): string
    {
        $blocks = [];
        foreach (self::TITLES as $scope => $title) {
            $lines = array_map(static fn (array $note): string => "  {$note['name']}: {$note['description']}", $this->store->all($scope));
            if ($lines !== []) {
                $blocks[] = $title . ":\n" . implode("\n", $lines);
            }
        }

        return $blocks === []
            ? 'No notes yet. The model saves them with the memory_save tool; memory.autonomy in the config says whether it asks you first.'
            : implode("\n\n", $blocks) . "\n\n/memory forget SCOPE/NAME removes one.";
    }
}
