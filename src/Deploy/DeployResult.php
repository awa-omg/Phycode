<?php

declare(strict_types=1);

namespace Phycode\Deploy;

final readonly class DeployResult
{
    public function __construct(
        public bool $ok,
        public string $target,
        public string $action,
        public array $steps,
        public ?string $url = null,
        public ?string $health = null,
        public array $notes = [],
        public ?string $error = null,
        public float $seconds = 0.0,
    ) {
    }

    public function report(bool $showOutput, int $tail = 6): string
    {
        $lines = ["deploy {$this->target}: {$this->action} " . ($this->ok ? 'ok' : 'FAILED') . ' in ' . round($this->seconds, 1) . 's'];
        if ($this->error !== null) {
            $lines[] = $this->error;
        }
        foreach ($this->steps as $at => $step) {
            $lines[] = ($at + 1) . '. ' . $step['label'] . ': ' . $step['status'] . ' (' . round($step['seconds'], 1) . 's)';
            $output = trim($step['output']);
            if ($output === '' || $step['status'] === 'skipped') {
                continue;
            }
            $keep = ($showOutput || $step['status'] === 'failed') ? 60 : $tail;
            $outputLines = explode("\n", $output);
            if (count($outputLines) > $keep) {
                $lines[] = '   [' . (count($outputLines) - $keep) . ' earlier lines left out]';
                $outputLines = array_slice($outputLines, -$keep);
            }
            foreach ($outputLines as $line) {
                $lines[] = '   ' . $line;
            }
        }
        if ($this->url !== null) {
            $lines[] = "url: {$this->url}";
        }
        if ($this->health !== null) {
            $lines[] = "health: {$this->health}";
        }
        foreach ($this->notes as $note) {
            $lines[] = "note: {$note}";
        }

        return implode("\n", $lines);
    }
}
