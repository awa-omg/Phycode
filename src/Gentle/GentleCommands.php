<?php

declare(strict_types=1);

namespace Phycode\Gentle;

use Phycode\Cli\Commands\CommandContext;
use Phycode\Cli\Commands\CommandProvider;
use Phycode\Exec\ProcessResult;
use Phycode\Exec\ProcessRunner;
use Phycode\Review\ReviewSettings;
use Phycode\Rpc\RpcUi;
use Phycode\Support\PhycodeException;

final class GentleCommands implements CommandProvider
{
    public function __construct(private readonly string $configFile)
    {
    }

    public function commands(): array
    {
        return [
            ['name' => '/gentle [status|install|sync|upgrade|uninstall|refresh|tui]', 'description' => 'show gentle-ai: version, pin, state, skills and review mode; or install, sync, upgrade, uninstall, refresh or open its TUI (each asks first)'],
            ['name' => '/mcp preset [engram|context7|codegraph]', 'description' => 'add a gentle-ai MCP server to the global config (asks first); without a name, list the presets'],
            ['name' => '/persona [gentleman|neutral|off]', 'description' => 'show the persona, or set it for the chat replies (asks first)'],
            ['name' => '/shell [status|install|uninstall]', 'description' => 'show, add or remove the PATH block that puts phycode first in the login shell (asks first)'],
            ['name' => '/odd FEATURE', 'description' => 'create odd/tasks/FEATURE.md from the ODD template (asks first)'],
        ];
    }

    public function handle(string $name, string $args, CommandContext $ctx): void
    {
        match ($name) {
            '/gentle' => $this->gentle($args, $ctx),
            '/mcp' => $this->mcp($args, $ctx),
            '/persona' => $this->persona($args, $ctx),
            '/shell' => $this->shell($args, $ctx),
            '/odd' => $this->odd($args, $ctx),
            default => throw new PhycodeException("no command named {$name}"),
        };
    }

    private function gentle(string $args, CommandContext $ctx): void
    {
        [$action, $words] = self::split($args);
        if ($action !== '' && $action !== 'status' && !in_array($action, GentleOperations::ACTIONS, true)) {
            $ctx->notice('error', 'usage: /gentle, /gentle install, sync, upgrade, uninstall, refresh or tui');

            return;
        }
        $status = (new GentleAi())->status($ctx->config->gentleAiBin(), $ctx->cwd);
        if ($action === '' || $action === 'status') {
            $ctx->panel('gentle', implode("\n", [...$this->phycodeLines(), $status->panel()]));

            return;
        }
        if ($status->bin === null) {
            $ctx->notice('error', GentleAi::NOT_INSTALLED);

            return;
        }
        $approval = $this->approval($ctx);
        $tokens = GentleOperations::tokens($words);
        if ($action === 'tui') {
            $this->tui($status, $tokens, $ctx, $approval);

            return;
        }
        $operation = GentleOperations::build($action, $status->bin, $ctx->cwd, $tokens, GentleAi::environment(), $status->versionNote());
        if (!$approval($operation->title, $operation->details(), "run {$operation->title}: the panel above has the exact argv and environment")) {
            $ctx->notice('info', 'not approved: nothing was run.');

            return;
        }
        $result = GentleOperations::run($operation, new ProcessRunner());
        $ctx->panel($operation->title . ($result->succeeded() ? ': finished' : ': failed'), self::outcome($result));
    }

    private function phycodeLines(): array
    {
        $review = new ReviewSettings($this->configFile);
        try {
            $mode = $review->enabled() ? 'enabled' : 'disabled';
            $origin = $review->configured() ? "review.mode in {$this->configFile}" : "review.mode is not set in {$this->configFile}, so the default applies";
            $reviewLine = "phycode review.mode: {$mode} ({$origin})";
        } catch (PhycodeException $error) {
            $reviewLine = "phycode review.mode: {$error->getMessage()}";
        }
        $persona = Persona::configured(new GlobalConfig($this->configFile));
        $origin = $persona === null ? "gentle_ai.persona is not set in {$this->configFile}" : "gentle_ai.persona in {$this->configFile}";

        return [$reviewLine, 'phycode persona: ' . ($persona ?? 'off') . " ({$origin})"];
    }

    private function tui(GentleStatus $status, array $tokens, CommandContext $ctx, Approval $approval): void
    {
        $operation = GentleOperations::build('tui', (string) $status->bin, $ctx->cwd, $tokens, GentleAi::environment(), $status->versionNote());
        $event = GentleOperations::execEvent($operation);
        if (!$ctx->ui instanceof RpcUi) {
            $ctx->notice('info', 'the gentle-ai TUI needs the phycode terminal UI, which hands over the terminal. In a terminal of your own, run: ' . GentleOperation::show($event['argv']));

            return;
        }
        if (!$approval($operation->title, $operation->details(), 'open the gentle-ai TUI: the panel above has the exact command')) {
            $ctx->notice('info', 'not approved: nothing was run.');

            return;
        }
        $ctx->ui->event($event);
        $ctx->notice('info', 'asked the terminal UI to open gentle-ai. It asks once more before it runs the program.');
    }

    private function mcp(string $args, CommandContext $ctx): void
    {
        [$word, $name] = self::split($args);
        if ($word === '' || ($word === 'preset' && $name === '')) {
            $ctx->panel('MCP presets (gentle-ai)', self::presets());

            return;
        }
        if ($word !== 'preset' || !in_array($name, McpPresets::NAMES, true)) {
            $ctx->notice('error', 'usage: /mcp preset engram, /mcp preset context7 or /mcp preset codegraph');

            return;
        }
        $ctx->notice('info', McpPresets::add(new GlobalConfig($this->configFile), $name, ProcessRunner::which('engram'), $this->approval($ctx)));
    }

    private function persona(string $args, CommandContext $ctx): void
    {
        $name = trim($args);
        $config = new GlobalConfig($this->configFile);
        if ($name === '') {
            $current = Persona::configured($config) ?? 'off';
            $ctx->panel('persona', "persona: {$current}\nchoices: " . implode(', ', Persona::NAMES) . ", off\nset one with /persona NAME; it asks first");

            return;
        }
        $ctx->notice('info', Persona::set($config, $name === 'off' ? null : $name, $this->approval($ctx)));
    }

    private function shell(string $args, CommandContext $ctx): void
    {
        $action = trim($args) === '' ? 'status' : trim($args);
        $shell = new ShellIntegration(
            home: (string) (getenv('HOME') ?: '/root'),
            binDirectory: ShellIntegration::defaultBinDirectory(),
            shell: self::environmentValue('SHELL'),
            zdotdir: self::environmentValue('ZDOTDIR'),
        );
        match ($action) {
            'status' => $ctx->panel('shell', $shell->status()),
            'install' => $ctx->notice('info', $shell->install($this->approval($ctx))),
            'uninstall' => $ctx->notice('info', $shell->uninstall($this->approval($ctx))),
            default => $ctx->notice('error', 'usage: /shell status, /shell install or /shell uninstall'),
        };
    }

    private function odd(string $args, CommandContext $ctx): void
    {
        $feature = trim($args);
        if ($feature === '') {
            $ctx->notice('error', 'usage: /odd FEATURE, such as /odd add-login');

            return;
        }
        $ctx->notice('info', OddTask::create($ctx->cwd, $feature, $this->approval($ctx)));
    }

    private function approval(CommandContext $ctx): Approval
    {
        return new Approval($ctx->ui, static function (string $title, string $text) use ($ctx): void {
            $ctx->panel($title, $text);
        });
    }

    private static function presets(): string
    {
        $lines = [];
        foreach (McpPresets::NAMES as $name) {
            $lines[] = sprintf('%-10s %s', $name, GentleOperation::show(McpPresets::command($name)));
        }

        return implode("\n", [...$lines, '', 'Add one with /mcp preset NAME. It asks first and writes one entry to the global config.']);
    }

    private static function split(string $args): array
    {
        $parts = preg_split('/\s+/', trim($args), 2) ?: [''];

        return [$parts[0], trim($parts[1] ?? '')];
    }

    private static function outcome(ProcessResult $result): string
    {
        if ($result->interrupted !== null) {
            return sprintf('stopped after %.0f s: the time limit was reached. gentle-ai may still be changing files; check them.', $result->seconds);
        }
        $text = trim(implode("\n", array_filter([$result->stdout, $result->stderr], static fn (string $part): bool => trim($part) !== '')));
        $lines = $text === '' ? [] : array_slice(explode("\n", $text), -40);

        return implode("\n", ["exit code {$result->exitCode}", ...$lines]);
    }

    private static function environmentValue(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
