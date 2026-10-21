<?php

declare(strict_types=1);

namespace Phycode\Exec;

use Phycode\Support\Entry;
use Phycode\Support\PhycodeException;

final class ProxyProcess
{
    private $process;
    private readonly string $folder;

    public function __construct(private readonly array $allow, private readonly bool $allowPrivate = false)
    {
        $base = rtrim(sys_get_temp_dir(), '/');
        $folder = $base . '/phycode-proxy-' . bin2hex(random_bytes(6));
        if (!mkdir($folder, 0700) || strlen($folder . '/proxy.sock') > 100) {
            throw new PhycodeException('cannot make a folder for the sandbox proxy under ' . $base);
        }
        $this->folder = $folder;
        $arguments = [...Entry::command(), 'proxy-run', '--socket', $this->socket(), '--log', $this->log(), '--allow', implode(',', $allow)];
        if ($allowPrivate) {
            $arguments[] = '--allow-private';
        }
        $process = @proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $folder . '/error.log', 'w']], $pipes, $folder, ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')]);
        if (!is_resource($process)) {
            $this->remove();
            throw new PhycodeException('cannot start the sandbox proxy');
        }
        $this->process = $process;
        for ($waited = 0; $waited < 100 && !file_exists($this->socket()); $waited++) {
            usleep(30000);
        }
        if (!file_exists($this->socket())) {
            $why = trim((string) @file_get_contents($folder . '/error.log'));
            $this->stop();
            throw new PhycodeException('the sandbox proxy did not start' . ($why === '' ? '' : ": {$why}"));
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function folder(): string
    {
        return $this->folder;
    }

    public function socket(): string
    {
        return $this->folder . '/proxy.sock';
    }

    public function log(): string
    {
        return $this->folder . '/proxy.log';
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        $this->remove();
    }

    private function remove(): void
    {
        foreach (['/proxy.sock', '/proxy.log', '/error.log'] as $file) {
            @unlink($this->folder . $file);
        }
        @rmdir($this->folder);
    }
}
