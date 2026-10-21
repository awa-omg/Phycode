<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Exec\ProcessRunner;
use Phycode\Git\GhPolicy;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

abstract class GhTool extends BaseTool
{
    private const PASSED = ['GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN', 'GH_HOST'];

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
                    'description' => 'gh arguments. The first two items are the group and the command, for example ["pr", "view", "12", "--json", "title,state"] or ["pr", "create", "--title", "Fix the loader", "--body", "What and why"].',
                ],
            ],
            'required' => ['args'],
        ];
    }

    public function summarize(array $args): string
    {
        $items = is_array($args['args'] ?? null) ? array_filter($args['args'], 'is_string') : [];

        return Text::oneLine('gh ' . implode(' ', $items), 160);
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return file_exists($this->root . '/.git');
    }

    public function validate(array $args, ToolContext $context): void
    {
        $refusal = GhPolicy::refusal($this->mode(), Args::stringList($args, 'args'));
        if ($refusal !== null) {
            throw new PhycodeException("gh refused: {$refusal}");
        }
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $list = Args::stringList($args, 'args');
        $refusal = GhPolicy::refusal($this->mode(), $list);
        if ($refusal !== null) {
            return ToolResult::fail("gh refused: {$refusal}");
        }
        $gh = ProcessRunner::which('gh');
        if ($gh === null) {
            return ToolResult::fail('the GitHub CLI (gh) is not installed or not on the PATH; see https://cli.github.com');
        }

        $env = ['GH_PROMPT_DISABLED' => '1', 'GH_NO_UPDATE_NOTIFIER' => '1', 'GH_NO_EXTENSION_UPDATE_NOTIFIER' => '1', 'GH_PAGER' => 'cat', 'GH_SPINNER_DISABLED' => '1', 'NO_COLOR' => '1', 'CLICOLOR' => '0'];
        foreach (self::PASSED as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $env[$name] = $value;
            }
        }
        $result = $context->processes->run([$gh, ...$list], $this->root, $this->mode() === 'read' ? 60 : 90, 262144, $context->cancel, $env);
        $report = ProcessReport::toResult($result);
        if ($report->ok && $this->mode() === 'write') {
            $context->changes->add('gh ' . $list[0] . ' ' . $list[1]);
        }

        return $report;
    }
}
