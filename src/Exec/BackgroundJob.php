<?php

declare(strict_types=1);

namespace Phycode\Exec;

final class BackgroundJob
{
    public const MAX_LOG_BYTES = 67108864;

    private int $offset = 0;
    private ?float $endedAt = null;
    private ?string $stoppedBecause = null;

    public function __construct(
        public readonly int $id,
        public readonly string $command,
        private readonly string $logFile,
        private readonly RunningProcess $process,
        private readonly float $startedAt,
        private readonly int $maxLogBytes = self::MAX_LOG_BYTES,
    ) {
    }

    public function running(): bool
    {
        if ($this->process->running()) {
            clearstatcache(true, $this->logFile);
            if (@filesize($this->logFile) > $this->maxLogBytes) {
                $this->stop('its output is larger than ' . max(1, intdiv($this->maxLogBytes, 1048576)) . ' MB');
            }

            return $this->process->running();
        }
        $this->endedAt ??= microtime(true);

        return false;
    }

    public function seconds(): float
    {
        return ($this->endedAt ?? microtime(true)) - $this->startedAt;
    }

    public function line(): string
    {
        return "job {$this->id}: " . $this->state() . ': ' . $this->command;
    }

    public function state(): string
    {
        $seconds = (int) round($this->seconds());
        if ($this->running()) {
            return "running for {$seconds}s";
        }
        if ($this->stoppedBecause !== null) {
            return "stopped: {$this->stoppedBecause} after {$seconds}s";
        }
        $code = $this->process->exitCode();
        $limit = $code === 124 || $this->process->limitPassed() ? ' (its time limit passed)' : '';
        $how = $code !== null ? "exited with code {$code}{$limit}" : 'ended on signal ' . ($this->process->signal() ?? '?') . $limit;

        return $how . " after {$seconds}s";
    }

    public function read(int $maxBytes): array
    {
        $handle = @fopen($this->logFile, 'rb');
        if ($handle === false) {
            return ['', 0];
        }
        $size = (int) fstat($handle)['size'];
        $from = $this->offset;
        $omitted = 0;
        if ($size - $from > $maxBytes) {
            $omitted = $size - $from - $maxBytes;
            $from = $size - $maxBytes;
        }
        fseek($handle, $from);
        $text = $size > $from ? (string) stream_get_contents($handle, $size - $from) : '';
        fclose($handle);
        $this->offset = $size;

        return [mb_scrub($text, 'UTF-8'), $omitted];
    }

    public function stop(?string $because = null): void
    {
        $this->stoppedBecause ??= $because;
        $this->process->stop();
        $this->endedAt ??= microtime(true);
    }

    public function forget(): void
    {
        @unlink($this->logFile);
    }
}
