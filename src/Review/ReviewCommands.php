<?php

declare(strict_types=1);

namespace Phycode\Review;

use Phycode\Cli\Commands\CommandContext;
use Phycode\Cli\Commands\CommandProvider;
use Phycode\Cli\Kernel;
use Phycode\Session\Transcript;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use Phycode\Tools\ToolResult;

final class ReviewCommands implements CommandProvider
{
    private const BASE = '/^[A-Za-z0-9][A-Za-z0-9._\/@~^{}+-]{0,199}$/D';

    public function __construct(private readonly \Closure $delegate)
    {
    }

    public static function fromKernel(Kernel $kernel): self
    {
        return new self(static function (string $agent, string $task) use ($kernel): ToolResult {
            $primary = $kernel->primaryAgent(null);
            $context = $kernel->context(
                $primary,
                $kernel->policyFor($primary),
                Transcript::memory(),
                new Cancellation(),
                $kernel->config->defaultProfile,
                $kernel->delegatorFor(),
                $kernel->sandboxes->runnerFor($primary->sandbox),
            );

            return $kernel->delegation->delegate($agent, $task, $context);
        });
    }

    public function commands(): array
    {
        return [['name' => '/review [base-ref]', 'description' => 'review the changes with four reviewers, a refuter and the test command; /review mode on|off|status']];
    }

    public function handle(string $name, string $args, CommandContext $ctx): void
    {
        $settings = new ReviewSettings($ctx->config->configDir . '/config.json');
        try {
            if ($args === 'mode' || str_starts_with($args, 'mode ')) {
                $this->mode(trim(substr($args, 4)), $settings, $ctx);

                return;
            }
            if (!$settings->enabled()) {
                $ctx->notice('info', 'reviews are off (review.mode is disabled), so /review did nothing. /review mode on turns them on.');

                return;
            }
            if (!$ctx->config->subagentsEnabled) {
                throw new PhycodeException('subagents are switched off in the config, and the review runs on them');
            }
            if ($args !== '' && preg_match(self::BASE, $args) !== 1) {
                throw new PhycodeException("'{$args}' is not a base ref: use one word such as main or HEAD~2");
            }

            $runner = new ReviewRunner($this->delegate, $settings->testCommand($ctx->cwd));
            $report = $runner->run($ctx->cwd, $args === '' ? null : $args, static function (string $step, string $detail, bool $done) use ($ctx): void {
                $ctx->notice('info', "{$step}: {$detail}");
            });
            $this->show($report, $ctx);
        } catch (PhycodeException $error) {
            $ctx->notice('error', $error->getMessage());
        }
    }

    private function mode(string $value, ReviewSettings $settings, CommandContext $ctx): void
    {
        if ($value === 'on' || $value === 'off') {
            $settings->setMode($value === 'on');
            $ctx->notice('info', $value === 'on'
                ? 'reviews are on: /review runs four reviewers, a refuter and the test command on the changes'
                : 'reviews are off: /review does nothing until /review mode on');

            return;
        }
        if ($value !== '' && $value !== 'status') {
            throw new PhycodeException('usage: /review mode on|off|status');
        }
        $where = $settings->configured() ? "review.mode in {$settings->file}" : 'review.mode is not set, so the default applies';
        $ctx->notice('info', ($settings->enabled() ? 'reviews are on' : 'reviews are off') . " ({$where})");
    }

    private function show(array $report, CommandContext $ctx): void
    {
        if ($report['verdict'] === 'empty') {
            $ctx->notice('info', "nothing to review: no changes {$report['base']}");

            return;
        }

        $items = [];
        foreach ($report['findings'] as $finding) {
            $items[] = [
                'category' => match ($finding['severity']) {
                    'error' => 'Errors',
                    'warning' => 'Warnings',
                    default => 'Suggestions',
                },
                'status' => $finding['severity'] === 'error' ? 'error' : '',
                'title' => "{$finding['id']} " . self::place($finding['file'], $finding['line']) . " {$finding['title']}",
                'detail' => $finding['detail'] !== '' ? $finding['detail'] : $finding['evidence'],
                'badge' => $finding['lens'] . ($finding['check'] === 'unchecked' ? '' : ", {$finding['check']}"),
            ];
        }
        $ctx->listPanel('Review', $items, self::hint($report));

        if ($report['plan'] !== []) {
            $edits = [];
            foreach ($report['plan'] as $edit) {
                $edits[] = [
                    'category' => 'Proposed edits',
                    'title' => self::place($edit['file'], $edit['line']),
                    'detail' => $edit['reason'],
                    'badge' => $edit['finding'],
                ];
            }
            $ctx->listPanel('Correction plan', $edits, 'A proposal only: nothing was edited.');
        }

        if ($report['validator']['status'] === 'failed' && $report['validator']['output'] !== '') {
            $ctx->panel('Test output', $report['validator']['output']);
        }

        foreach ($report['lenses'] as $lens) {
            if ($lens['status'] !== 'completed') {
                $ctx->notice('warn', "lens {$lens['lens']} {$lens['status']}: " . ($lens['reason'] ?? 'no reason'));
            }
        }
        if ($report['refuter']['status'] === 'failed' || $report['refuter']['status'] === 'partial') {
            $ctx->notice('warn', 'refuter ' . $report['refuter']['status'] . ': ' . ($report['refuter']['reason'] ?? 'no reason') . '; the findings it did not check stand');
        }
        foreach ($report['notes'] as $note) {
            $ctx->notice('info', $note);
        }
    }

    private static function hint(array $report): string
    {
        $verdict = match ($report['verdict']) {
            'blocking' => 'blocking: ' . implode('; ', $report['gate']['reasons']),
            'incomplete' => 'incomplete: a lens or the refuter did not finish, which does not block by itself',
            default => 'pass: no error finding survived the refuter and the test command did not fail',
        };
        $validator = $report['validator']['status'] . ($report['validator']['command'] === null ? '' : " ({$report['validator']['command']})");

        return implode("\n", [
            "Verdict: {$verdict}.",
            "Base: {$report['base']}; files: " . count($report['files']) . '; refuted: ' . count($report['refuted']) . '; dropped as incomplete: ' . $report['dropped'] . '.',
            "Validator: {$validator}.",
        ]);
    }

    private static function place(string $file, ?int $line): string
    {
        return $line === null ? $file : "{$file}:{$line}";
    }
}
