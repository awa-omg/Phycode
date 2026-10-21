<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

final class FakeCli
{
    private string $path;

    private array $watched;

    private function __construct(private readonly string $folder, string $path, array $watched)
    {
        $this->path = $path;
        $this->watched = $watched;
    }

    public static function install(array $programs, array $watched = []): self
    {
        $folder = Kit::folder('fake-cli');
        $log = $folder . '/calls.log';
        foreach ($programs as $name => $body) {
            $lines = ['#!/bin/sh', "{ printf 'CALL\\t%s' " . escapeshellarg($name) . '; for a in "$@"; do printf \'\\t%s\' "$a"; done; printf \'\\n\'; printf \'CWD\\t%s\\n\' "$PWD";'];
            foreach ($watched as $variable) {
                $lines[] = "printf 'ENV\\t{$variable}\\t%s\\n' \"\${{$variable}-<unset>}\";";
            }
            $lines[] = '} >> ' . escapeshellarg($log);
            $lines[] = $body;
            file_put_contents("{$folder}/{$name}", implode("\n", $lines) . "\n");
            chmod("{$folder}/{$name}", 0700);
        }
        $old = (string) getenv('PATH');
        putenv('PATH=' . $folder . PATH_SEPARATOR . $old);

        return new self($folder, $old, $watched);
    }

    public function end(): void
    {
        putenv('PATH=' . $this->path);
    }

    public function calls(string $name): array
    {
        $calls = [];
        foreach ($this->lines() as $line) {
            $fields = explode("\t", $line);
            if ($fields[0] === 'CALL' && $fields[1] === $name) {
                $calls[] = array_slice($fields, 2);
            }
        }

        return $calls;
    }

    public function values(string $variable): array
    {
        $values = [];
        foreach ($this->lines() as $line) {
            $fields = explode("\t", $line, 3);
            if ($fields[0] === 'ENV' && $fields[1] === $variable) {
                $values[] = $fields[2];
            }
        }

        return $values;
    }

    public function directories(): array
    {
        $directories = [];
        foreach ($this->lines() as $line) {
            if (str_starts_with($line, "CWD\t")) {
                $directories[] = substr($line, 4);
            }
        }

        return $directories;
    }

    private function lines(): array
    {
        $file = $this->folder . '/calls.log';

        return is_file($file) ? explode("\n", rtrim((string) file_get_contents($file))) : [];
    }
}
