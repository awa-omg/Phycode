<?php

declare(strict_types=1);

namespace Phycode\Ssh;

use Phycode\Config\SshHostWriter;
use Phycode\Exec\ProcessRunner;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class SshCli
{
    private const USAGE = <<<'TEXT'
        usage:
          phycode ssh [list]                       the hosts, and whether their key is trusted
          phycode ssh add NAME DESTINATION [--port N] [--cwd DIR] [--identity FILE] [--confirm always|rules]
                                           [--host-key strict|accept-new] [--known-hosts FILE] [--config-file FILE]
          phycode ssh remove NAME
          phycode ssh trust NAME [--yes]           show the host key and, on a yes, add it to known_hosts
          phycode ssh test NAME                    run a short command on the host
        TEXT;

    private const OPTIONS = [
        '--port' => 'port',
        '--cwd' => 'cwd',
        '--identity' => 'identity_file',
        '--confirm' => 'confirm',
        '--host-key' => 'host_key',
        '--known-hosts' => 'known_hosts',
        '--config-file' => 'config_file',
    ];

    public static function run(array $args, string $configFile, $out, $err, $in = null, ?bool $interactive = null, ?ProcessRunner $local = null): int
    {
        $local ??= new ProcessRunner();
        $command = array_shift($args) ?? 'list';
        try {
            return match ($command) {
                'list' => self::list($args, $configFile, $out, $local),
                'add' => self::add($args, $configFile, $out),
                'remove' => self::remove($args, $configFile, $out),
                'trust' => self::trust($args, $configFile, $out, $in ?? STDIN, $interactive ?? (defined('STDIN') && posix_isatty(STDIN)), $local),
                'test' => self::test($args, $configFile, $out, $err, $local),
                default => throw new PhycodeException("unknown ssh command '{$command}'\n" . self::USAGE),
            };
        } catch (PhycodeException $error) {
            fwrite($err, 'phycode: ' . $error->getMessage() . PHP_EOL);

            return 1;
        }
    }

    private static function list(array $args, string $configFile, $out, ProcessRunner $local): int
    {
        if ($args !== []) {
            throw new PhycodeException(self::USAGE);
        }
        $hosts = SshHostWriter::read($configFile);
        if ($hosts === []) {
            fwrite($out, "No ssh hosts. Add one: phycode ssh add NAME user@host --cwd /path\n");

            return 0;
        }
        $keys = new HostKeys($local);
        $rows = [['NAME', 'DESTINATION', 'PORT', 'CWD', 'CONFIRM', 'HOST KEY']];
        foreach ($hosts as $host) {
            try {
                $state = $host->hostKey === 'accept-new' ? 'accept-new' : ($keys->isKnown($host) ? 'trusted' : 'not trusted: phycode ssh trust ' . $host->name);
            } catch (PhycodeException $error) {
                $state = $error->getMessage();
            }
            $rows[] = [$host->name, $host->destination, (string) ($host->port ?? 22), $host->cwd ?? '-', $host->confirm, $state];
        }
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

        return 0;
    }

    private static function add(array $args, string $configFile, $out): int
    {
        $settings = [];
        $words = [];
        while ($args !== []) {
            $arg = array_shift($args);
            if (isset(self::OPTIONS[$arg])) {
                $value = array_shift($args);
                if ($value === null) {
                    throw new PhycodeException("{$arg} needs a value");
                }
                $key = self::OPTIONS[$arg];
                $settings[$key] = $key === 'port' ? (ctype_digit($value) ? (int) $value : $value) : $value;
            } elseif (str_starts_with($arg, '--')) {
                throw new PhycodeException("unknown option {$arg}\n" . self::USAGE);
            } else {
                $words[] = $arg;
            }
        }
        if (count($words) !== 2) {
            throw new PhycodeException("give a name and a destination\n" . self::USAGE);
        }
        [$name, $destination] = $words;
        try {
            $host = SshHost::fromArray($name, ['destination' => $destination] + $settings);
        } catch (\Phycode\Config\ConfigException $error) {
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
        SshHostWriter::add($configFile, $host);
        fwrite($out, "Added ssh host {$name}. Its key must be trusted before the model can use it: phycode ssh trust {$name}\n");
        if ($host->confirm === 'always') {
            fwrite($out, "Every command on {$name} will ask for your approval, in every mode. Use --confirm rules to follow the permission mode and rules instead.\n");
        }

        return 0;
    }

    private static function remove(array $args, string $configFile, $out): int
    {
        if (count($args) !== 1) {
            throw new PhycodeException(self::USAGE);
        }
        SshHostWriter::remove($configFile, $args[0]);
        fwrite($out, "Removed ssh host {$args[0]}. Its key stays in known_hosts.\n");

        return 0;
    }

    private static function trust(array $args, string $configFile, $out, $in, bool $interactive, ProcessRunner $local): int
    {
        $yes = in_array('--yes', $args, true);
        $args = array_values(array_diff($args, ['--yes']));
        if (count($args) !== 1) {
            throw new PhycodeException(self::USAGE);
        }
        $host = self::host($configFile, $args[0]);
        $keys = new HostKeys($local);
        $file = $keys->file($host);
        if ($keys->isKnown($host)) {
            fwrite($out, "{$host->name}: the key of " . HostKeys::entryName($host) . " is in {$file} already.\n");

            return 0;
        }
        $found = $keys->scan($host);
        fwrite($out, "{$host->name} ({$host->destination}) answered with these keys:\n");
        foreach ($found['fingerprints'] as $line) {
            fwrite($out, "  {$line}\n");
        }
        fwrite($out, "Compare the fingerprint with the one your provider shows (or run ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub on the host).\n");
        if (!$yes) {
            if (!$interactive) {
                throw new PhycodeException('there is no terminal to ask on; check the fingerprint and run again with --yes');
            }
            fwrite($out, "Add them to {$file}? [y/N] ");
            $answer = strtolower(trim((string) fgets($in)));
            if ($answer !== 'y' && $answer !== 'yes') {
                fwrite($out, "Nothing was added.\n");

                return 1;
            }
        }
        $keys->add($host, $found['lines']);
        fwrite($out, "Added to {$file}.\n");

        return 0;
    }

    private static function test(array $args, string $configFile, $out, $err, ProcessRunner $local): int
    {
        if (count($args) !== 1) {
            throw new PhycodeException(self::USAGE);
        }
        $host = self::host($configFile, $args[0]);
        $hosts = new SshHosts([$host->name => $host], $local);
        $started = microtime(true);
        try {
            $result = $hosts->runner($host->name)->run(['bash', '-c', 'echo "$(id -un)@$(hostname) $(uname -sr)"; pwd'], sys_get_temp_dir(), 30, 4096);
        } finally {
            $hosts->close();
        }
        if (!$result->succeeded()) {
            fwrite($err, "{$host->name}: failed with exit code {$result->exitCode}\n" . trim($result->stderr) . "\n");
            if ($result->exitCode === 255) {
                fwrite($err, "ssh itself failed. Is the key trusted (phycode ssh trust {$host->name}), loaded in ssh-agent or set with --identity, and the address right?\n");
            }

            return 1;
        }
        fwrite($out, "{$host->name}: ok in " . round(microtime(true) - $started, 1) . "s\n" . $result->stdout);

        return 0;
    }

    private static function host(string $configFile, string $name): SshHost
    {
        $hosts = SshHostWriter::read($configFile);

        return $hosts[$name] ?? throw new PhycodeException("no ssh host named '{$name}'. Hosts: " . ($hosts === [] ? 'none' : implode(', ', array_keys($hosts))));
    }
}
