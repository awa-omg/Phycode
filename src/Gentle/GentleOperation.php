<?php

declare(strict_types=1);

namespace Phycode\Gentle;

final readonly class GentleOperation
{
    public function __construct(
        public string $title,
        public array $argv,
        public array $env,
        public string $cwd,
        public int $timeout,
        public array $writes,
        public array $notes,
    ) {
    }

    public function details(): string
    {
        $lines = [
            'argv: ' . self::show($this->argv),
            'env:  ' . self::show(self::assignments($this->env)),
            "cwd:  {$this->cwd}",
        ];
        foreach ($this->writes as $write) {
            $lines[] = "writes: {$write}";
        }
        foreach ($this->notes as $note) {
            $lines[] = "note: {$note}";
        }

        return implode("\n", $lines);
    }

    public static function assignments(array $env): array
    {
        return array_map(static fn (string $name, string $value): string => "{$name}={$value}", array_keys($env), array_values($env));
    }

    public static function show(array $words): string
    {
        return implode(' ', array_map(
            static fn (string $word): string => preg_match('~^[A-Za-z0-9_./=:@%+,-]+$~', $word) === 1
                ? $word
                : "'" . str_replace("'", "'\\''", $word) . "'",
            $words,
        ));
    }
}
