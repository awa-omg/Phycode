<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Lsp;

use Phycode\Lsp\LspClient;
use Phycode\Lsp\LspTimeouts;
use Phycode\Tests\Support\Kit;

final class FakeLspServer
{
    public static function command(array $scenario = []): array
    {
        return [PHP_BINARY, dirname(__DIR__, 2) . '/Support/fake-lsp.php', json_encode($scenario, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
    }

    public static function logFile(): string
    {
        return Kit::folder('lsp-log') . '/server.log';
    }

    public static function timeouts(): LspTimeouts
    {
        return new LspTimeouts(initializeSeconds: 5.0, requestSeconds: 2.0, diagnosticsSeconds: 2.0, diagnosticsQuietSeconds: 0.3, warmupSeconds: 0.0, shutdownSeconds: 1.0);
    }

    public static function client(array $scenario = [], ?string $root = null, array $initializationOptions = [], ?LspTimeouts $timeouts = null): LspClient
    {
        return new LspClient('fake', self::command($scenario), $root ?? Kit::folder('lsp-root'), $initializationOptions, $timeouts ?? self::timeouts(), '9.9.9');
    }

    public static function messages(string $log): array
    {
        $messages = [];
        foreach (explode("\n", is_file($log) ? (string) file_get_contents($log) : '') as $line) {
            $message = $line === '' ? null : json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($message) && !array_key_exists('pid', $message)) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public static function methods(string $log): array
    {
        return array_values(array_filter(array_map(static fn (array $message): ?string => $message['method'] ?? null, self::messages($log))));
    }

    public static function pids(string $log): array
    {
        $pids = [];
        foreach (explode("\n", is_file($log) ? (string) file_get_contents($log) : '') as $line) {
            $entry = $line === '' ? null : json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($entry) && array_key_exists('pid', $entry)) {
                $pids[] = $entry['pid'];
            }
        }

        return $pids;
    }

    public static function running(int $pid): bool
    {
        return posix_kill($pid, 0);
    }

    public static function messagesFor(string $log, string $method): array
    {
        return array_values(array_filter(self::messages($log), static fn (array $message): bool => ($message['method'] ?? null) === $method));
    }
}
