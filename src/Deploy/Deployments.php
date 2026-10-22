<?php

declare(strict_types=1);

namespace Phycode\Deploy;

use Phycode\Deploy\Drivers\Drivers;
use Phycode\Exec\ProcessResult;
use Phycode\Exec\ProcessRunner;
use Phycode\Security\HardLine;
use Phycode\Ssh\SshHosts;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Vault;

final class Deployments
{
    public const ACTIONS = ['plan', 'dry_run', 'apply', 'status', 'logs', 'rollback'];
    public const MUTATING = ['dry_run', 'apply', 'rollback'];
    private const VERSION = '~^[A-Za-z0-9][A-Za-z0-9._:/@-]{0,199}$~D';
    private const MAX_ARCHIVE_BYTES = 268435456;

    private readonly \Closure $probe;

    public function __construct(
        private readonly array $targets,
        private readonly Vault $vault,
        private readonly ProcessRunner $local,
        private readonly SshHosts $ssh,
        private readonly string $root,
        ?\Closure $probe = null,
        private readonly int $healthAttempts = 5,
        private readonly int $healthDelay = 3,
    ) {
        $this->probe = $probe ?? self::fetch(...);
    }

    public function isEmpty(): bool
    {
        return $this->targets === [];
    }

    public function names(): array
    {
        return array_keys($this->targets);
    }

    public function has(string $name): bool
    {
        return isset($this->targets[$name]);
    }

    public function target(string $name): DeployTarget
    {
        return $this->targets[$name] ?? throw new PhycodeException("no deploy target named '{$name}'. Targets: " . ($this->targets === [] ? 'none' : implode(', ', array_keys($this->targets))));
    }

    public function plan(string $name, string $action = 'apply', ?string $version = null, int $lines = 100): DeployPlan
    {
        $target = $this->target($name);
        if (!in_array($action, self::ACTIONS, true)) {
            throw new PhycodeException("unknown action '{$action}'. Actions: " . implode(', ', self::ACTIONS));
        }
        if ($version !== null && preg_match(self::VERSION, $version) !== 1) {
            throw new PhycodeException('the version must be a short id, a tag or a URL, not starting with a dash');
        }
        $effective = $action === 'plan' ? 'apply' : $action;
        $context = new DeployContext(
            $this->root,
            $this->directory($target),
            bin2hex(random_bytes(6)),
            $this->tag(),
            ProcessRunner::which(...),
            $this->ssh,
            $version,
            max(1, min(500, $lines)),
        );
        $plan = Drivers::for($target->kind)->plan($target, $effective, $context);

        return $action === 'plan' ? new DeployPlan($plan->target, 'plan', $plan->steps, $plan->notes, $plan->cleanup) : $plan;
    }

    public function summary(string $name, string $action, ?string $version = null): string
    {
        try {
            $target = $this->target($name);
            $plan = $this->plan($name, $action, $version);
        } catch (PhycodeException) {
            return "deploy {$name}: {$action}";
        }
        $steps = $plan->commands();
        $mutating = array_values(array_filter($plan->steps, static fn (DeployStep $step): bool => !$step->optional));
        $first = $mutating === [] ? ($steps[0] ?? '') : $mutating[array_key_last($mutating)]->display();

        return "deploy {$name}: {$action} [{$target->environment}] {$first}" . (count($steps) > 1 ? ' (' . count($steps) . ' steps)' : '');
    }

    public function secretValues(): array
    {
        $values = [];
        foreach ($this->targets as $target) {
            try {
                array_push($values, ...array_values($this->secrets($target)));
            } catch (PhycodeException) {
                continue;
            }
        }

        return $values;
    }

    public function missingSecrets(string $name): array
    {
        $target = $this->target($name);
        $missing = [];
        foreach ($target->secrets as $variable) {
            if ($this->secret($target, $variable) === null) {
                $missing[] = $variable;
            }
        }

        return $missing;
    }

    public function run(string $name, string $action, ?string $version, int $lines, Cancellation $cancel, int $maxBytes, ?\Closure $progress = null): DeployResult
    {
        $started = microtime(true);
        $target = $this->target($name);
        try {
            $plan = $this->plan($name, $action === 'plan' ? 'apply' : $action, $version, $lines);
        } catch (DeployUnsupported $error) {
            return new DeployResult(false, $name, $action, [], error: $error->getMessage(), seconds: microtime(true) - $started);
        }
        $missing = $this->missingSecrets($name);
        if ($missing !== []) {
            return new DeployResult(false, $name, $action, [], error: 'missing: ' . implode(', ', $missing) . ". Store a value with: phycode deploy secret {$name} NAME, or export it in the environment of phycode. Nothing was run.", seconds: microtime(true) - $started);
        }
        foreach ($plan->steps as $step) {
            if ($step->host === null && $step->type === DeployStep::RUN && !str_contains($step->argv[0], '/') && ProcessRunner::which($step->argv[0]) === null) {
                return new DeployResult(false, $name, $action, [], error: "{$step->argv[0]} was not found on PATH, so nothing was run. Install it, or set command in the target.", seconds: microtime(true) - $started);
            }
            $facts = $step->host === null ? null : $this->ssh->hardLineFacts($step->host, $step->directory);
            $hard = HardLine::reason("deploy: {$step->line()}", $step->host === null ? $this->directory($target) : ($facts['cwd'] ?? null), $facts);
            if ($hard !== null) {
                return new DeployResult(false, $name, $action, [], error: "refused: {$hard} is never run, in any mode and with any approval. Step '{$step->label}' was not run, and neither was any other.", seconds: microtime(true) - $started);
            }
        }

        $secrets = $this->secrets($target);
        $environment = [...$target->vars, ...$secrets];
        $values = array_values($secrets);
        $directory = $this->directory($target);
        $results = [];
        $failed = null;
        $urlText = '';
        try {
            foreach ($plan->steps as $at => $step) {
                if ($failed !== null || $cancel->isCancelled()) {
                    $results[] = ['label' => $step->label, 'command' => $step->display(), 'status' => 'skipped', 'seconds' => 0.0, 'output' => ''];
                    $failed ??= 'cancelled: ' . ($cancel->reason() ?? 'cancelled');
                    continue;
                }
                if ($progress !== null) {
                    $progress(($at + 1) . '/' . count($plan->steps) . ' ' . $step->label);
                }
                $begun = microtime(true);
                $result = $this->step($step, $target, $directory, $environment, $cancel, $maxBytes);
                $output = Redaction::text(rtrim($result->stdout) . (trim($result->stderr) === '' ? '' : "\n[stderr]\n" . rtrim($result->stderr)), $values);
                $ok = self::succeeded($step, $result);
                $status = $ok ? 'ok' : ($step->optional ? 'optional-failed' : 'failed');
                if ($ok && $action === 'apply') {
                    $urlText = Redaction::text($result->stdout, $values) . "\n" . $urlText;
                }
                $results[] = ['label' => $step->label, 'command' => $step->display(), 'status' => $status, 'seconds' => microtime(true) - $begun, 'output' => $output];
                if (!$ok && !$step->optional) {
                    $failed = $result->interrupted === 'cancelled' ? 'cancelled' : "step '{$step->label}' failed" . ($result->interrupted === 'timeout' ? ' (time limit)' : " (exit code {$result->exitCode})");
                }
            }
        } finally {
            foreach ($plan->cleanup as $file) {
                @unlink($file);
            }
        }

        $url = $failed === null && $action === 'apply' ? self::url($urlText) : null;
        $health = null;
        if ($failed === null && $action === 'apply' && $target->healthUrl !== null) {
            $health = $this->check($target->healthUrl, $cancel);
            if (!str_starts_with($health, 'ok')) {
                $failed = 'the deploy ran but the health check failed';
            }
        }
        if ($failed !== null && $action === 'apply' && in_array('ok', array_column($results, 'status'), true) && $this->canRollBack($name)) {
            $failed .= ". Something was already changed. To undo it, roll back: phycode deploy {$name} rollback";
        }
        $seconds = microtime(true) - $started;
        $result = new DeployResult($failed === null, $name, $action, $results, $url, $health, $plan->notes, $failed, $seconds);

        if (in_array($action, self::MUTATING, true)) {
            $this->remember($target, $action, $plan, $result);
        }

        return $result;
    }

    public function canRollBack(string $name): bool
    {
        try {
            return $this->plan($name, 'rollback')->steps !== [];
        } catch (DeployUnsupported) {
            return false;
        }
    }

    public function offersRollBack(DeployResult $result): bool
    {
        return !$result->ok && $result->action === 'apply' && in_array('ok', array_column($result->steps, 'status'), true) && $this->canRollBack($result->target);
    }

    public function history(?string $name = null, int $limit = 20): array
    {
        return (new DeployHistory($this->root))->entries($name, $limit);
    }

    private function step(DeployStep $step, DeployTarget $target, string $directory, array $environment, Cancellation $cancel, int $maxBytes): ProcessResult
    {
        $timeout = min($step->timeout ?? $target->timeout, $target->timeout);
        if ($step->host === null) {
            return $this->local->run($step->argv, $directory, $timeout, $maxBytes, $cancel, [...$environment, ...$step->env]);
        }
        $runner = $this->ssh->runner($step->host);
        if ($step->type === DeployStep::COPY) {
            if ((int) @filesize($step->argv[0]) > self::MAX_ARCHIVE_BYTES) {
                return new ProcessResult(1, '', 'the packed project is larger than ' . intdiv(self::MAX_ARCHIVE_BYTES, 1048576) . ' MB; exclude more files', null, 0.0);
            }

            return $runner->copy(true, $step->argv[0], $step->argv[1], $timeout, $maxBytes, $cancel);
        }
        if ($step->directory !== null) {
            $runner = $runner->inDirectory($step->directory);
        }

        return $runner->run($step->argv, $directory, $timeout, $maxBytes, $cancel);
    }

    private static function succeeded(DeployStep $step, ProcessResult $result): bool
    {
        if ($result->interrupted === 'cancelled') {
            return false;
        }
        if ($step->bounded) {
            return $result->exitCode === 0 || $result->interrupted === 'timeout' || in_array($result->exitCode, [124, 137, 143, -1], true);
        }

        return $result->succeeded();
    }

    private function secrets(DeployTarget $target): array
    {
        $values = [];
        foreach ($target->secrets as $variable) {
            $value = $this->secret($target, $variable);
            if ($value !== null) {
                $values[$variable] = $value;
            }
        }

        return $values;
    }

    private function secret(DeployTarget $target, string $variable): ?string
    {
        $stored = $this->vault->deploySecret($target->name, $variable);
        if ($stored !== null) {
            return $stored;
        }
        $env = getenv($variable);

        return is_string($env) && $env !== '' ? $env : null;
    }

    private function directory(DeployTarget $target): string
    {
        $root = realpath($this->root) ?: $this->root;
        $directory = realpath($root . '/' . $target->dir);
        if ($directory === false || !is_dir($directory) || ($directory !== $root && !str_starts_with($directory, $root . '/'))) {
            throw new DeployUnsupported("the folder '{$target->dir}' of {$target->name} does not exist inside the project");
        }

        return $directory;
    }

    private function tag(): string
    {
        $git = ProcessRunner::which('git');
        if ($git !== null && is_dir($this->root . '/.git')) {
            $result = $this->local->run([$git, 'rev-parse', '--short', 'HEAD'], $this->root, 10, 256);
            if ($result->succeeded() && preg_match('/^[0-9a-f]{4,40}$/', trim($result->stdout)) === 1) {
                return trim($result->stdout);
            }
        }

        return gmdate('YmdHis');
    }

    private static function url(string $text): ?string
    {
        if (preg_match_all('~https?://(?:\[redacted\]|[^\s"\'<>)\]])+~', $text, $found) < 1) {
            return null;
        }

        return rtrim($found[0][0], '.,;');
    }

    private function check(string $url, Cancellation $cancel): string
    {
        $last = 'no answer';
        for ($attempt = 1; $attempt <= $this->healthAttempts; $attempt++) {
            if ($cancel->isCancelled()) {
                return 'cancelled';
            }
            [$status, $error] = ($this->probe)($url);
            if ($status !== null && $status >= 200 && $status < 400) {
                return "ok: HTTP {$status} from {$url}";
            }
            $last = $status !== null ? "HTTP {$status}" : ($error ?? 'no answer');
            if ($attempt < $this->healthAttempts) {
                sleep($this->healthDelay);
            }
        }

        return "{$last} from {$url} after {$this->healthAttempts} tries";
    }

    private static function fetch(string $url): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_NOBODY => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RANGE => '0-0',
        ]);
        curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        return [$status > 0 ? $status : null, $error === '' ? null : $error];
    }

    private function remember(DeployTarget $target, string $action, DeployPlan $plan, DeployResult $result): void
    {
        try {
            (new DeployHistory($this->root))->append([
                'target' => $target->name,
                'kind' => $target->kind,
                'environment' => $target->environment,
                'action' => $action,
                'ok' => $result->ok,
                'commit' => $this->tag(),
                'commands' => $plan->commands(),
                'steps' => array_map(static fn (array $step): array => ['label' => $step['label'], 'status' => $step['status']], $result->steps),
                'url' => $result->url,
                'health' => $result->health,
                'seconds' => round($result->seconds, 1),
                'error' => $result->error,
            ]);
        } catch (PhycodeException) {
        }
    }
}
