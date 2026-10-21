<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\Commands\CommandContext;
use Phycode\Cli\Commands\CommandProvider;

final class FakeCommands implements CommandProvider
{
    public array $calls = [];

    public function __construct(private readonly array $list, private readonly ?\Closure $action = null)
    {
    }

    public function commands(): array
    {
        return $this->list;
    }

    public function handle(string $name, string $args, CommandContext $ctx): void
    {
        $this->calls[] = ['name' => $name, 'args' => $args];
        if ($this->action !== null) {
            ($this->action)($name, $args, $ctx);
        }
    }
}
