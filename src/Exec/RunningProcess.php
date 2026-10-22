<?php

declare(strict_types=1);

namespace Phycode\Exec;

final class RunningProcess
{
    private const TERM = 15;
    private const KILL = 9;
    private const TERM_GRACE_SECONDS = 3.0;
    private const TIMED_OUT = 124;

    private ?int $exitCode = null;
    private ?int $signal = null;
    private bool $closed = false;
    private bool $limitPassed = false;
    private ?\Closure $onClose = null;

    public function __construct(private $process, public readonly int $pid, private ?float $deadline = null, private readonly ?string $mark = null, private readonly ?string $statusFile = null)
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
            if ($this->deadline !== null && microtime(true) >= $this->deadline) {
                $this->deadline = null;
                $this->limitPassed = true;
                $this->stop();

                return false;
            }

            return true;
        }
        $this->exitCode = $status['exitcode'];
        $this->signal = $status['signaled'] ? $status['termsig'] : null;
        $reported = $this->statusFile === null ? '' : trim((string) @file_get_contents($this->statusFile));
        if (ctype_digit($reported)) {
            $this->exitCode = (int) $reported;
            $this->signal = null;
        }
        $this->close();

        return false;
    }

    public function limitPassed(): bool
    {
        return $this->limitPassed;
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
        ProcessTree::signal($this->pid, self::TERM, true, $this->mark, $this->statusFile !== null);
        $deadline = microtime(true) + self::TERM_GRACE_SECONDS;
        while ($this->running() && microtime(true) < $deadline) {
            usleep(50000);
        }
        if (!$this->closed) {
            ProcessTree::signal($this->pid, self::KILL, true, $this->mark, $this->statusFile !== null);
            for ($wait = microtime(true) + 1.0; $this->statusFile !== null && proc_get_status($this->process)['running'] && microtime(true) < $wait; ) {
                usleep(20000);
            }
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
        ProcessTree::signal($this->pid, self::KILL, false, $this->limitPassed || $this->exitCode === self::TIMED_OUT ? $this->mark : null);
        proc_close($this->process);
        if ($this->statusFile !== null) {
            @unlink($this->statusFile);
        }
        if ($this->onClose !== null) {
            ($this->onClose)();
        }
    }
}
