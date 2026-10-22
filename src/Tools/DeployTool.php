<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Deploy\Deployments;
use Phycode\Deploy\DeployUnsupported;
use Phycode\Support\PhycodeException;

final class DeployTool extends BaseTool
{
    public function __construct(private readonly Deployments $deployments)
    {
    }

    public function name(): string
    {
        return 'deploy';
    }

    public function description(): string
    {
        $targets = [];
        foreach ($this->deployments->names() as $name) {
            $target = $this->deployments->target($name);
            $targets[] = "{$name} ({$target->kind}, {$target->environment})";
        }

        return 'Deploy the project to a target of the config. action: dry_run builds without publishing where the kind can, apply publishes, rollback goes back to the previous version (or to version). Targets: '
            . implode(', ', $targets) . '. Use deploy_info first to see the plan and the state. Needs user approval, always for production.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Exec;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target' => ['type' => 'string', 'enum' => $this->deployments->names(), 'description' => 'Target to deploy to'],
                'action' => ['type' => 'string', 'enum' => ['dry_run', 'apply', 'rollback'], 'description' => 'dry_run, apply or rollback'],
                'version' => ['type' => 'string', 'description' => 'rollback only: the version or deployment to go back to. Default: the previous one'],
            ],
            'required' => ['target', 'action'],
        ];
    }

    public function summarize(array $args): string
    {
        $target = is_string($args['target'] ?? null) ? $args['target'] : '?';
        $action = is_string($args['action'] ?? null) ? $args['action'] : '?';

        return $this->deployments->summary($target, $action, is_string($args['version'] ?? null) && $args['version'] !== '' ? $args['version'] : null);
    }

    public function forcesApproval(array $args): bool
    {
        $name = $args['target'] ?? null;

        return !is_string($name) || !$this->deployments->has($name) || $this->deployments->target($name)->forcesApproval();
    }

    public function validate(array $args, ToolContext $context): void
    {
        $name = Args::string($args, 'target');
        $action = Args::string($args, 'action');
        if (!in_array($action, Deployments::MUTATING, true)) {
            throw new PhycodeException('action must be dry_run, apply or rollback; deploy_info reads the plan, the status and the logs');
        }
        $version = Args::string($args, 'version', false);
        if ($version !== '' && $action !== 'rollback') {
            throw new PhycodeException('version is for rollback only');
        }
        $this->deployments->plan($name, $action, $version === '' ? null : $version);
        $missing = $this->deployments->missingSecrets($name);
        if ($missing !== []) {
            throw new PhycodeException('the target lacks ' . implode(', ', $missing) . ". The person stores one with: phycode deploy secret {$name} NAME. Nothing was run.");
        }
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $name = Args::string($args, 'target');
        $version = Args::string($args, 'version', false);
        try {
            $result = $this->deployments->run(
                $name,
                Args::string($args, 'action'),
                $version === '' ? null : $version,
                100,
                $context->cancel,
                max(2048, intdiv($context->limits->toolOutputBytes, 2)),
                static fn (string $step) => $context->ui->notice('info', "deploy {$name}: {$step}"),
            );
        } catch (DeployUnsupported $error) {
            return ToolResult::fail($error->getMessage());
        }
        $report = $result->report(false);
        if ($context->ui->interactive() && $this->deployments->offersRollBack($result)
            && $context->ui->ask("deploy {$name} failed after something was changed. Roll back now?", ['no', 'yes, roll back'], false) === 'yes, roll back') {
            $back = $this->deployments->run($name, 'rollback', null, 100, $context->cancel, max(2048, intdiv($context->limits->toolOutputBytes, 2)), static fn (string $step) => $context->ui->notice('info', "deploy {$name}: {$step}"));
            $report .= "\n\nthe person chose to roll back:\n" . $back->report(false);
        }

        return $result->ok ? ToolResult::ok($report, ['url' => $result->url]) : ToolResult::fail($report);
    }
}
