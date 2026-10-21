<?php

declare(strict_types=1);

namespace Phycode\Rpc;

use Phycode\Support\Json;

final class JsonLines
{
    private string $buffer = '';
    private bool $ended = false;

    public function __construct(private $in, private $out, private readonly int $maxLine = 0)
    {
    }

    public function send(array $event): void
    {
        fwrite($this->out, Json::encode($event) . "\n");
        fflush($this->out);
    }

    public function next(?float $timeout): ?array
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        while (($line = $this->take()) === null) {
            if ($this->ended) {
                return null;
            }
            $left = $deadline === null ? null : max(0.0, $deadline - microtime(true));
            if ($this->select($left) !== 1) {
                return null;
            }
            $chunk = fread($this->in, 65536);
            if ($chunk === false || ($chunk === '' && feof($this->in))) {
                $this->ended = true;
            } else {
                $this->buffer .= $chunk;
                if ($this->maxLine > 0 && strlen($this->buffer) > $this->maxLine && strpos($this->buffer, "\n") === false) {
                    $this->buffer = '';
                    $this->ended = true;
                }
            }
        }

        return Json::object($line) ?? [];
    }

    public function ended(): bool
    {
        return $this->ended;
    }

    private function take(): ?string
    {
        $end = strpos($this->buffer, "\n");
        if ($end === false) {
            if (!$this->ended || $this->buffer === '') {
                return null;
            }
            $end = strlen($this->buffer);
        }
        $line = substr($this->buffer, 0, $end);
        $this->buffer = (string) substr($this->buffer, $end + 1);

        return $line;
    }

    private function select(?float $seconds): int|false
    {
        while (true) {
            $read = [$this->in];
            $write = null;
            $except = null;
            $ready = @stream_select(
                $read,
                $write,
                $except,
                $seconds === null ? null : (int) $seconds,
                $seconds === null ? null : (int) round(fmod($seconds, 1.0) * 1000000),
            );
            if ($ready !== false || $seconds !== null) {
                return $ready;
            }
        }
    }
}
