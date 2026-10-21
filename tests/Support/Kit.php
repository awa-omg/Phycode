<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

use Phycode\Agent\AgentDefinition;
use Phycode\Agent\RunPolicy;
use Phycode\Config\Limits;
use Phycode\Exec\ProcessRunner;
use Phycode\Security\Permissions;
use Phycode\Security\Workspace;
use Phycode\Session\Transcript;
use Phycode\Support\Cancellation;
use Phycode\Tools\ChangeLog;
use Phycode\Tools\Delegator;
use Phycode\Tools\ToolContext;

final class Kit
{
    private const BASE = '/tmp/opencode';

    private static array $created = [];

    public static function folder(string $name): string
    {
        $path = self::BASE . '/phpunit-' . $name . '-' . bin2hex(random_bytes(4));
        mkdir($path, 0700, true);
        $path = realpath($path) ?: $path;
        if (self::$created === []) {
            register_shutdown_function([self::class, 'removeCreated']);
            if (function_exists('pcntl_signal')) {
                pcntl_async_signals(true);
                pcntl_signal(SIGTERM, [self::class, 'stopped']);
                pcntl_signal(SIGINT, [self::class, 'stopped']);
            }
        }
        self::$created[] = $path;

        return $path;
    }

    public static function write(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $content);
    }

    public static function agent(string $name = 'build', string $mode = 'primary', ?array $tools = null, array $denied = []): AgentDefinition
    {
        return new AgentDefinition(
            name: $name,
            description: "{$name} agent for tests",
            mode: $mode,
            tools: $tools,
            excludedTools: [],
            deniedCategories: $denied,
            profile: null,
            maxTurns: null,
            temperature: null,
            prompt: "You are the {$name} agent.",
            source: 'test',
            path: 'memory',
            warnings: [],
        );
    }

    public static function context(
        string $root,
        AgentDefinition $agent,
        RunPolicy $policy,
        RecordingUi $ui,
        ?Delegator $delegator = null,
        ?Cancellation $cancel = null,
        ?Limits $limits = null,
        ?string $model = null,
        ?string $thinking = null,
        ?string $profile = null,
    ): ToolContext {
        return new ToolContext(
            new Workspace($root),
            new Permissions($ui),
            new ProcessRunner(),
            $ui,
            Transcript::memory(),
            $limits ?? new Limits(),
            $agent,
            $policy,
            $cancel ?? new Cancellation(),
            $profile ?? 'test',
            $delegator,
            new ChangeLog(),
            new ProcessRunner(),
            model: $model,
            thinking: $thinking,
        );
    }

    public static function policy(string $mode = 'ask', ?array $allowed = null, array $denied = []): RunPolicy
    {
        return new RunPolicy($allowed, [], $denied, $mode, 0);
    }

    public static function builtinAgents(): string
    {
        return dirname(__DIR__, 2) . '/resources/agents';
    }

    public static function commandLines(): array
    {
        if (is_readable('/proc/self/cmdline')) {
            $lines = [];
            foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
                $lines[] = implode(' ', explode("\0", rtrim((string) @file_get_contents($file), "\0")));
            }

            return $lines;
        }
        exec('ps -ax -o command= 2>/dev/null', $lines);

        return $lines;
    }

    public static function processState(int $pid): ?string
    {
        if (is_readable('/proc/self/stat')) {
            $stat = @file_get_contents('/proc/' . $pid . '/stat');

            return $stat === false ? null : substr(trim(substr($stat, (int) strrpos($stat, ')') + 1)), 0, 1);
        }
        $state = trim((string) shell_exec('ps -o stat= -p ' . $pid . ' 2>/dev/null'));

        return $state === '' ? null : $state[0];
    }

    public static function sleeping(string $seconds): bool
    {
        foreach (self::commandLines() as $line) {
            if (preg_match('~^(?:\S*/)?sleep ' . preg_quote($seconds, '~') . '$~', trim($line)) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function removeCreated(): void
    {
        foreach (self::$created as $path) {
            try {
                self::removeTree($path);
            } catch (\UnexpectedValueException) {
            }
        }
        self::$created = [];
    }

    public static function stopped(int $signal): void
    {
        self::removeCreated();
        exit(128 + $signal);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
