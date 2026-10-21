<?php

declare(strict_types=1);

namespace Phycode\Cli\Commands;

use Phycode\Config\Config;
use Phycode\Support\PhycodeException;
use Phycode\Ui\Ui;

final class CommandContext
{
    private const LEVELS = ['info', 'warn', 'error'];

    public function __construct(
        public readonly Ui $ui,
        public readonly string $cwd,
        public readonly Config $config,
        private readonly \Closure $turn,
        private readonly \Closure $redact,
    ) {
    }

    public function runTurn(string $prompt): void
    {
        ($this->turn)($prompt);
    }

    public function panel(string $title, string $text): void
    {
        $this->ui->panel($title, ($this->redact)($text));
    }

    public function listPanel(string $title, array $items, string $hint = ''): void
    {
        $this->ui->listPanel($title, $this->redacted($items), ($this->redact)($hint));
    }

    public function notice(string $level, string $message): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new PhycodeException("a notice level is info, warn or error, not {$level}");
        }
        $this->ui->notice($level, ($this->redact)($message));
    }

    private function redacted(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $value[$key] = ($this->redact)($item);
            } elseif (is_array($item)) {
                $value[$key] = $this->redacted($item);
            }
        }

        return $value;
    }
}
