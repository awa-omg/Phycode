<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Exec\BackgroundJobs;
use Phycode\Support\PhycodeException;

final class JobTool extends BaseTool
{
    private const MAX_WAIT_SECONDS = 60;

    public function __construct(private readonly BackgroundJobs $jobs)
    {
    }

    public function name(): string
    {
        return 'job';
    }

    public function description(): string
    {
        return 'Read or stop a command that bash started with background=true. action: output (default; wait=N seconds for new output or the end), list, stop.';
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
                'action' => ['type' => 'string', 'enum' => ['output', 'list', 'stop'], 'description' => 'output (default), list or stop'],
                'id' => ['type' => 'integer', 'description' => 'Job number. Optional when only one job exists'],
                'wait' => ['type' => 'integer', 'description' => 'Seconds to wait for new output or for the job to end, at most ' . self::MAX_WAIT_SECONDS],
            ],
        ];
    }

    public function summarize(array $args): string
    {
        $action = is_string($args['action'] ?? null) ? $args['action'] : 'output';

        return 'job ' . $action . (isset($args['id']) && is_scalar($args['id']) ? ' ' . $args['id'] : '');
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $action = Args::string($args, 'action', false) ?: 'output';
        if (!in_array($action, ['output', 'list', 'stop'], true)) {
            return ToolResult::fail("unknown action '{$action}'; use output, list or stop");
        }
        if ($action === 'list') {
            $lines = array_map(static fn ($job): string => $job->line(), $this->jobs->all());

            return ToolResult::ok($lines === [] ? 'no jobs' : implode("\n", $lines));
        }

        try {
            $job = $this->pick($args);
        } catch (PhycodeException $error) {
            return ToolResult::fail($error->getMessage());
        }
        if ($action === 'stop') {
            $job->stop('by the agent');

            return ToolResult::ok($job->line());
        }

        $wait = Args::int($args, 'wait', 0, self::MAX_WAIT_SECONDS, 0);
        $limit = $context->limits->toolOutputBytes;
        $deadline = microtime(true) + $wait;
        while (true) {
            [$text, $omitted] = $job->read($limit);
            if ($text !== '' || !$job->running() || microtime(true) >= $deadline || $context->cancel->isCancelled()) {
                break;
            }
            usleep(100000);
        }
        if (!$job->running()) {
            [$more, $moreOmitted] = $job->read($limit);
            $text .= $more;
            $omitted += $moreOmitted;
        }

        $lines = [$job->line()];
        if ($omitted > 0) {
            $lines[] = "[{$omitted} bytes of earlier output left out]";
        }
        if (trim($text) !== '') {
            $lines[] = rtrim($text);
        } else {
            $lines[] = '[no new output]';
        }

        return ToolResult::ok(implode("\n", $lines));
    }

    private function pick(array $args): \Phycode\Exec\BackgroundJob
    {
        $all = $this->jobs->all();
        if (!isset($args['id'])) {
            if (count($all) === 1) {
                return $all[0];
            }

            throw new PhycodeException($all === [] ? 'no job is running; start one with bash and background=true' : 'say which job: id=N (action=list shows them)');
        }
        $id = Args::int($args, 'id', 1, PHP_INT_MAX, 1);
        $job = $this->jobs->get($id);
        if ($job === null) {
            throw new PhycodeException("no job {$id}; action=list shows the jobs");
        }

        return $job;
    }
}
