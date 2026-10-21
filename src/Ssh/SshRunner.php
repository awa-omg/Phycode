<?php

declare(strict_types=1);

namespace Phycode\Ssh;

use Phycode\Exec\Backgroundable;
use Phycode\Exec\CommandRunner;
use Phycode\Exec\ProcessResult;
use Phycode\Exec\ProcessRunner;
use Phycode\Exec\RunningProcess;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;

final class SshRunner implements CommandRunner, Backgroundable
{
    private const CONNECT_SECONDS = 15;
    private const KILL_AFTER = 5;
    private const MAX_COMMAND_BYTES = 65536;

    private bool $used = false;

    public function __construct(
        private readonly SshHost $host,
        private readonly ProcessRunner $local,
        private readonly string $controlDirectory,
        private readonly ?string $directory = null,
        private readonly string $ssh = 'ssh',
    ) {
    }

    public function host(): SshHost
    {
        return $this->host;
    }

    public function inDirectory(string $directory): self
    {
        $copy = new self($this->host, $this->local, $this->controlDirectory, $directory, $this->ssh);
        $copy->used = &$this->used;

        return $copy;
    }

    public function run(array $argv, string $cwd, int $timeout, int $maxBytes, ?Cancellation $cancel = null, array $env = []): ProcessResult
    {
        $timeout = max(1, $timeout);
        $this->used = true;

        return $this->local->run(
            [...$this->options(false), '--', $this->host->destination, self::remoteCommand($argv, $this->directory ?? $this->host->cwd, $timeout, false)],
            is_dir($cwd) ? $cwd : sys_get_temp_dir(),
            $timeout + self::CONNECT_SECONDS + 10,
            $maxBytes,
            $cancel,
            $this->environment($env),
        );
    }

    public function start(array $argv, string $cwd, string $logFile, int $lifetime, array $env = []): RunningProcess
    {
        $this->used = true;

        return $this->local->start(
            [...$this->options(true), '--', $this->host->destination, self::remoteCommand($argv, $this->directory ?? $this->host->cwd, max(1, $lifetime), true)],
            is_dir($cwd) ? $cwd : sys_get_temp_dir(),
            $logFile,
            $lifetime + self::CONNECT_SECONDS,
            $this->environment($env),
        );
    }

    public function copyCommand(bool $upload, string $localPath, string $remotePath): array
    {
        $remote = self::copyDestination($this->host->destination) . ':' . $remotePath;
        $options = $this->options(false);
        $scp = [$this->scpBinary(), '-q'];
        for ($i = 1; $i < count($options); $i++) {
            if ($options[$i] === '-p') {
                $scp[] = '-P';
                $scp[] = $options[++$i];
            } elseif ($options[$i] === '-T' || $options[$i] === '-tt') {
                continue;
            } else {
                $scp[] = $options[$i];
            }
        }

        return [...$scp, '--', ...($upload ? [$localPath, $remote] : [$remote, $localPath])];
    }

    public function copy(bool $upload, string $localPath, string $remotePath, int $timeout, int $maxBytes, ?Cancellation $cancel = null): ProcessResult
    {
        $this->used = true;

        return $this->local->run(
            $this->copyCommand($upload, $localPath, $remotePath),
            sys_get_temp_dir(),
            max(1, $timeout) + self::CONNECT_SECONDS,
            $maxBytes,
            $cancel,
            $this->environment([]),
        );
    }

    public function close(): void
    {
        if (!$this->used) {
            return;
        }
        $this->used = false;
        $this->local->run([...$this->options(false), '-O', 'exit', '--', $this->host->destination], sys_get_temp_dir(), 5, 4096, null, $this->environment([]));
    }

    public function options(bool $terminal): array
    {
        $options = [
            $this->ssh,
            $terminal ? '-tt' : '-T',
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=' . ($this->host->hostKey === 'strict' ? 'yes' : 'accept-new'),
            '-o', 'ConnectTimeout=' . self::CONNECT_SECONDS,
            '-o', 'ServerAliveInterval=15',
            '-o', 'ServerAliveCountMax=3',
            '-o', 'LogLevel=ERROR',
            '-o', 'ControlMaster=auto',
            '-o', 'ControlPersist=60',
            '-o', 'ControlPath=' . $this->controlDirectory . '/%C',
            '-o', 'ForwardAgent=no',
            '-o', 'ForwardX11=no',
            '-o', 'ClearAllForwardings=yes',
            '-o', 'PermitLocalCommand=no',
        ];
        if ($this->host->configFile !== null) {
            array_splice($options, 2, 0, ['-F', $this->host->configFile]);
        }
        if ($this->host->port !== null) {
            $options[] = '-p';
            $options[] = (string) $this->host->port;
        }
        if ($this->host->identityFile !== null) {
            array_push($options, '-i', $this->host->identityFile, '-o', 'IdentitiesOnly=yes');
        }
        if ($this->host->knownHosts !== null) {
            array_push($options, '-o', 'UserKnownHostsFile=' . $this->host->knownHosts);
        }

        return $options;
    }

    public static function remoteCommand(array $argv, ?string $directory, int $timeout, bool $terminal = false): string
    {
        $script = self::script($argv, $directory, $timeout, $terminal);
        if (strlen($script) > self::MAX_COMMAND_BYTES) {
            throw new PhycodeException('the command is longer than ' . intdiv(self::MAX_COMMAND_BYTES, 1024) . ' KB; put it in a file and copy it to the host');
        }

        return 'echo ' . base64_encode($script) . '|base64 -d|sh';
    }

    public static function script(array $argv, ?string $directory, int $timeout, bool $terminal = false): string
    {
        if ($argv === [] || $argv[0] === '') {
            throw new PhycodeException('empty command');
        }
        foreach ($argv as $word) {
            if (str_contains($word, "\0")) {
                throw new PhycodeException('a command cannot hold a NUL byte');
            }
        }
        $lines = [];
        if ($directory !== null) {
            $go = $directory === '~' ? '"$HOME"' : (str_starts_with($directory, '~/') ? '"$HOME"' . self::quote(substr($directory, 1)) : self::quote($directory));
            $lines[] = "cd {$go} 2>/dev/null || { echo " . self::quote("phycode: cannot go to {$directory} on the host") . ' >&2; exit 125; }';
        }
        $lines[] = 'export TERM=dumb NO_COLOR=1 PAGER=cat GIT_PAGER=cat';
        $program = self::quote($argv[0]);
        if ($argv[0] === 'bash') {
            $lines[] = 'P=bash; command -v bash >/dev/null 2>&1 || P=sh';
            $program = '"$P"';
        }
        $lines[] = 'T=; command -v timeout >/dev/null 2>&1 && T=' . self::quote('timeout ' . ($terminal ? '--foreground ' : '') . '--signal=TERM --kill-after=' . self::KILL_AFTER . ' ' . $timeout);
        $lines[] = 'exec $T ' . $program . ($argv === [$argv[0]] ? '' : ' ' . implode(' ', array_map([self::class, 'quote'], array_slice($argv, 1))));

        return implode("\n", $lines) . "\n";
    }

    public static function quote(string $word): string
    {
        return "'" . str_replace("'", "'\\''", $word) . "'";
    }

    private static function copyDestination(string $destination): string
    {
        $at = strrpos($destination, '@');
        $user = $at === false ? '' : substr($destination, 0, $at + 1);
        $host = $at === false ? $destination : substr($destination, $at + 1);

        return $user . (str_contains($host, ':') && !str_starts_with($host, '[') ? "[{$host}]" : $host);
    }

    private function scpBinary(): string
    {
        return str_ends_with($this->ssh, 'ssh') ? substr($this->ssh, 0, -3) . 'scp' : 'scp';
    }

    private function environment(array $extra): array
    {
        $environment = [];
        foreach (['SSH_AUTH_SOCK'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return array_merge($environment, $extra);
    }
}
