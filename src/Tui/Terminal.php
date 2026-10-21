<?php

declare(strict_types=1);

namespace Phycode\Tui;

use Phycode\Support\PhycodeException;

final class Terminal
{
    private ?string $savedSettings = null;
    private bool $open = false;
    private int $rows = 24;
    private int $columns = 80;

    private function __construct()
    {
    }

    public static function open(): self
    {
        if (!posix_isatty(STDIN) || !posix_isatty(STDOUT)) {
            throw new PhycodeException('phycode -t needs an interactive terminal; use phycode chat when there is none');
        }
        $terminal = new self();
        $settings = trim((string) shell_exec('stty -g 2>/dev/null'));
        if ($settings === '') {
            throw new PhycodeException('cannot read the terminal settings with stty');
        }
        $terminal->savedSettings = $settings;

        $signals = function_exists('pcntl_signal') ? '' : '-isig ';
        shell_exec("stty -icanon -echo {$signals}min 1 time 0 2>/dev/null");
        stream_set_blocking(STDIN, false);
        $terminal->open = true;
        register_shutdown_function([$terminal, 'close']);
        $terminal->refreshSize();
        $terminal->write("\e[?1049h\e[?25l\e[2J");

        return $terminal;
    }

    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->write("\e[0m\e[?25h\e[?1049l");
        if ($this->savedSettings !== null) {
            shell_exec('stty ' . escapeshellarg($this->savedSettings) . ' 2>/dev/null');
        }
        stream_set_blocking(STDIN, true);
    }

    public function size(): array
    {
        return [$this->rows, $this->columns];
    }

    public function refreshSize(): void
    {
        $output = trim((string) shell_exec('stty size 2>/dev/null'));
        if (preg_match('/^(\d+) (\d+)$/', $output, $match) === 1) {
            $this->rows = max(6, (int) $match[1]);
            $this->columns = max(20, (int) $match[2]);
        }
    }

    public function write(string $bytes): void
    {
        fwrite(STDOUT, $bytes);
        fflush(STDOUT);
    }

    public function read(float $timeout): string
    {
        $read = [STDIN];
        $write = null;
        $except = null;
        $seconds = (int) $timeout;
        $microseconds = (int) round(($timeout - $seconds) * 1000000);
        if (@stream_select($read, $write, $except, $seconds, $microseconds) !== 1) {
            return '';
        }
        $data = fread(STDIN, 4096);

        return is_string($data) ? $data : '';
    }
}
