<?php

declare(strict_types=1);

namespace Phycode\Cli\Commands;

interface CommandProvider
{
    public function commands(): array;

    public function handle(string $name, string $args, CommandContext $ctx): void;
}
