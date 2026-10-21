<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Text;
use Phycode\Tools\ArgvRunner;
use Phycode\Ui\Ui;

final class Hooks
{
    public const EVENTS = ['PreToolUse', 'PostToolUse', 'UserPromptSubmit', 'Stop', 'SubagentStop'];
    public const TIMEOUT_SECONDS = 10;
    public const MAX_TIMEOUT_SECONDS = 600;
    private const BLOCKING = ['PreToolUse', 'UserPromptSubmit'];
    private const MAX_OUTPUT_BYTES = 16384;
    private const MAX_CONTEXT_BYTES = 10000;

    public function __construct(private readonly array $hooks = [], private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS)
    {
    }

    public function has(string $event): bool
    {
        return ($this->hooks[$event] ?? []) !== [];
    }

    public function run(string $event, array $payload, string $cwd, string $subject, Ui $ui): ?string
    {
        return $this->dispatch($event, $payload, $cwd, $subject, $ui)->block;
    }

    public function dispatch(string $event, array $payload, string $cwd, string $subject, Ui $ui): HookOutcome
    {
        $stdin = Json::encode($payload) . "\n";
        $outcome = new HookOutcome();
        foreach ($this->hooks[$event] ?? [] as $hook) {
            if (!self::matches($hook['match'], $subject)) {
                continue;
            }
            $outcome = $outcome->merge($this->runOne($event, $hook['command'], $hook['timeout'] ?? $this->timeoutSeconds, $stdin, $cwd, $ui));
            if ($outcome->block !== null) {
                return $outcome;
            }
        }

        return $outcome;
    }

    public static function matches(?string $match, string $subject): bool
    {
        if ($match === null || $match === '' || $match === '*') {
            return true;
        }
        foreach (preg_split('/[|,]/', $match) ?: [] as $glob) {
            if (trim($glob) !== '' && fnmatch(trim($glob), $subject)) {
                return true;
            }
        }

        return false;
    }

    public static function split(string $command): array
    {
        $words = [];
        $word = '';
        $inWord = false;
        $quote = null;
        $length = strlen($command);
        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];
            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                } else {
                    $word .= $char;
                }
                continue;
            }
            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                } elseif ($char === '\\' && $i + 1 < $length && ($command[$i + 1] === '"' || $command[$i + 1] === '\\')) {
                    $word .= $command[++$i];
                } else {
                    $word .= $char;
                }
                continue;
            }
            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                if ($inWord) {
                    $words[] = $word;
                    $word = '';
                    $inWord = false;
                }
                continue;
            }
            $inWord = true;
            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '\\' && $i + 1 < $length) {
                $word .= $command[++$i];
            } else {
                $word .= $char;
            }
        }
        if ($quote !== null) {
            throw new PhycodeException('the hook command has a quote that is not closed');
        }
        if ($inWord) {
            $words[] = $word;
        }
        if ($words === []) {
            throw new PhycodeException('the hook command is empty');
        }

        return $words;
    }

    private function runOne(string $event, string $command, int $timeout, string $stdin, string $cwd, Ui $ui): HookOutcome
    {
        try {
            $argv = self::split($command);
            $label = Text::oneLine(implode(' ', array_map('basename', $argv)), 80);
            $result = ArgvRunner::run($argv, $cwd, $timeout, self::MAX_OUTPUT_BYTES, $stdin);
        } catch (PhycodeException $error) {
            return self::failed($event, 'the hook could not run: ' . $error->getMessage(), $ui);
        }

        if ($result->interrupted === 'timeout') {
            return self::failed($event, "the hook {$label} timed out after {$timeout} seconds", $ui);
        }
        $stderr = Redaction::text(trim($result->stderr));
        if ($result->exitCode === 0) {
            return $this->fromStdout($event, trim($result->stdout), $ui);
        }
        if ($result->exitCode === 2) {
            if (in_array($event, self::BLOCKING, true)) {
                $reason = Text::oneLine($stderr, 300);

                return new HookOutcome($reason !== '' ? $reason : "the {$event} hook exited with code 2 and gave no reason");
            }

            return new HookOutcome(feedback: [$stderr !== '' ? Text::headTail($stderr, 4000) : "the {$event} hook exited with code 2 and gave no reason"]);
        }
        $ui->notice('warn', "{$event} hook {$label} exited with code {$result->exitCode}; ignored" . ($stderr === '' ? '' : ': ' . Text::oneLine($stderr, 300)));

        return new HookOutcome();
    }

    private function fromStdout(string $event, string $stdout, Ui $ui): HookOutcome
    {
        $json = $stdout === '' ? null : Json::object($stdout);
        if ($json === null) {
            return $event === 'UserPromptSubmit' && $stdout !== '' ? new HookOutcome(context: [self::clip($stdout)]) : new HookOutcome();
        }
        if (is_string($json['systemMessage'] ?? null) && $json['systemMessage'] !== '') {
            $ui->notice('warn', Text::oneLine($json['systemMessage'], 300));
        }
        $specific = is_array($json['hookSpecificOutput'] ?? null) ? $json['hookSpecificOutput'] : [];
        $context = is_string($specific['additionalContext'] ?? null) && trim($specific['additionalContext']) !== '' ? [self::clip($specific['additionalContext'])] : [];

        $reason = null;
        if (($json['decision'] ?? null) === 'block') {
            $reason = is_string($json['reason'] ?? null) && trim($json['reason']) !== '' ? trim($json['reason']) : "the {$event} hook blocked without a reason";
        } elseif ($event === 'PreToolUse' && ($specific['permissionDecision'] ?? null) === 'deny') {
            $why = $specific['permissionDecisionReason'] ?? null;
            $reason = is_string($why) && trim($why) !== '' ? trim($why) : 'the PreToolUse hook denied the call';
        }
        $updated = $event === 'PreToolUse' && is_array($specific['updatedInput'] ?? null) && !array_is_list($specific['updatedInput']) ? $specific['updatedInput'] : null;

        if ($reason === null) {
            return new HookOutcome(context: $context, updatedInput: $updated);
        }
        if (in_array($event, self::BLOCKING, true)) {
            return new HookOutcome(Text::oneLine($reason, 300), context: $context, updatedInput: $updated);
        }

        return new HookOutcome(feedback: [Text::headTail($reason, 4000)], context: $context);
    }

    private static function clip(string $text): string
    {
        return Text::headTail(trim($text), self::MAX_CONTEXT_BYTES);
    }

    private static function failed(string $event, string $why, Ui $ui): HookOutcome
    {
        if ($event === 'PreToolUse') {
            return new HookOutcome($why);
        }
        $ui->notice('warn', "{$event} hook ignored: {$why}");

        return new HookOutcome();
    }
}
