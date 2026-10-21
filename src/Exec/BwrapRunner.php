<?php

declare(strict_types=1);

namespace Phycode\Exec;

use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;

final class BwrapRunner implements CommandRunner, Backgroundable
{
    private const NETWORKS = ['none', 'bridge'];
    private const PROTECTED_FOLDERS = ['.git', '.phycode', '.claude'];
    private const SECRET_PATHS = [
        '.ssh', '.aws', '.gnupg', '.kube', '.docker', '.azure', '.password-store', '.config/gcloud', '.config/gh', '.config/op', '.config/phycode',
        '.netrc', '.npmrc', '.pypirc', '.git-credentials', '.pgpass', '.Xauthority', '.config/hub', '.terraform.d/credentials.tfrc.json',
    ];
    private const PROXY_PORT = 3128;
    private const PROXY_FOLDER = '/tmp/phycode-proxy';

    private readonly string $binary;
    private readonly string $root;
    private readonly DomainAllowlist $allow;
    private ?ProxyProcess $proxy = null;
    private ?string $emptyFile = null;

    public function __construct(
        private readonly ProcessRunner $host,
        private readonly string $network,
        private readonly array $allowDomains,
        string $root,
        ?string $binary = null,
        private readonly bool $proxyMayReachThisMachine = false,
    ) {
        $found = $binary ?? ProcessRunner::which('bwrap');
        if ($found === null || !is_executable($found)) {
            throw new PhycodeException("sandbox 'bwrap' needs bubblewrap: install the package bubblewrap (apt install bubblewrap, dnf install bubblewrap)");
        }
        if (!in_array($network, self::NETWORKS, true)) {
            throw new PhycodeException("sandbox network must be 'none' or 'bridge', not '{$network}'");
        }
        $this->allow = new DomainAllowlist($allowDomains);
        if (!$this->allow->isEmpty()) {
            if ($network === 'bridge') {
                throw new PhycodeException('sandbox.allow_domains and sandbox.network bridge contradict each other: bridge is the whole network, and the list narrows it; use network none with the list');
            }
            if (ProcessRunner::which('socat') === null) {
                throw new PhycodeException('sandbox.allow_domains needs socat (apt install socat): it carries the proxy into the sandbox');
            }
        }
        $real = realpath($root);
        if ($real === false || !is_dir($real) || $real === '/') {
            throw new PhycodeException("sandbox folder does not exist or is /: {$root}");
        }
        $this->binary = $found;
        $this->root = $real;
    }

    public function run(array $argv, string $cwd, int $timeout, int $maxBytes, ?Cancellation $cancel = null, array $env = []): ProcessResult
    {
        $placeholders = $this->createMissingProtectedFolders();
        try {
            return $this->host->run($this->command($argv, $cwd, $env), $this->root, $timeout, $maxBytes, $cancel);
        } finally {
            foreach ($placeholders as $path) {
                @rmdir($path);
            }
        }
    }

    public function start(array $argv, string $cwd, string $logFile, int $lifetime, array $env = []): RunningProcess
    {
        $placeholders = $this->createMissingProtectedFolders();
        try {
            $process = $this->host->start($this->command($argv, $cwd, $env), $this->root, $logFile, $lifetime);
        } catch (\Throwable $error) {
            foreach ($placeholders as $path) {
                @rmdir($path);
            }

            throw $error;
        }
        $process->onClose(function () use ($placeholders): void {
            foreach ($placeholders as $path) {
                @rmdir($path);
            }
        });

        return $process;
    }

    public static function available(): bool
    {
        return PHP_OS_FAMILY === 'Linux' && ProcessRunner::which('bwrap') !== null;
    }

    public function proxyLog(): string
    {
        return $this->proxy === null ? '' : (string) @file_get_contents($this->proxy->log());
    }

    public function command(array $argv, string $cwd, array $env = []): array
    {
        if ($argv === []) {
            throw new PhycodeException('empty command');
        }
        $real = realpath($cwd);
        if ($real === false || !is_dir($real) || ($real !== $this->root && !str_starts_with($real, $this->root . '/'))) {
            throw new PhycodeException("command folder is outside the project: {$cwd}");
        }
        $filtered = !$this->allow->isEmpty();
        $proxy = $filtered ? ($this->proxy ??= new ProxyProcess($this->allowDomains, $this->proxyMayReachThisMachine)) : null;

        $arguments = [
            $this->binary,
            '--die-with-parent', '--new-session',
            '--unshare-user-try', '--unshare-pid', '--unshare-ipc', '--unshare-uts', '--unshare-cgroup-try',
            ...($this->network === 'none' || $filtered ? ['--unshare-net'] : []),
            '--cap-drop', 'ALL',
            '--ro-bind', '/', '/',
            '--dev', '/dev', '--proc', '/proc', '--tmpfs', '/tmp', '--tmpfs', '/run',
            '--bind', $this->root, $this->root,
            ...$this->hiddenPaths(),
            ...$this->protectedMounts(),
            ...($proxy === null ? [] : ['--ro-bind', $proxy->folder(), self::PROXY_FOLDER]),
            '--chdir', $real,
            '--clearenv',
        ];
        $variables = [
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => '/tmp', 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8', 'TERM' => 'dumb', 'NO_COLOR' => '1',
        ];
        foreach ($env as $name => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', (string) $name) !== 1 || str_contains($value, "\0")) {
                throw new PhycodeException("invalid environment variable for the sandbox: {$name}");
            }
            $variables[(string) $name] = $value;
        }
        if ($proxy !== null) {
            $address = 'http://127.0.0.1:' . self::PROXY_PORT;
            $variables += ['HTTP_PROXY' => $address, 'HTTPS_PROXY' => $address, 'http_proxy' => $address, 'https_proxy' => $address, 'NO_PROXY' => 'localhost,127.0.0.1', 'no_proxy' => 'localhost,127.0.0.1'];
        }
        foreach ($variables as $name => $value) {
            array_push($arguments, '--setenv', (string) $name, $value);
        }
        $arguments[] = '--';

        if ($proxy === null) {
            return [...$arguments, ...$argv];
        }

        $script = 'socat TCP-LISTEN:' . self::PROXY_PORT . ',bind=127.0.0.1,fork,reuseaddr UNIX-CONNECT:' . self::PROXY_FOLDER . '/proxy.sock & '
            . 'i=0; until socat -u /dev/null TCP:127.0.0.1:' . self::PROXY_PORT . ' 2>/dev/null || [ "$i" -ge 60 ]; do i=$((i+1)); sleep 0.05; done; exec "$@"';

        return [...$arguments, 'sh', '-c', $script, 'sh', ...$argv];
    }

    private function hiddenPaths(): array
    {
        $arguments = [];
        $home = getenv('HOME');
        if (is_string($home) && $home !== '' && $home !== '/' && is_dir($home)) {
            foreach (self::SECRET_PATHS as $path) {
                $full = rtrim($home, '/') . '/' . $path;
                if (is_dir($full) && !is_link($full)) {
                    array_push($arguments, '--tmpfs', $full);
                } elseif (is_file($full)) {
                    array_push($arguments, '--ro-bind', $this->emptyFile(), $full);
                }
            }
        }

        return $arguments;
    }

    private function emptyFile(): string
    {
        if ($this->emptyFile === null) {
            $folder = rtrim(sys_get_temp_dir(), '/') . '/phycode-bwrap-' . bin2hex(random_bytes(6));
            $file = $folder . '/empty';
            if (!mkdir($folder, 0700) || file_put_contents($file, '') === false) {
                throw new PhycodeException('cannot make an empty file for the sandbox under ' . dirname($folder));
            }
            $this->emptyFile = $file;
        }

        return $this->emptyFile;
    }

    public function __destruct()
    {
        if ($this->emptyFile !== null) {
            @unlink($this->emptyFile);
            @rmdir(dirname($this->emptyFile));
        }
    }

    private function protectedMounts(): array
    {
        $arguments = [];
        foreach (self::PROTECTED_FOLDERS as $folder) {
            if (file_exists($this->root . '/' . $folder)) {
                array_push($arguments, '--ro-bind', $this->root . '/' . $folder, $this->root . '/' . $folder);
            }
        }

        return $arguments;
    }

    private function createMissingProtectedFolders(): array
    {
        $created = [];
        foreach (self::PROTECTED_FOLDERS as $folder) {
            $path = $this->root . '/' . $folder;
            if (is_link($path)) {
                throw new PhycodeException("{$folder} is a symbolic link, so the sandbox will not run");
            }
            if (!file_exists($path)) {
                if (!mkdir($path, 0700)) {
                    throw new PhycodeException("cannot prepare {$folder}/ for the sandbox");
                }
                $created[] = $path;
            }
        }

        return $created;
    }
}
