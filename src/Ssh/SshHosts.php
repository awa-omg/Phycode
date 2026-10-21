<?php

declare(strict_types=1);

namespace Phycode\Ssh;

use Phycode\Exec\ProcessRunner;
use Phycode\Support\PhycodeException;

final class SshHosts
{
    private const MAX_FOLDER_BYTES = 60;

    private array $runners = [];
    private ?string $folder = null;

    public function __construct(
        private readonly array $hosts,
        private readonly ProcessRunner $local,
        private readonly ?string $controlFolder = null,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function isEmpty(): bool
    {
        return $this->hosts === [];
    }

    public function names(): array
    {
        return array_keys($this->hosts);
    }

    public function has(string $name): bool
    {
        return isset($this->hosts[$name]);
    }

    public function host(string $name): SshHost
    {
        return $this->hosts[$name] ?? throw new PhycodeException("no ssh host named '{$name}'. Hosts: " . ($this->hosts === [] ? 'none' : implode(', ', array_keys($this->hosts))));
    }

    public function runner(string $name): SshRunner
    {
        $host = $this->host($name);
        if (isset($this->runners[$name])) {
            return $this->runners[$name];
        }
        $ssh = ProcessRunner::which('ssh') ?? throw new PhycodeException('the ssh client is not installed');

        return $this->runners[$name] = new SshRunner($host, $this->local, $this->folder(), null, $ssh);
    }

    public function close(): void
    {
        foreach ($this->runners as $runner) {
            try {
                $runner->close();
            } catch (\Throwable) {
            }
        }
        $this->runners = [];
    }

    private function folder(): string
    {
        if ($this->folder !== null) {
            return $this->folder;
        }
        $uid = getmyuid();
        $candidates = [];
        if ($this->controlFolder !== null) {
            $candidates[] = $this->controlFolder;
        } else {
            $runtime = getenv('XDG_RUNTIME_DIR');
            if (is_string($runtime) && $runtime !== '' && is_dir($runtime)) {
                $candidates[] = rtrim($runtime, '/') . '/phycode-ssh';
            }
            $candidates[] = '/tmp/phycode-ssh-' . $uid;
        }
        foreach ($candidates as $candidate) {
            if (strlen($candidate) > self::MAX_FOLDER_BYTES) {
                continue;
            }
            if (!is_dir($candidate) && !@mkdir($candidate, 0700, true) && !is_dir($candidate)) {
                continue;
            }
            if (is_link($candidate) || fileowner($candidate) !== $uid || (fileperms($candidate) & 0077) !== 0) {
                throw new PhycodeException("{$candidate} is not private to you (not yours, a link, or open to others); remove it so phycode can make it");
            }

            return $this->folder = $candidate;
        }

        throw new PhycodeException('no folder short enough for the sockets of the ssh connections; set XDG_RUNTIME_DIR to a short path');
    }
}
