<?php

declare(strict_types=1);

namespace Phycode\Deploy\Drivers;

use Phycode\Config\ConfigException;
use Phycode\Deploy\Check;
use Phycode\Deploy\DeployContext;
use Phycode\Deploy\DeployDriver;
use Phycode\Deploy\DeployPlan;
use Phycode\Deploy\DeployStep;
use Phycode\Deploy\DeployTarget;
use Phycode\Deploy\DeployUnsupported;

final class CustomDriver implements DeployDriver
{
    public function keys(): array
    {
        return ['commands'];
    }

    public function options(array $raw, string $label): array
    {
        $commands = $raw['commands'] ?? null;
        if (!is_array($commands) || ($commands !== [] && array_is_list($commands)) || $commands === []) {
            throw new ConfigException("{$label}.commands must be an object with an action as each key");
        }
        $checked = [];
        foreach ($commands as $action => $lines) {
            if (!in_array((string) $action, self::ACTIONS, true)) {
                throw new ConfigException("{$label}.commands.{$action} is not an action: " . implode(', ', self::ACTIONS));
            }
            if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > 8) {
                throw new ConfigException("{$label}.commands.{$action} must be a list of 1 to 8 commands, each a list of words");
            }
            foreach ($lines as $at => $argv) {
                $checked[$action][] = Check::argv($argv, "{$label}.commands.{$action}[{$at}]");
            }
        }

        return ['commands' => $checked];
    }

    public function plan(DeployTarget $target, string $action, DeployContext $context): DeployPlan
    {
        $lines = $target->options['commands'][$action] ?? throw new DeployUnsupported("target {$target->name} has no commands.{$action}");
        $steps = [];
        $notes = [];
        foreach ($lines as $at => $argv) {
            if ($context->version === null && array_filter($argv, static fn (string $word): bool => str_contains($word, '{version}')) !== []) {
                throw new DeployUnsupported("the {$action} command of {$target->name} needs a version");
            }
            $argv = array_map(static fn (string $word): string => str_replace(['{lines}', '{version}'], [(string) $context->lines, $context->version ?? ''], $word), $argv);
            $notes[] = Drivers::missing($context->which, $argv);
            $steps[] = new DeployStep($action . ' ' . ($at + 1), $argv, bounded: $action === 'logs', timeout: $action === 'logs' ? 30 : null);
        }

        return new DeployPlan($target->name, $action, $steps, Drivers::notes(array_unique($notes)));
    }
}
