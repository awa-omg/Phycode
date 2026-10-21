<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Tests\Support\Kit;

final readonly class PhycodeRun
{
    private function __construct(public string $stdout, public string $stderr, public int $status, public string $home)
    {
    }

    public static function run(array $arguments, array $profile, string $stdin = '', array $env = [], array $global = [], ?string $home = null): self
    {
        $home ??= Kit::folder('phycode-home');
        $profiles = ['test' => $profile] + ($global['profiles'] ?? []);
        Kit::write($home . '/config/phycode/config.json', json_encode(['default_profile' => 'test', 'profiles' => $profiles] + $global, JSON_THROW_ON_ERROR));
        Kit::write($home . '/stdin.txt', $stdin);
        $root = dirname(__DIR__, 3);

        $process = proc_open(
            [PHP_BINARY, $root . '/bin/phycode', ...$arguments],
            [0 => ['file', $home . '/stdin.txt', 'r'], 1 => ['file', $home . '/stdout.txt', 'w'], 2 => ['file', $home . '/stderr.txt', 'w']],
            $pipes,
            $root,
            [...['PATH' => (string) getenv('PATH'), 'HOME' => $home, 'XDG_CONFIG_HOME' => $home . '/config', 'XDG_CACHE_HOME' => $home . '/cache'], ...$env],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start phycode');
        }
        $status = proc_close($process);

        return new self((string) file_get_contents($home . '/stdout.txt'), (string) file_get_contents($home . '/stderr.txt'), $status, $home);
    }
}
