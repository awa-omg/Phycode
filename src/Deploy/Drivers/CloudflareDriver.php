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

final class CloudflareDriver implements DeployDriver
{
    private const WORD = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D';

    public function keys(): array
    {
        return ['command', 'mode', 'wrangler_env', 'name', 'project_name', 'output_dir', 'branch'];
    }

    public function options(array $raw, string $label): array
    {
        $mode = Check::choice($raw['mode'] ?? 'workers', "{$label}.mode", ['workers', 'pages']);
        $options = [
            'command' => Check::command($raw['command'] ?? null, "{$label}.command", 'wrangler'),
            'mode' => $mode,
            'wrangler_env' => Check::optionalWord($raw['wrangler_env'] ?? null, "{$label}.wrangler_env", self::WORD),
            'name' => Check::optionalWord($raw['name'] ?? null, "{$label}.name", self::WORD),
            'project_name' => Check::optionalWord($raw['project_name'] ?? null, "{$label}.project_name", self::WORD),
            'output_dir' => isset($raw['output_dir']) ? Check::relativePath($raw['output_dir'], "{$label}.output_dir") : null,
            'branch' => Check::optionalWord($raw['branch'] ?? null, "{$label}.branch", '~^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$~D'),
        ];
        if ($mode === 'pages' && ($options['project_name'] === null || $options['output_dir'] === null)) {
            throw new \Phycode\Config\ConfigException("{$label}: a pages target needs project_name and output_dir");
        }

        return $options;
    }

    public function plan(DeployTarget $target, string $action, DeployContext $context): DeployPlan
    {
        $o = $target->options;
        $cli = $o['command'];
        $notes = Drivers::notes([Drivers::missing($context->which, $cli)]);
        $steps = $o['mode'] === 'pages' ? $this->pages($target, $action, $context) : $this->workers($target, $action, $context);

        return new DeployPlan($target->name, $action, $steps, $notes);
    }

    private function workers(DeployTarget $target, string $action, DeployContext $context): array
    {
        $o = $target->options;
        $cli = $o['command'];
        $name = $o['name'] === null ? [] : ['--name', $o['name']];
        $env = $o['wrangler_env'] === null ? [] : ['CLOUDFLARE_ENV' => $o['wrangler_env']];
        $envFlag = $o['wrangler_env'] === null ? [] : ['--env', $o['wrangler_env']];

        return match ($action) {
            'dry_run' => [new DeployStep('bundle without publishing', [...$cli, 'deploy', '--dry-run', ...$envFlag, ...$name])],
            'apply' => [new DeployStep('publish the worker', [...$cli, 'deploy', ...$envFlag, ...$name])],
            'status' => [new DeployStep('show the current deployment', [...$cli, 'deployments', 'status', ...$name], env: $env)],
            'logs' => [new DeployStep('tail the log for a while', [...$cli, 'tail', ...($o['name'] === null ? [] : [$o['name']]), '--format', 'pretty'], env: $env, bounded: true, timeout: 20)],
            'rollback' => [new DeployStep('roll back to the version before the latest', [...$cli, 'rollback', ...($context->version === null ? [] : [$context->version]), '--message', 'rollback by phycode', ...$name], env: $env)],
            default => throw new DeployUnsupported("a cloudflare target has no action '{$action}'"),
        };
    }

    private function pages(DeployTarget $target, string $action, DeployContext $context): array
    {
        $o = $target->options;
        $cli = $o['command'];
        $environment = $target->production() ? 'production' : 'preview';

        return match ($action) {
            'apply' => [new DeployStep('publish the pages project', [...$cli, 'pages', 'deploy', $o['output_dir'], '--project-name', $o['project_name'], ...($o['branch'] === null ? [] : ['--branch', $o['branch']])])],
            'status' => [new DeployStep('list the deployments', [...$cli, 'pages', 'deployment', 'list', '--project-name', $o['project_name'], '--environment', $environment, '--json'])],
            'logs' => [new DeployStep('tail the log for a while', [...$cli, 'pages', 'deployment', 'tail', '--project-name', $o['project_name'], '--environment', $environment, '--format', 'pretty'], bounded: true, timeout: 20)],
            'dry_run' => throw new DeployUnsupported('wrangler has no dry run for a pages project'),
            'rollback' => throw new DeployUnsupported('a pages project is rolled back in the Cloudflare dashboard, by promoting an earlier deployment'),
            default => throw new DeployUnsupported("a cloudflare target has no action '{$action}'"),
        };
    }
}
