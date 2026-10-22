<?php

declare(strict_types=1);

namespace Phycode\Plugin;

use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;

final class PluginCli
{
    private const USAGE = <<<'TEXT'
        usage:
          phycode plugin [list]        the installed plugins and what each brings
          phycode plugin add SOURCE    install a plugin from a folder or a git address (https://, ssh://, file://, git@host:path)
          phycode plugin remove NAME   remove a plugin
        A plugin is a folder with agents/, skills/ or prompts/ in it, and an optional plugin.json with a name, a description and a version.
        TEXT;

    public static function run(array $args, string $configDir, $out, $err): int
    {
        $command = array_shift($args) ?? 'list';
        $plugins = new Plugins($configDir);
        try {
            return match ($command) {
                'list' => self::list($plugins, $out),
                'add' => self::add($args, $plugins, $out),
                'remove' => self::remove($args, $plugins, $out),
                default => throw new PhycodeException("unknown plugin command '{$command}'\n" . self::USAGE),
            };
        } catch (PhycodeException $error) {
            fwrite($err, 'phycode: ' . Redaction::text($error->getMessage()) . PHP_EOL);

            return 1;
        }
    }

    private static function list(Plugins $plugins, $out): int
    {
        $all = $plugins->all();
        if ($all === []) {
            fwrite($out, "No plugins. phycode plugin add ./my-plugin installs one.\n");

            return 0;
        }
        foreach ($all as $plugin) {
            fwrite($out, sprintf("%-24s %-10s %-26s %s\n", $plugin['name'], $plugin['version'] === '' ? '-' : $plugin['version'], implode(', ', $plugin['parts']), $plugin['description']));
        }

        return 0;
    }

    private static function add(array $args, Plugins $plugins, $out): int
    {
        if (count($args) !== 1) {
            throw new PhycodeException("give the folder or the git address of the plugin\n" . self::USAGE);
        }
        $plugin = $plugins->add($args[0]);
        fwrite($out, "installed {$plugin['name']}: " . implode(', ', $plugin['parts']) . "\n");

        return 0;
    }

    private static function remove(array $args, Plugins $plugins, $out): int
    {
        if (count($args) !== 1) {
            throw new PhycodeException("give the name of the plugin\n" . self::USAGE);
        }
        $plugins->remove($args[0]);
        fwrite($out, "removed {$args[0]}\n");

        return 0;
    }
}
