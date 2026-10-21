<?php

declare(strict_types=1);

namespace Phycode\Deploy;

use Phycode\Config\ConfigException;

interface DeployDriver
{
    public const ACTIONS = ['dry_run', 'apply', 'status', 'logs', 'rollback'];

    public function keys(): array;

    public function options(array $raw, string $label): array;

    public function plan(DeployTarget $target, string $action, DeployContext $context): DeployPlan;
}
