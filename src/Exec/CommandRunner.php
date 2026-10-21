<?php

declare(strict_types=1);

namespace Phycode\Exec;

use Phycode\Support\Cancellation;

interface CommandRunner
{
    public function run(array $argv, string $cwd, int $timeout, int $maxBytes, ?Cancellation $cancel = null, array $env = []): ProcessResult;
}
