<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Exec\BackgroundJobs;
use Phycode\Support\PhycodeException;

final class BashTool extends BaseTool
{
    public function __construct(private readonly ?BackgroundJobs $jobs = null)
    {
    }

    public function name(): string
    {
        return 'bash';
    }

    public function description(): string
    {
        return 'Run a shell command in the project root. Returns the exit code and output. Needs user approval.'
            . ($this->jobs === null ? '' : ' For a server or a command that keeps running, set background=true; the job tool then reads and stops it.');
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
                'command' => ['type' => 'string', 'description' => 'Shell command to run'],
                'timeout' => ['type' => 'integer', 'description' => 'Seconds before the command is killed'],
            ] + ($this->jobs === null ? [] : [
                'background' => ['type' => 'boolean', 'description' => 'Start the command and return at once with a job number'],
            ]),
            'required' => ['command'],
        ];
    }

    public function summarize(array $args): string
    {
        return 'bash: ' . (is_string($args['command'] ?? null) ? $args['command'] : '?');
    }

    public function validate(array $args, ToolContext $context): void
    {
        $context->workspace->assertNotNamed(Args::string($args, 'command'));
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $command = Args::string($args, 'command');
        if ($this->jobs !== null && Args::bool($args, 'background', false)) {
            return $this->startJob($command, $context);
        }
        $timeout = Args::int($args, 'timeout', 1, $context->limits->maxBashTimeout, $context->limits->bashTimeout);

        $result = $context->sandbox->run(
            ['bash', '-c', $command],
            $context->workspace->root,
            $timeout,
            $context->limits->toolOutputBytes,
            $context->cancel,
        );

        return ProcessReport::toResult($result);
    }

    private function startJob(string $command, ToolContext $context): ToolResult
    {
        try {
            $job = $this->jobs->start($context->sandbox, ['bash', '-c', $command], $context->workspace->root, $command);
        } catch (PhycodeException $error) {
            return ToolResult::fail($error->getMessage());
        }

        return ToolResult::ok("started job {$job->id}. Read its output with job(id={$job->id}, wait=SECONDS); stop it with job(action=stop).", ['job' => $job->id]);
    }
}
