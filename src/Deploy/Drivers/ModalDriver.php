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

final class ModalDriver implements DeployDriver
{
    public function keys(): array
    {
        return ['command', 'app', 'module', 'modal_env', 'name', 'tag', 'strategy'];
    }

    public function options(array $raw, string $label): array
    {
        $module = $raw['module'] ?? null;
        if ($module !== null) {
            Check::flag($module, "{$label}.module");
        }
        $strategy = $raw['strategy'] ?? null;

        return [
            'command' => Check::command($raw['command'] ?? null, "{$label}.command", 'modal'),
            'app' => Check::word($raw['app'] ?? null, "{$label}.app"),
            'module' => $module,
            'modal_env' => Check::optionalWord($raw['modal_env'] ?? null, "{$label}.modal_env", '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D'),
            'name' => Check::optionalWord($raw['name'] ?? null, "{$label}.name", '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D'),
            'tag' => Check::optionalWord($raw['tag'] ?? null, "{$label}.tag", '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D'),
            'strategy' => $strategy === null ? null : Check::choice($strategy, "{$label}.strategy", ['rolling', 'recreate']),
        ];
    }

    public function plan(DeployTarget $target, string $action, DeployContext $context): DeployPlan
    {
        $o = $target->options;
        $cli = $o['command'];
        $env = $o['modal_env'] === null ? [] : ['-e', $o['modal_env']];
        $strategy = $o['strategy'] === null ? [] : ['--strategy', $o['strategy']];
        $notes = Drivers::notes([Drivers::missing($context->which, $cli)]);
        $needName = static function () use ($o, $target): string {
            return $o['name'] ?? throw new DeployUnsupported("set name in the target {$target->name}: modal app needs the name of the app");
        };

        $steps = match ($action) {
            'apply' => [new DeployStep('deploy the app', [
                ...$cli, 'deploy',
                ...($o['name'] === null ? [] : ['--name', $o['name']]),
                ...$env,
                ...($o['tag'] === null ? [] : ['--tag', $o['tag']]),
                ...$strategy,
                ...(($o['module'] ?? false) ? ['-m'] : []),
                $o['app'],
            ])],
            'status' => [new DeployStep('list the apps', [...$cli, 'app', 'list', ...$env, '--json'])],
            'logs' => [new DeployStep('read the log of the app', [...$cli, 'app', 'logs', $needName(), '--tail', (string) $context->lines, ...$env], bounded: true, timeout: 30)],
            'rollback' => [new DeployStep('redeploy the previous version', [...$cli, 'app', 'rollback', $needName(), ...($context->version === null ? [] : [$context->version]), ...$strategy, ...$env])],
            'dry_run' => throw new DeployUnsupported('modal has no dry run: plan shows the command that apply runs'),
            default => throw new DeployUnsupported("a modal target has no action '{$action}'"),
        };

        return new DeployPlan($target->name, $action, $steps, $notes);
    }
}
