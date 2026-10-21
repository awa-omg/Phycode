<?php

declare(strict_types=1);

namespace Phycode\Ssh;

use Phycode\Exec\ProcessRunner;
use Phycode\Support\Files;
use Phycode\Support\PhycodeException;

final class HostKeys
{
    private const TYPES = 'ed25519,ecdsa,rsa';

    public function __construct(private readonly ProcessRunner $local)
    {
    }

    public function file(SshHost $host): string
    {
        $home = getenv('HOME');

        return $host->knownHosts ?? (is_string($home) && $home !== '' ? rtrim($home, '/') . '/.ssh/known_hosts' : throw new PhycodeException('HOME is not set, so there is no ~/.ssh/known_hosts'));
    }

    public static function entryName(SshHost $host): string
    {
        $port = $host->port ?? 22;

        return $port === 22 ? $host->hostname() : "[{$host->hostname()}]:{$port}";
    }

    public function isKnown(SshHost $host): bool
    {
        $file = $this->file($host);
        if (!is_file($file)) {
            return false;
        }
        $ssh = ProcessRunner::which('ssh-keygen') ?? throw new PhycodeException('ssh-keygen is not installed');

        return $this->local->run([$ssh, '-F', self::entryName($host), '-f', $file], sys_get_temp_dir(), 10, 65536)->succeeded();
    }

    public function scan(SshHost $host): array
    {
        $scan = ProcessRunner::which('ssh-keyscan') ?? throw new PhycodeException('ssh-keyscan is not installed');
        $keygen = ProcessRunner::which('ssh-keygen') ?? throw new PhycodeException('ssh-keygen is not installed');
        $argv = [$scan, '-T', '10', '-t', self::TYPES, ...($host->port === null ? [] : ['-p', (string) $host->port]), '--', $host->hostname()];
        $result = $this->local->run($argv, sys_get_temp_dir(), 30, 65536);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $result->stdout)), static fn (string $line): bool => $line !== '' && $line[0] !== '#'));
        if ($lines === []) {
            throw new PhycodeException("{$host->hostname()} gave no host key" . (trim($result->stderr) === '' ? '' : ': ' . trim(explode("\n", trim($result->stderr))[0])));
        }

        $temporary = tempnam(sys_get_temp_dir(), 'phycode-keys-');
        if ($temporary === false) {
            throw new PhycodeException('cannot make a temporary file');
        }
        try {
            file_put_contents($temporary, implode("\n", $lines) . "\n");
            $shown = $this->local->run([$keygen, '-l', '-f', $temporary], sys_get_temp_dir(), 10, 65536);
        } finally {
            @unlink($temporary);
        }
        $fingerprints = array_values(array_filter(array_map('trim', explode("\n", $shown->stdout)), static fn (string $line): bool => $line !== ''));

        return ['lines' => $lines, 'fingerprints' => $fingerprints];
    }

    public function add(SshHost $host, array $lines): string
    {
        $file = $this->file($host);
        $folder = dirname($file);
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new PhycodeException("cannot create {$folder}");
        }
        if (is_link($file)) {
            throw new PhycodeException("{$file} is a symbolic link, so phycode does not write to it");
        }
        $existing = is_file($file) ? (string) file_get_contents($file) : '';
        $separator = $existing === '' || str_ends_with($existing, "\n") ? '' : "\n";
        Files::writeAtomic($file, $existing . $separator . implode("\n", $lines) . "\n", 0600);

        return $file;
    }
}
