<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Ssh\SshHosts;
use Phycode\Support\Files;
use Phycode\Support\PhycodeException;

final class SshCopyTool extends BaseTool
{
    private const MAX_BYTES = 8388608;

    public function __construct(private readonly SshHosts $hosts)
    {
    }

    public function name(): string
    {
        return 'ssh_copy';
    }

    public function description(): string
    {
        return 'Copy one file between the project and a host: direction put sends a file of the project to the host, get brings a file of the host into the project. At most 8 MB. Needs user approval.';
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
                'host' => ['type' => 'string', 'enum' => $this->hosts->names(), 'description' => 'Host to copy to or from'],
                'direction' => ['type' => 'string', 'enum' => ['put', 'get'], 'description' => 'put: project to host. get: host to project'],
                'local' => ['type' => 'string', 'description' => 'File path in the project'],
                'remote' => ['type' => 'string', 'description' => 'File path on the host'],
            ],
            'required' => ['host', 'direction', 'local', 'remote'],
        ];
    }

    public function summarize(array $args): string
    {
        $host = is_string($args['host'] ?? null) ? $args['host'] : '?';
        $local = is_string($args['local'] ?? null) ? $args['local'] : '?';
        $remote = is_string($args['remote'] ?? null) ? $args['remote'] : '?';

        return "ssh_copy {$host}: " . (($args['direction'] ?? null) === 'get' ? "get {$remote} -> {$local}" : "put {$local} -> {$remote}");
    }

    public function forcesApproval(array $args): bool
    {
        $host = $args['host'] ?? null;

        return !is_string($host) || !$this->hosts->has($host) || $this->hosts->host($host)->confirm === 'always';
    }

    public function validate(array $args, ToolContext $context): void
    {
        $this->hosts->host(Args::string($args, 'host'));
        $direction = Args::string($args, 'direction');
        if (!in_array($direction, ['put', 'get'], true)) {
            throw new PhycodeException('direction must be put or get');
        }
        $remote = Args::string($args, 'remote');
        if (trim($remote) === '' || strlen($remote) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $remote) === 1) {
            throw new PhycodeException('the remote path must be one line of text');
        }
        $local = Args::string($args, 'local');
        $direction === 'get' ? $context->workspace->resolveWritable($local) : $context->workspace->resolve($local);
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $name = Args::string($args, 'host');
        $direction = Args::string($args, 'direction');
        $remote = Args::string($args, 'remote');
        $local = Args::string($args, 'local');
        try {
            $runner = $this->hosts->runner($name);

            return $direction === 'put'
                ? $this->put($runner, $name, $context->workspace->resolve($local), $remote, $context)
                : $this->get($runner, $name, $local, $remote, $context);
        } catch (PhycodeException $error) {
            return ToolResult::fail($error->getMessage());
        }
    }

    private function put(\Phycode\Ssh\SshRunner $runner, string $name, string $file, string $remote, ToolContext $context): ToolResult
    {
        if (!is_file($file)) {
            return ToolResult::fail('not a file: ' . $context->workspace->relative($file));
        }
        $size = (int) filesize($file);
        if ($size > self::MAX_BYTES) {
            return ToolResult::fail('the file is larger than 8 MB');
        }
        $result = $runner->copy(true, $file, $remote, 120, 4096, $context->cancel);
        if (!$result->succeeded()) {
            return ProcessReport::toResult($result);
        }

        return ToolResult::ok("sent {$context->workspace->relative($file)} ({$size} bytes) to {$name}:{$remote}");
    }

    private function get(\Phycode\Ssh\SshRunner $runner, string $name, string $local, string $remote, ToolContext $context): ToolResult
    {
        $destination = $context->workspace->resolveWritable($local);
        if (is_dir($destination)) {
            return ToolResult::fail("{$local} is a folder");
        }
        $probe = $runner->run(['sh', '-c', 'wc -c < "$1"', 'sh', $remote], $context->workspace->root, 30, 256, $context->cancel);
        $size = (int) trim($probe->stdout);
        if (!$probe->succeeded() || trim($probe->stdout) === '' || $size < 0) {
            return ToolResult::fail("cannot read {$remote} on {$name}: " . trim($probe->stderr !== '' ? $probe->stderr : 'exit code ' . $probe->exitCode));
        }
        if ($size > self::MAX_BYTES) {
            return ToolResult::fail("{$remote} is {$size} bytes, more than the 8 MB this tool copies");
        }

        $folder = Files::runtimeFolder($context->workspace->root, 'tmp');
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            return ToolResult::fail('cannot create the folder for the download');
        }
        $temporary = $folder . '/ssh-' . bin2hex(random_bytes(6));
        try {
            $result = $runner->copy(false, $temporary, $remote, 120, 4096, $context->cancel);
            if (!$result->succeeded()) {
                return ProcessReport::toResult($result);
            }
            $content = file_get_contents($temporary);
            if ($content === false || strlen($content) > self::MAX_BYTES) {
                return ToolResult::fail('the downloaded file cannot be read, or is larger than 8 MB');
            }
        } finally {
            @unlink($temporary);
        }

        $relative = $context->workspace->relative($destination);
        $previous = is_file($destination) ? file_get_contents($destination) : null;
        if ($previous === false) {
            return ToolResult::fail("cannot read the current content of {$local}; nothing was written");
        }
        try {
            $context->checkpoints?->record($relative, $previous);
        } catch (PhycodeException $error) {
            return ToolResult::fail("cannot keep the content of {$local} for undo; nothing was written: " . $error->getMessage());
        }
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0775, true) && !is_dir(dirname($destination))) {
            return ToolResult::fail("cannot create the folder for {$local}");
        }
        Files::writeAtomic($destination, $content, $previous === null ? null : (int) fileperms($destination));
        $context->changes->add($relative);

        return ToolResult::ok(($previous === null ? 'created ' : 'overwrote ') . "{$relative} (" . strlen($content) . " bytes) from {$name}:{$remote}");
    }
}
