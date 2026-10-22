<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Exec\BackgroundJobs;
use Phycode\Ssh\SshHost;
use Phycode\Ssh\SshHosts;
use Phycode\Support\PhycodeException;

final class SshTool extends BaseTool
{
    private const MAX_COMMAND_BYTES = 60000;

    public function __construct(private readonly SshHosts $hosts, private readonly ?BackgroundJobs $jobs = null)
    {
    }

    public function name(): string
    {
        return 'ssh';
    }

    public function description(): string
    {
        $hosts = [];
        foreach ($this->hosts->names() as $name) {
            $cwd = $this->hosts->host($name)->cwd;
            $hosts[] = $name . ($cwd === null ? '' : " (starts in {$cwd})");
        }

        return 'Run a shell command on a remote host over ssh. Hosts: ' . implode(', ', $hosts) . '. Returns the exit code and output. Needs user approval.'
            . ($this->jobs === null ? '' : ' For a command that keeps running, set background=true; the job tool then reads and stops it.');
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
                'host' => ['type' => 'string', 'enum' => $this->hosts->names(), 'description' => 'Host to run on'],
                'command' => ['type' => 'string', 'description' => 'Shell command to run on the host'],
                'cwd' => ['type' => 'string', 'description' => 'Directory on the host. Default: the one of the host'],
                'timeout' => ['type' => 'integer', 'description' => 'Seconds before the command is killed'],
            ] + ($this->jobs === null ? [] : [
                'background' => ['type' => 'boolean', 'description' => 'Start the command and return at once with a job number'],
            ]),
            'required' => ['host', 'command'],
        ];
    }

    public function summarize(array $args): string
    {
        $host = is_string($args['host'] ?? null) ? $args['host'] : '?';

        return 'ssh ' . $host . ': ' . (is_string($args['command'] ?? null) ? $args['command'] : '?');
    }

    public function hardLineFacts(array $args): ?array
    {
        $name = $args['host'] ?? null;

        return is_string($name) ? $this->hosts->hardLineFacts($name, is_string($args['cwd'] ?? null) ? $args['cwd'] : null) : null;
    }

    public function forcesApproval(array $args): bool
    {
        $host = $args['host'] ?? null;

        return !is_string($host) || !$this->hosts->has($host) || $this->hosts->host($host)->confirm === 'always';
    }

    public function validate(array $args, ToolContext $context): void
    {
        $this->hosts->host(Args::string($args, 'host'));
        $command = Args::string($args, 'command');
        if (trim($command) === '') {
            throw new PhycodeException('the command is empty');
        }
        if (strlen($command) > self::MAX_COMMAND_BYTES || str_contains($command, "\0")) {
            throw new PhycodeException('the command is longer than ' . intdiv(self::MAX_COMMAND_BYTES, 1000) . ' KB or holds a NUL byte; put a long script in a file and copy it with ssh_copy');
        }
        $cwd = Args::string($args, 'cwd', false);
        if ($cwd !== '') {
            SshHost::remotePath($cwd, 'cwd');
        }
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $name = Args::string($args, 'host');
        $command = Args::string($args, 'command');
        $cwd = Args::string($args, 'cwd', false);
        $argv = ['bash', '-c', $command];
        try {
            $runner = $this->hosts->runner($name);
            if ($cwd !== '') {
                $runner = $runner->inDirectory($cwd);
            }
            if ($this->jobs !== null && Args::bool($args, 'background', false)) {
                $job = $this->jobs->start($runner, $argv, $context->workspace->root, "ssh {$name}: {$command}");

                return ToolResult::ok("started job {$job->id} on {$name}. Read its output with job(id={$job->id}, wait=SECONDS); stop it with job(action=stop).", ['job' => $job->id]);
            }
            $timeout = Args::int($args, 'timeout', 1, $context->limits->maxBashTimeout, $context->limits->bashTimeout);
            $result = $runner->run($argv, $context->workspace->root, $timeout, $context->limits->toolOutputBytes, $context->cancel);
        } catch (PhycodeException $error) {
            return ToolResult::fail($error->getMessage());
        }

        $report = ProcessReport::toResult($result);
        if ($result->exitCode === 255 && $result->interrupted === null) {
            return ToolResult::fail($report->content . "\n[ssh itself failed (exit 255): check that the host key is trusted (phycode ssh trust {$name}), the key is loaded in ssh-agent or set as identity_file, and the address]", $report->meta);
        }

        return $report;
    }
}
