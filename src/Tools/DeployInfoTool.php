<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Deploy\Deployments;
use Phycode\Deploy\DeployUnsupported;
use Phycode\Support\PhycodeException;

final class DeployInfoTool extends BaseTool
{
    public function __construct(private readonly Deployments $deployments)
    {
    }

    public function name(): string
    {
        return 'deploy_info';
    }

    public function description(): string
    {
        return 'Look at a deploy target without changing it. action: plan shows the commands that apply would run, status shows the current deployments, logs shows recent log lines.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Read;
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return !$policy->denies(ToolCategory::Exec);
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target' => ['type' => 'string', 'enum' => $this->deployments->names(), 'description' => 'Target to look at'],
                'action' => ['type' => 'string', 'enum' => ['plan', 'status', 'logs'], 'description' => 'plan, status or logs'],
                'lines' => ['type' => 'integer', 'description' => 'logs only: how many lines, 1 to 500. Default 100'],
            ],
            'required' => ['target', 'action'],
        ];
    }

    public function summarize(array $args): string
    {
        return 'deploy_info ' . (is_string($args['target'] ?? null) ? $args['target'] : '?') . ': ' . (is_string($args['action'] ?? null) ? $args['action'] : '?');
    }

    public function validate(array $args, ToolContext $context): void
    {
        $this->deployments->target(Args::string($args, 'target'));
        if (!in_array(Args::string($args, 'action'), ['plan', 'status', 'logs'], true)) {
            throw new PhycodeException('action must be plan, status or logs; deploy changes things');
        }
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $name = Args::string($args, 'target');
        $action = Args::string($args, 'action');
        $lines = Args::int($args, 'lines', 1, 500, 100);
        try {
            if ($action === 'plan') {
                $plan = $this->deployments->plan($name, 'plan');
                $target = $this->deployments->target($name);
                $missing = $this->deployments->missingSecrets($name);
                $text = ["deploy {$name}: plan for apply ({$target->kind}, {$target->environment})"];
                foreach ($plan->commands() as $at => $command) {
                    $text[] = ($at + 1) . '. ' . $command;
                }
                foreach ($plan->notes as $note) {
                    $text[] = "note: {$note}";
                }
                if ($missing !== []) {
                    $text[] = 'missing: ' . implode(', ', $missing) . ' (the person stores one with: phycode deploy secret ' . $name . ' NAME)';
                }
                $text[] = $target->forcesApproval() ? 'apply asks the person every time.' : 'apply follows the permission mode and rules.';

                return ToolResult::ok(implode("\n", $text));
            }
            $result = $this->deployments->run($name, $action, null, $lines, $context->cancel, $context->limits->toolOutputBytes);
        } catch (DeployUnsupported $error) {
            return ToolResult::fail($error->getMessage());
        }
        $report = $result->report(true);

        return $result->ok ? ToolResult::ok($report) : ToolResult::fail($report);
    }
}
