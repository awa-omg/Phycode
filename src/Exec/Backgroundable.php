<?php

declare(strict_types=1);

namespace Phycode\Exec;

interface Backgroundable
{
    public function start(array $argv, string $cwd, string $logFile, int $lifetime, array $env = []): RunningProcess;
}
