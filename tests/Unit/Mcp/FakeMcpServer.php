<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Mcp;

use Phycode\Mcp\McpClient;
use Phycode\Tests\Support\Kit;

final class FakeMcpServer
{
    public static function argv(string $scenario = ''): array
    {
        $argv = [PHP_BINARY, dirname(__DIR__, 2) . '/Support/fake-mcp.php'];
        if ($scenario !== '') {
            $argv[] = dirname(__DIR__, 2) . "/Fixtures/mcp/{$scenario}.json";
        }

        return $argv;
    }

    public static function client(
        string $scenario = '',
        string $name = 'echo',
        float $timeout = 5.0,
        float $shutdownSeconds = 0.5,
        array $env = [],
        string $clientVersion = '0.1.0',
    ): McpClient {
        return new McpClient($name, self::argv($scenario), Kit::folder('mcp-cwd'), $env, $clientVersion, $timeout, $shutdownSeconds);
    }

    public static function logFile(): string
    {
        return Kit::folder('mcp-log') . '/server.log';
    }

    public static function pid(string $log): int
    {
        $first = json_decode(explode("\n", (string) file_get_contents($log))[0], true, 512, JSON_THROW_ON_ERROR);

        return (int) $first['pid'];
    }

    public static function messages(string $log): array
    {
        $messages = [];
        foreach (explode("\n", (string) file_get_contents($log)) as $line) {
            $message = $line === '' ? null : json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($message) && !array_key_exists('pid', $message)) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public static function rawLines(string $log): array
    {
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log)), static fn (string $line): bool => $line !== ''));

        return array_slice($lines, 1);
    }

    public static function methods(array $messages): array
    {
        return array_values(array_filter(array_column($messages, 'method'), static fn (mixed $method): bool => is_string($method)));
    }

    public static function alive(int $pid): bool
    {
        return posix_kill($pid, 0);
    }
}
