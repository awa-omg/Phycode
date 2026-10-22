<?php

declare(strict_types=1);

namespace Phycode\Deploy;

use Phycode\Config\ConfigException;
use Phycode\Config\DeployTargetWriter;
use Phycode\Config\SshHostWriter;
use Phycode\Exec\ProcessRunner;
use Phycode\Ssh\SshHosts;
use Phycode\Support\Cancellation;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;

final class DeployCli
{
    private const USAGE = <<<'TEXT'
        usage:
          phycode deploy [list]                          the targets, and whether their secrets have a value
          phycode deploy add NAME KIND [--env ENVIRONMENT] [--dir DIR] [--secret VARIABLE]... [--var NAME=VALUE]...
                                       [--health URL] [--timeout SECONDS] [--confirm always|rules] [--set KEY=VALUE]...
                                       KIND: docker, podman, vercel, cloudflare, modal, ssh or custom; --set gives the
                                       settings of the kind, a JSON value or a word (--set ports='["8080:80"]')
          phycode deploy remove NAME
          phycode deploy secret NAME VARIABLE            store a value in the vault (typed hidden, or read from stdin)
          phycode deploy secrets NAME                    which secrets of the target have a value, and where from
          phycode deploy plan NAME [ACTION]              the commands that an action would run (default: apply)
          phycode deploy NAME ACTION [--version V] [--lines N] [--yes]
                                       ACTION: dry_run, apply, status, logs or rollback; apply, rollback and dry_run ask first
          phycode deploy history [NAME]                  the deploys of this project
        TEXT;

    public static function run(array $args, string $configFile, string $root, $out, $err, $in = null, ?bool $interactive = null, ?\Closure $readSecret = null, ?ProcessRunner $local = null): int
    {
        $local ??= new ProcessRunner();
        $command = array_shift($args) ?? 'list';
        $interactive ??= defined('STDIN') && posix_isatty(STDIN);
        try {
            return match ($command) {
                'list' => self::list($configFile, $out),
                'add' => self::add($args, $configFile, $out),
                'remove' => self::remove($args, $configFile, $out),
                'secret' => self::secret($args, $configFile, $out, $readSecret),
                'secrets' => self::secrets($args, $configFile, $out),
                'plan' => self::plan($args, $configFile, $root, $out, $local),
                'history' => self::history($args, $root, $out),
                default => self::act($command, $args, $configFile, $root, $out, $in ?? STDIN, $interactive, $local),
            };
        } catch (PhycodeException $error) {
            fwrite($err, 'phycode: ' . $error->getMessage() . PHP_EOL);

            return 1;
        }
    }

    private static function list(string $configFile, $out): int
    {
        $targets = DeployTargetWriter::read($configFile);
        if ($targets === []) {
            fwrite($out, "No deploy targets. Add one: phycode deploy add NAME KIND (docker, podman, vercel, cloudflare, modal, ssh or custom)\n");

            return 0;
        }
        $vault = new Vault(dirname($configFile));
        $rows = [['NAME', 'KIND', 'ENVIRONMENT', 'CONFIRM', 'SECRETS']];
        foreach ($targets as $target) {
            $missing = array_filter($target->secrets, static fn (string $variable): bool => self::source($vault, $target, $variable) === null);
            $rows[] = [$target->name, $target->kind, $target->environment, $target->forcesApproval() ? 'always' : 'rules', $target->secrets === [] ? '-' : ($missing === [] ? 'all set' : 'missing: ' . implode(', ', $missing))];
        }
        self::table($rows, $out);

        return 0;
    }

    private static function add(array $args, string $configFile, $out): int
    {
        $raw = [];
        $words = [];
        $options = ['--env' => 'environment', '--dir' => 'dir', '--health' => 'health_url', '--timeout' => 'timeout', '--confirm' => 'confirm'];
        while ($args !== []) {
            $arg = array_shift($args);
            if (isset($options[$arg]) || in_array($arg, ['--secret', '--var', '--set'], true)) {
                $value = array_shift($args);
                if ($value === null) {
                    throw new PhycodeException("{$arg} needs a value");
                }
                if (isset($options[$arg])) {
                    $raw[$options[$arg]] = $arg === '--timeout' ? (ctype_digit($value) ? (int) $value : $value) : $value;
                } elseif ($arg === '--secret') {
                    $raw['secrets'][] = $value;
                } else {
                    $pair = explode('=', $value, 2);
                    if (count($pair) !== 2 || $pair[0] === '') {
                        throw new PhycodeException("{$arg} takes NAME=VALUE");
                    }
                    if ($arg === '--var') {
                        $raw['vars'][$pair[0]] = $pair[1];
                    } else {
                        $decoded = json_decode($pair[1], true);
                        $raw[$pair[0]] = json_last_error() === JSON_ERROR_NONE && $decoded !== null ? $decoded : $pair[1];
                    }
                }
            } elseif (str_starts_with($arg, '--')) {
                throw new PhycodeException("unknown option {$arg}\n" . self::USAGE);
            } else {
                $words[] = $arg;
            }
        }
        if (count($words) !== 2) {
            throw new PhycodeException("give a name and a kind\n" . self::USAGE);
        }
        [$name, $kind] = $words;
        try {
            $target = DeployTarget::fromArray($name, ['kind' => $kind] + $raw);
        } catch (ConfigException $error) {
            throw new PhycodeException($error->getMessage());
        }
        if (!is_file($configFile)) {
            $folder = dirname($configFile);
            if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
                throw new PhycodeException("cannot create {$folder}");
            }
            file_put_contents($configFile, Json::encode(new \stdClass(), true) . "\n");
            chmod($configFile, 0600);
        }
        DeployTargetWriter::add($configFile, $target);
        fwrite($out, "Added deploy target {$name} ({$kind}, {$target->environment}).\n");
        foreach ($target->secrets as $variable) {
            fwrite($out, "Store its {$variable}: phycode deploy secret {$name} {$variable}\n");
        }
        fwrite($out, $target->forcesApproval()
            ? "Every deploy to {$name} will ask for your approval, in every mode" . ($target->production() ? ' (a production target always does).' : '.') . "\n"
            : "Deploys to {$name} follow the permission mode and the rules.\n");
        fwrite($out, "See what apply would run: phycode deploy plan {$name}\n");

        return 0;
    }

    private static function remove(array $args, string $configFile, $out): int
    {
        if (count($args) !== 1) {
            throw new PhycodeException(self::USAGE);
        }
        DeployTargetWriter::remove($configFile, $args[0]);
        $vault = new Vault(dirname($configFile));
        $removed = 0;
        foreach ($vault->deploySecretNames($args[0]) as $variable) {
            $removed += $vault->removeDeploySecret($args[0], $variable) ? 1 : 0;
        }
        fwrite($out, "Removed deploy target {$args[0]}" . ($removed > 0 ? " and {$removed} stored secret" . ($removed > 1 ? 's' : '') : '') . ".\n");

        return 0;
    }

    private static function secret(array $args, string $configFile, $out, ?\Closure $readSecret): int
    {
        if (count($args) !== 2) {
            throw new PhycodeException(self::USAGE);
        }
        [$name, $variable] = $args;
        $target = self::targets($configFile)[$name] ?? throw new PhycodeException("no deploy target named '{$name}'");
        if (!in_array($variable, $target->secrets, true)) {
            throw new PhycodeException("{$variable} is not in the secrets of {$name}" . ($target->secrets === [] ? ' (it has none: add --secret when you add the target)' : ': ' . implode(', ', $target->secrets)));
        }
        $value = $readSecret !== null ? $readSecret("Value of {$variable} for {$name} (typing is hidden): ") : trim((string) stream_get_contents(STDIN));
        (new Vault(dirname($configFile)))->storeDeploySecret($name, $variable, $value);
        fwrite($out, "Stored {$variable} for {$name}, encrypted in the vault.\n");

        return 0;
    }

    private static function secrets(array $args, string $configFile, $out): int
    {
        if (count($args) !== 1) {
            throw new PhycodeException(self::USAGE);
        }
        $target = self::targets($configFile)[$args[0]] ?? throw new PhycodeException("no deploy target named '{$args[0]}'");
        if ($target->secrets === []) {
            fwrite($out, "{$target->name} has no secrets.\n");

            return 0;
        }
        $vault = new Vault(dirname($configFile));
        foreach ($target->secrets as $variable) {
            fwrite($out, "{$variable}: " . (self::source($vault, $target, $variable) ?? "missing (phycode deploy secret {$target->name} {$variable})") . "\n");
        }

        return 0;
    }

    private static function plan(array $args, string $configFile, string $root, $out, ProcessRunner $local): int
    {
        if ($args === [] || count($args) > 2) {
            throw new PhycodeException(self::USAGE);
        }
        $deployments = self::service($configFile, $root, $local);
        $plan = $deployments->plan($args[0], $args[1] ?? 'apply');
        $target = $deployments->target($args[0]);
        fwrite($out, "{$target->name} ({$target->kind}, {$target->environment}): {$plan->action}\n");
        foreach ($plan->commands() as $at => $command) {
            fwrite($out, ($at + 1) . '. ' . $command . "\n");
        }
        foreach ($plan->notes as $note) {
            fwrite($out, "note: {$note}\n");
        }
        $missing = $deployments->missingSecrets($args[0]);
        if ($missing !== []) {
            fwrite($out, 'missing: ' . implode(', ', $missing) . "\n");
        }

        return 0;
    }

    private static function history(array $args, string $root, $out): int
    {
        $entries = (new DeployHistory($root))->entries($args[0] ?? null, 20);
        if ($entries === []) {
            fwrite($out, "No deploys in this project yet.\n");

            return 0;
        }
        foreach ($entries as $entry) {
            fwrite($out, sprintf(
                "%s  %-12s %-9s %-7s %s%s\n",
                $entry['time'] ?? '?',
                (string) ($entry['target'] ?? '?'),
                (string) ($entry['action'] ?? '?'),
                ($entry['ok'] ?? false) ? 'ok' : 'FAILED',
                (string) ($entry['commit'] ?? ''),
                isset($entry['url']) ? '  ' . $entry['url'] : '',
            ));
        }

        return 0;
    }

    private static function act(string $target, array $args, string $configFile, string $root, $out, $in, bool $interactive, ProcessRunner $local): int
    {
        $action = array_shift($args) ?? throw new PhycodeException("give an action after {$target}\n" . self::USAGE);
        $version = null;
        $lines = 100;
        $yes = false;
        while ($args !== []) {
            $arg = array_shift($args);
            if ($arg === '--yes') {
                $yes = true;
            } elseif ($arg === '--version' || $arg === '--lines') {
                $value = array_shift($args) ?? throw new PhycodeException("{$arg} needs a value");
                $arg === '--version' ? $version = $value : $lines = ctype_digit($value) ? (int) $value : throw new PhycodeException('--lines takes a number');
            } else {
                throw new PhycodeException("unknown option {$arg}\n" . self::USAGE);
            }
        }
        if (!in_array($action, DeployDriver::ACTIONS, true)) {
            throw new PhycodeException("unknown action '{$action}'. Actions: " . implode(', ', DeployDriver::ACTIONS));
        }
        $deployments = self::service($configFile, $root, $local);
        $plan = $deployments->plan($target, $action, $version, $lines);
        $definition = $deployments->target($target);
        if (in_array($action, Deployments::MUTATING, true)) {
            fwrite($out, "{$target} ({$definition->kind}, {$definition->environment}): {$action} will run\n");
            foreach ($plan->commands() as $at => $command) {
                fwrite($out, '  ' . ($at + 1) . '. ' . $command . "\n");
            }
            if (!$yes) {
                if (!$interactive) {
                    throw new PhycodeException('there is no terminal to ask on; look at the plan and run again with --yes');
                }
                fwrite($out, 'Run it? [y/N] ');
                $answer = strtolower(trim((string) fgets($in)));
                if ($answer !== 'y' && $answer !== 'yes') {
                    fwrite($out, "Nothing was run.\n");

                    return 1;
                }
            }
        }
        $result = $deployments->run($target, $action, $version, $lines, new Cancellation(), 16384, static function (string $step) use ($out): void {
            fwrite($out, "  {$step}\n");
        });
        fwrite($out, $result->report(!in_array($action, Deployments::MUTATING, true), 12) . "\n");
        if ($interactive && $deployments->offersRollBack($result)) {
            fwrite($out, "Roll back {$target} now? [y/N] ");
            $answer = strtolower(trim((string) fgets($in)));
            if ($answer === 'y' || $answer === 'yes') {
                $back = $deployments->run($target, 'rollback', null, $lines, new Cancellation(), 16384, static function (string $step) use ($out): void {
                    fwrite($out, "  {$step}\n");
                });
                fwrite($out, $back->report(false, 12) . "\n");
            }
        }

        return $result->ok ? 0 : 1;
    }

    private static function service(string $configFile, string $root, ProcessRunner $local): Deployments
    {
        $hosts = SshHostWriter::read($configFile);

        return new Deployments(DeployTargetWriter::read($configFile), new Vault(dirname($configFile)), $local, new SshHosts($hosts, $local), $root);
    }

    private static function targets(string $configFile): array
    {
        return DeployTargetWriter::read($configFile);
    }

    private static function source(Vault $vault, DeployTarget $target, string $variable): ?string
    {
        if ($vault->deploySecret($target->name, $variable) !== null) {
            return 'stored in the vault';
        }
        $env = getenv($variable);

        return is_string($env) && $env !== '' ? 'from the environment of phycode' : null;
    }

    private static function table(array $rows, $out): void
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $at => $cell) {
                $widths[$at] = max($widths[$at] ?? 0, mb_strlen($cell));
            }
        }
        foreach ($rows as $row) {
            $line = '';
            foreach ($row as $at => $cell) {
                $line .= $at === count($row) - 1 ? $cell : str_pad($cell, $widths[$at] + 2);
            }
            fwrite($out, $line . "\n");
        }
    }
}
