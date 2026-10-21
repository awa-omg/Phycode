<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Git\GitPolicy;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

abstract class GitTool extends BaseTool
{
    private const PLAIN = [
        'diff' => ['--no-ext-diff', '--no-textconv'],
        'log' => ['--no-ext-diff', '--no-textconv'],
        'show' => ['--no-ext-diff', '--no-textconv'],
    ];

    public function __construct(private readonly string $root)
    {
    }

    abstract protected function mode(): string;

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'args' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'git arguments. The first item is the subcommand, for example ["status", "--short"] or ["commit", "-m", "message"].',
                ],
            ],
            'required' => ['args'],
        ];
    }

    public function summarize(array $args): string
    {
        $items = is_array($args['args'] ?? null) ? array_filter($args['args'], 'is_string') : [];

        return Text::oneLine('git ' . implode(' ', $items), 120);
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return file_exists($this->root . '/.git');
    }

    public function validate(array $args, ToolContext $context): void
    {
        $list = Args::stringList($args, 'args');
        $refusal = GitPolicy::refusal($this->mode(), $list);
        if ($refusal !== null) {
            throw new PhycodeException("git refused: {$refusal}");
        }
        $context->workspace->assertNotNamed(implode(' ', $list));
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $list = Args::stringList($args, 'args');
        $refusal = GitPolicy::refusal($this->mode(), $list);
        if ($refusal !== null) {
            return ToolResult::fail("git refused: {$refusal}");
        }

        $env = ['GIT_TERMINAL_PROMPT' => '0', 'GIT_EDITOR' => 'true', 'GIT_PAGER' => 'cat', 'PAGER' => 'cat'];
        $timeout = match ($this->mode()) {
            'read' => 30,
            'write' => 60,
            default => 120,
        };
        if ($this->mode() === 'remote') {
            $socket = getenv('SSH_AUTH_SOCK');
            if (is_string($socket) && $socket !== '') {
                $env['SSH_AUTH_SOCK'] = $socket;
            }
        }

        $result = $context->processes->run(
            ['git', '-C', $this->root, '--no-pager', $list[0], ...(self::PLAIN[$list[0]] ?? []), ...array_slice($list, 1)],
            $this->root,
            $timeout,
            262144,
            $context->cancel,
            $env,
        );
        $report = ProcessReport::toResult($result);
        if ($report->ok && $this->mode() !== 'read') {
            $context->changes->add('git ' . $list[0]);
        }

        return $report;
    }
}
