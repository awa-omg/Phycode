<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

final class FakeRuntime
{
    private const LOG_CALL = 'for arg in "$@"; do printf \'%s\n\' "$arg"; done >> "${0%/*}/calls.log"';

    private function __construct(private readonly string $folder)
    {
    }

    public static function install(): self
    {
        $fake = new self(Kit::folder('fake-runtime'));
        $fake->behave('');
        putenv('PATH=' . $fake->folder . PATH_SEPARATOR . (string) getenv('PATH'));

        return $fake;
    }

    public function behave(string $shell): void
    {
        foreach (['podman', 'docker'] as $name) {
            $path = $this->folder . '/' . $name;
            file_put_contents($path, "#!/bin/sh\n" . self::LOG_CALL . "\n{$shell}\necho fake-ok\n");
            chmod($path, 0700);
        }
    }

    public function log(): array
    {
        return $this->lines('calls.log');
    }

    public function lines(string $file): array
    {
        $path = $this->folder . '/' . $file;
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $lines = explode("\n", $content);
        array_pop($lines);

        return $lines;
    }

    public function clear(): void
    {
        $path = $this->folder . '/calls.log';
        if (is_file($path)) {
            unlink($path);
        }
    }
}
