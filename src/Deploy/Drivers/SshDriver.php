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
use Phycode\Ssh\SshHost;

final class SshDriver implements DeployDriver
{
    private const EXCLUDE = '~^[A-Za-z0-9._*?][A-Za-z0-9._*?/-]{0,99}$~D';
    private const DEFAULT_EXCLUDE = ['.git', '.phycode', 'node_modules', '.env', '.env.*'];

    public function keys(): array
    {
        return ['host', 'path', 'sync', 'exclude', 'commands', 'compose'];
    }

    public function options(array $raw, string $label): array
    {
        $compose = $raw['compose'] ?? null;
        if ($compose !== null) {
            Check::flag($compose, "{$label}.compose");
        }
        $commands = [];
        $given = $raw['commands'] ?? null;
        if ($given !== null) {
            if (!is_array($given) || ($given !== [] && array_is_list($given))) {
                throw new ConfigException("{$label}.commands must be an object with an action as each key");
            }
            foreach ($given as $action => $lines) {
                if (!in_array((string) $action, self::ACTIONS, true)) {
                    throw new ConfigException("{$label}.commands.{$action} is not an action: " . implode(', ', self::ACTIONS));
                }
                if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > 8) {
                    throw new ConfigException("{$label}.commands.{$action} must be a list of 1 to 8 shell lines");
                }
                foreach ($lines as $at => $line) {
                    if (!is_string($line) || trim($line) === '' || strlen($line) > 2000 || str_contains($line, "\0")) {
                        throw new ConfigException("{$label}.commands.{$action}[{$at}] must be a shell line of at most 2000 bytes");
                    }
                    $commands[$action][] = $line;
                }
            }
        }
        $path = $raw['path'] ?? null;
        if (!is_string($path)) {
            throw new ConfigException("{$label}.path must be the folder on the host that the project goes to");
        }

        return [
            'host' => Check::word($raw['host'] ?? null, "{$label}.host", '/^[a-z0-9][a-z0-9_-]{0,31}$/D'),
            'path' => SshHost::remotePath($path, "{$label}.path"),
            'sync' => Check::choice($raw['sync'] ?? 'tar', "{$label}.sync", ['tar', 'none']),
            'exclude' => self::exclude($raw['exclude'] ?? null, "{$label}.exclude"),
            'commands' => $commands,
            'compose' => $compose,
        ];
    }

    private static function exclude(mixed $value, string $label): array
    {
        $patterns = Check::words($value, $label, 32, self::EXCLUDE);
        foreach ($patterns as $at => $pattern) {
            if (in_array('..', explode('/', $pattern), true)) {
                throw new ConfigException("{$label}[{$at}] must stay inside the project, without '..'");
            }
        }

        return $patterns;
    }

    public function plan(DeployTarget $target, string $action, DeployContext $context): DeployPlan
    {
        $o = $target->options;
        if ($context->ssh === null || !$context->ssh->has($o['host'])) {
            throw new DeployUnsupported("host '{$o['host']}' is not in the ssh section of the config");
        }
        $path = $o['path'];
        $commands = $o['commands'][$action] ?? $this->defaults($o, $action);
        if ($commands === null) {
            throw new DeployUnsupported("target {$target->name} has no commands.{$action}" . ($action === 'apply' ? '' : '; add it, or set compose: true for a compose project'));
        }
        if ($context->version === null && array_filter($commands, static fn (string $line): bool => str_contains($line, '{version}')) !== []) {
            throw new DeployUnsupported("the {$action} command of {$target->name} needs a version");
        }

        $steps = [];
        $cleanup = [];
        $notes = [];
        if ($action === 'apply' && $o['sync'] === 'tar') {
            $archive = sys_get_temp_dir() . "/phycode-deploy-{$context->token}.tgz";
            $remote = "/tmp/phycode-deploy-{$context->token}.tgz";
            $exclude = [];
            foreach ([...self::DEFAULT_EXCLUDE, ...$o['exclude']] as $pattern) {
                $exclude[] = "--exclude={$pattern}";
            }
            $steps[] = new DeployStep('pack the project', ['tar', '-czf', $archive, ...$exclude, '-C', $context->directory, '.']);
            $steps[] = new DeployStep('send it to the host', [$archive, $remote], DeployStep::COPY, $o['host']);
            $steps[] = new DeployStep('unpack it on the host', [
                'sh', '-c',
                'p=$1; case "$p" in "~") p=$HOME;; "~/"*) p=$HOME/${p#"~/"};; esac; mkdir -p "$p" && tar -xzf "$2" -C "$p" && rm -f "$2"',
                'sh', $path, $remote,
            ], host: $o['host']);
            $cleanup[] = $archive;
            $notes[] = 'the files of the folder go up except ' . implode(', ', [...self::DEFAULT_EXCLUDE, ...$o['exclude']]) . '; files the project dropped stay on the host';
        }
        foreach ($commands as $at => $line) {
            $line = str_replace(['{lines}', '{version}'], [(string) $context->lines, $context->version ?? ''], $line);
            $steps[] = new DeployStep("{$action} " . ($at + 1) . ' on the host', ['bash', '-c', $line], host: $o['host'], directory: $path, bounded: $action === 'logs', timeout: $action === 'logs' ? 30 : null);
        }

        return new DeployPlan($target->name, $action, $steps, $notes, $cleanup);
    }

    private function defaults(array $o, string $action): ?array
    {
        if (!($o['compose'] ?? false)) {
            return null;
        }

        return match ($action) {
            'apply' => ['docker compose up -d --build --wait --remove-orphans'],
            'status' => ['docker compose ps'],
            'logs' => ['docker compose logs --tail {lines} --no-color'],
            default => null,
        };
    }
}
