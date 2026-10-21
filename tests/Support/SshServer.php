<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

use Phycode\Exec\ProcessRunner;
use Phycode\Ssh\SshHost;
use Phycode\Ssh\SshHosts;

final class SshServer
{
    private $process;

    private function __construct(
        private readonly string $folder,
        public readonly int $port,
        private readonly string $user,
        $process,
    ) {
        $this->process = $process;
    }

    public function __destruct()
    {
        $this->stop();
    }

    public static function start(): ?self
    {
        $sshd = '/usr/sbin/sshd';
        if (!is_executable($sshd) || ProcessRunner::which('ssh-keygen') === null || ProcessRunner::which('ssh') === null || ProcessRunner::which('scp') === null || ProcessRunner::which('timeout') === null) {
            return null;
        }
        $user = posix_getpwuid(posix_geteuid())['name'] ?? null;
        if (!is_string($user)) {
            return null;
        }
        $folder = Kit::folder('sshd');
        chmod($folder, 0700);
        foreach (['host_key', 'client_key'] as $key) {
            exec('ssh-keygen -q -t ed25519 -N "" -f ' . escapeshellarg("{$folder}/{$key}") . ' 2>&1', $output, $code);
            if ($code !== 0) {
                return null;
            }
        }
        copy("{$folder}/client_key.pub", "{$folder}/authorized_keys");

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if ($probe === false) {
            return null;
        }
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);

        file_put_contents("{$folder}/sshd_config", implode("\n", [
            "Port {$port}",
            'ListenAddress 127.0.0.1',
            "HostKey {$folder}/host_key",
            "PidFile {$folder}/sshd.pid",
            "AuthorizedKeysFile {$folder}/authorized_keys",
            'PasswordAuthentication no',
            'KbdInteractiveAuthentication no',
            'PubkeyAuthentication yes',
            'UsePAM no',
            'PermitRootLogin yes',
            'StrictModes no',
            'Subsystem sftp internal-sftp',
            'LogLevel ERROR',
            '',
        ]));
        $hostKey = explode(' ', trim((string) file_get_contents("{$folder}/host_key.pub")));
        file_put_contents("{$folder}/known_hosts", "[127.0.0.1]:{$port} {$hostKey[0]} {$hostKey[1]}\n");
        file_put_contents("{$folder}/empty_known_hosts", '');
        file_put_contents("{$folder}/ssh_config", '');

        $process = proc_open(
            [$sshd, '-D', '-e', '-f', "{$folder}/sshd_config"],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$folder}/sshd.out", 'w'], 2 => ['file', "{$folder}/sshd.log", 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return null;
        }
        $server = new self($folder, $port, $user, $process);
        for ($waited = 0; $waited < 100; $waited++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $message, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return $server;
            }
            usleep(50000);
        }
        $server->stop();

        return null;
    }

    public function host(string $name = 'test', array $settings = []): SshHost
    {
        return SshHost::fromArray($name, array_merge([
            'destination' => "{$this->user}@127.0.0.1",
            'port' => $this->port,
            'identity_file' => "{$this->folder}/client_key",
            'known_hosts' => "{$this->folder}/known_hosts",
            'config_file' => "{$this->folder}/ssh_config",
            'confirm' => 'rules',
        ], $settings));
    }

    public function hosts(array $settings = [], string $name = 'test'): SshHosts
    {
        return new SshHosts([$name => $this->host($name, $settings)], new ProcessRunner(), Kit::folder('sshctl'));
    }

    public function emptyKnownHosts(): string
    {
        return "{$this->folder}/empty_known_hosts";
    }

    public function knownHosts(): string
    {
        return "{$this->folder}/known_hosts";
    }

    public function user(): string
    {
        return $this->user;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 15);
            proc_close($this->process);
        }
    }
}
