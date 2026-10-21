<?php

declare(strict_types=1);

namespace Phycode\Exec;

final class RunningProcess
{
    private const TERM = 15;
    private const KILL = 9;
    private const TERM_GRACE_SECONDS = 3.0;

    private ?int $exitCode = null;
    private ?int $signal = null;
    private bool $closed = false;
    private ?\Closure $onClose = null;

    public function __construct(private $process, public readonly int $pid)
    {
    }

    public function onClose(\Closure $hook): void
    {
        $this->onClose = $hook;
    }

    public function running(): bool
    {
        if ($this->closed) {
            return false;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            return true;
        }
        $this->exitCode = $status['exitcode'];
        $this->signal = $status['signaled'] ? $status['termsig'] : null;
        $this->close();

        return false;
    }

    public function exitCode(): ?int
    {
        return $this->running() || $this->signal !== null ? null : $this->exitCode;
    }

    public function signal(): ?int
    {
        $this->running();

        return $this->signal;
    }

    public function stop(): void
    {
        if ($this->closed) {
            return;
        }
        @posix_kill(-$this->pid, self::TERM);
        $deadline = microtime(true) + self::TERM_GRACE_SECONDS;
        while ($this->running() && microtime(true) < $deadline) {
            usleep(50000);
        }
        if (!$this->closed) {
            @posix_kill(-$this->pid, self::KILL);
            $status = proc_get_status($this->process);
            $this->exitCode = -1;
            $this->signal = self::KILL;
            if ($status['running']) {
                proc_terminate($this->process, self::KILL);
            }
            $this->close();
        }
    }

    private function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        @posix_kill(-$this->pid, self::KILL);
        proc_close($this->process);
        if ($this->onClose !== null) {
            ($this->onClose)();
        }
    }
}
