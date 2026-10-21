<?php

declare(strict_types=1);

namespace Phycode\Deploy\Drivers;

use Phycode\Deploy\Check;
use Phycode\Deploy\DeployContext;
use Phycode\Deploy\DeployDriver;
use Phycode\Deploy\DeployPlan;
use Phycode\Deploy\DeployStep;
use Phycode\Deploy\DeployTarget;
use Phycode\Deploy\DeployUnsupported;

final class VercelDriver implements DeployDriver
{
    public function keys(): array
    {
        return ['command', 'scope', 'prebuilt'];
    }

    public function options(array $raw, string $label): array
    {
        $prebuilt = $raw['prebuilt'] ?? null;
        if ($prebuilt !== null) {
            Check::flag($prebuilt, "{$label}.prebuilt");
        }

        return [
            'command' => Check::command($raw['command'] ?? null, "{$label}.command", 'vercel'),
            'scope' => Check::optionalWord($raw['scope'] ?? null, "{$label}.scope", '/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D'),
            'prebuilt' => $prebuilt,
        ];
    }

    public function plan(DeployTarget $target, string $action, DeployContext $context): DeployPlan
    {
        $o = $target->options;
        $cli = $o['command'];
        $global = ['--non-interactive', ...($o['scope'] === null ? [] : ['--scope', $o['scope']])];
        $environment = $target->environment;
        $flag = $target->production() ? ['--prod'] : ($environment === 'preview' ? [] : ["--target={$environment}"]);
        $pull = new DeployStep('pull the settings of the project', [...$cli, 'pull', '--yes', "--environment={$environment}", ...$global]);
        $build = new DeployStep('build locally', [...$cli, 'build', ...$flag, ...$global]);
        $notes = Drivers::notes([Drivers::missing($context->which, $cli)]);

        $steps = match ($action) {
            'dry_run' => [$pull, $build],
            'apply' => ($o['prebuilt'] ?? false)
                ? [$pull, $build, new DeployStep('upload the build and deploy', [...$cli, 'deploy', '--prebuilt', ...$flag, '--yes', ...$global])]
                : [new DeployStep('deploy', [...$cli, 'deploy', ...$flag, '--yes', ...$global])],
            'status' => [new DeployStep('list the deployments', [...$cli, 'list', ...$global])],
            'logs' => [new DeployStep('read the runtime logs', [...$cli, 'logs', '--since', '30m', '--limit', (string) $context->lines, '--environment', $environment, ...$global])],
            'rollback' => [new DeployStep('roll back production', [...$cli, 'rollback', ...($context->version === null ? [] : [$context->version]), ...$global])],
            default => throw new DeployUnsupported("a vercel target has no action '{$action}'"),
        };
        if ($action === 'rollback' && !$target->production()) {
            throw new DeployUnsupported('vercel rollback acts on production only; this target is ' . $environment);
        }

        return new DeployPlan($target->name, $action, $steps, $notes);
    }
}
