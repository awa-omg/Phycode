<?php

declare(strict_types=1);

namespace Phycode\Exec;

use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;

final class ContainerRunner implements CommandRunner, Backgroundable
{
    private const RUNTIMES = ['podman', 'docker'];
    private const NETWORKS = ['none', 'bridge'];
    private const WORKSPACE = '/workspace';
    private const PROTECTED_FOLDERS = ['.git', '.phycode', '.claude'];
    private const CLIENT_VARIABLES = ['XDG_RUNTIME_DIR', 'DOCKER_HOST', 'CONTAINER_HOST'];

    private readonly string $binary;
    private readonly string $root;

    public function __construct(
        private readonly ProcessRunner $host,
        private readonly string $runtime,
        private readonly string $image,
        private readonly string $network,
        string $root,
    ) {
        if (!in_array($runtime, self::RUNTIMES, true)) {
            throw new PhycodeException("sandbox runtime must be 'podman' or 'docker', not '{$runtime}'");
        }
        $binary = ProcessRunner::which($runtime);
        if ($binary === null) {
            throw new PhycodeException("sandbox runtime '{$runtime}' is not installed");
        }
        if ($image === '' || $image[0] === '-' || preg_match('/\s/', $image) === 1) {
            throw new PhycodeException("invalid sandbox image name '{$image}'");
        }
        if (!in_array($network, self::NETWORKS, true)) {
            throw new PhycodeException("sandbox network must be 'none' or 'bridge', not '{$network}'");
        }
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new PhycodeException("sandbox folder does not exist: {$root}");
        }
        if ($real === '/') {
            throw new PhycodeException('refusing to use / as the sandbox folder');
        }
        if (strpbrk($real, ',:') !== false) {
            throw new PhycodeException("sandbox folder path cannot contain ',' or ':': {$real}");
        }

        $this->binary = $binary;
        $this->root = $real;
    }

    public function run(array $argv, string $cwd, int $timeout, int $maxBytes, ?Cancellation $cancel = null, array $env = []): ProcessResult
    {
        if ($argv === []) {
            throw new PhycodeException('empty command');
        }
        $real = realpath($cwd);
        if ($real === false || !is_dir($real)) {
            throw new PhycodeException("command folder does not exist: {$cwd}");
        }
        if ($real !== $this->root && !str_starts_with($real, $this->root . '/')) {
            throw new PhycodeException("command folder is outside the project: {$cwd}");
        }

        $name = 'phycode-' . bin2hex(random_bytes(6));
        $clientEnvironment = self::clientEnvironment();
        $placeholders = $this->createMissingProtectedFolders();
        try {
            $result = $this->host->run(
                $this->containerArgv($name, self::WORKSPACE . substr($real, strlen($this->root)), $argv),
                $this->root,
                $timeout,
                $maxBytes,
                $cancel,
                array_merge($clientEnvironment, $env),
            );
            if ($result->interrupted !== null) {
                $this->host->run([$this->binary, 'rm', '-f', $name], $this->root, 15, 4096, null, $clientEnvironment);
            }
        } finally {
            foreach ($placeholders as $path) {
                @rmdir($path);
            }
        }

        return $result;
    }

    public function start(array $argv, string $cwd, string $logFile, int $lifetime, array $env = []): RunningProcess
    {
        if ($argv === []) {
            throw new PhycodeException('empty command');
        }
        $real = realpath($cwd);
        if ($real === false || !is_dir($real) || ($real !== $this->root && !str_starts_with($real, $this->root . '/'))) {
            throw new PhycodeException("command folder is outside the project: {$cwd}");
        }
        $name = 'phycode-' . bin2hex(random_bytes(6));
        $clientEnvironment = self::clientEnvironment();
        $placeholders = $this->createMissingProtectedFolders();
        try {
            $process = $this->host->start(
                $this->containerArgv($name, self::WORKSPACE . substr($real, strlen($this->root)), $argv),
                $this->root,
                $logFile,
                $lifetime,
                array_merge($clientEnvironment, $env),
            );
        } catch (\Throwable $error) {
            foreach ($placeholders as $path) {
                @rmdir($path);
            }

            throw $error;
        }
        $process->onClose(function () use ($name, $clientEnvironment, $placeholders): void {
            $this->host->run([$this->binary, 'rm', '-f', $name], $this->root, 15, 4096, null, $clientEnvironment);
            foreach ($placeholders as $path) {
                @rmdir($path);
            }
        });

        return $process;
    }

    public static function available(string $runtime): bool
    {
        return self::binary($runtime) !== null;
    }

    public static function imageExists(string $runtime, string $image, ProcessRunner $host): bool
    {
        $binary = self::binary($runtime);
        if ($binary === null) {
            return false;
        }

        return $host->run([$binary, 'image', 'inspect', $image], sys_get_temp_dir(), 15, 4096)->succeeded();
    }

    private static function binary(string $runtime): ?string
    {
        return in_array($runtime, self::RUNTIMES, true) ? ProcessRunner::which($runtime) : null;
    }

    private function createMissingProtectedFolders(): array
    {
        $created = [];
        foreach (self::PROTECTED_FOLDERS as $folder) {
            $path = $this->root . '/' . $folder;
            if (is_link($path)) {
                throw new PhycodeException("{$folder} is a symbolic link, so the sandbox will not run");
            }
            if (file_exists($path)) {
                continue;
            }
            if (!mkdir($path, 0700)) {
                throw new PhycodeException("cannot prepare {$folder}/ for the sandbox");
            }
            $created[] = $path;
        }

        return $created;
    }

    private function containerArgv(string $name, string $workdir, array $argv): array
    {
        $userNamespace = $this->runtime === 'podman' && posix_geteuid() !== 0 ? ['--userns=keep-id'] : [];

        return [
            $this->binary,
            'run',
            ...$userNamespace,
            '--rm',
            '--name',
            $name,
            '--pull=never',
            '--network',
            $this->network,
            '--user',
            posix_getuid() . ':' . posix_getgid(),
            '--cap-drop=ALL',
            '--security-opt=no-new-privileges',
            '--memory=2g',
            '--pids-limit=512',
            '--tmpfs=/tmp:rw,size=512m',
            '--env=HOME=/tmp',
            '--env=LANG=C.UTF-8',
            '--env=LC_ALL=C.UTF-8',
            '--volume=' . $this->root . ':' . self::WORKSPACE,
            '--workdir=' . $workdir,
            ...$this->protectedMounts(),
            $this->image,
            ...$argv,
        ];
    }

    private function protectedMounts(): array
    {
        $mounts = [];
        foreach (self::PROTECTED_FOLDERS as $folder) {
            if (file_exists($this->root . '/' . $folder)) {
                $mounts[] = "--mount=type=bind,source={$this->root}/{$folder},target=" . self::WORKSPACE . "/{$folder},readonly";
            }
        }

        return $mounts;
    }

    private static function clientEnvironment(): array
    {
        $environment = [];
        foreach (self::CLIENT_VARIABLES as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
