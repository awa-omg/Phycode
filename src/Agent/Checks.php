<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Text;
use Phycode\Tools\ArgvRunner;

final class Checks
{
    public const KINDS = ['check', 'format'];
    public const TIMEOUT_SECONDS = 30;
    public const MAX_TIMEOUT_SECONDS = 300;
    private const OUTPUT_BYTES = 3000;

    public function __construct(private readonly array $checks = [])
    {
    }

    public function any(): bool
    {
        return $this->checks !== [];
    }

    public function after(string $relative, string $root): array
    {
        $file = $root . '/' . $relative;
        if (!is_file($file)) {
            return [];
        }
        $matching = array_values(array_filter($this->checks, static fn (array $check): bool => Hooks::matches($check['match'], $relative) || Hooks::matches($check['match'], basename($relative))));
        usort($matching, static fn (array $a, array $b): int => ($a['kind'] === 'format' ? 0 : 1) <=> ($b['kind'] === 'format' ? 0 : 1));

        $notes = [];
        foreach ($matching as $check) {
            $notes[] = $this->one($check, $relative, $file, $root);
        }

        return $notes;
    }

    private function one(array $check, string $relative, string $file, string $root): string
    {
        $name = $check['name'];
        $kind = $check['kind'] === 'format' ? 'format' : 'check';
        $path = str_starts_with($relative, '-') ? './' . $relative : $relative;
        try {
            $argv = array_map(static fn (string $word): string => str_replace('{file}', $path, $word), Hooks::split($check['command']));
            $before = $kind === 'format' ? md5_file($file) : null;
            $result = ArgvRunner::run($argv, $root, $check['timeout'], 65536);
        } catch (PhycodeException $error) {
            return "{$kind} {$name} could not run: " . $error->getMessage();
        }
        if ($result->interrupted === 'timeout') {
            return "{$kind} {$name} timed out after {$check['timeout']} seconds on {$relative}";
        }
        if ($result->exitCode !== 0) {
            $output = Redaction::text(trim($result->stdout . ($result->stdout !== '' && $result->stderr !== '' ? "\n" : '') . $result->stderr));

            return "{$kind} {$name} failed on {$relative} (exit {$result->exitCode})" . ($output === '' ? '' : ":\n" . Text::headTail($output, self::OUTPUT_BYTES));
        }
        if ($kind === 'format') {
            return md5_file($file) === $before
                ? "format {$name}: {$relative} was already formatted"
                : "format {$name} rewrote {$relative}; read it again before the next edit, because the text changed";
        }

        return "check {$name}: ok";
    }
}
