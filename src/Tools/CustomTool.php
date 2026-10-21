<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Exec\ProcessRunner;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class CustomTool extends BaseTool
{
    private const PREFIX = 'custom__';

    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $command,
        private readonly bool $stdin,
    ) {
    }

    public function name(): string
    {
        return self::PREFIX . $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Exec;
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => true];
    }

    public function summarize(array $args): string
    {
        $arguments = $args === [] ? '' : ' ' . Json::encode($args);

        return Text::oneLine($this->name() . ': ' . implode(' ', $this->command) . $arguments, 300);
    }

    public function validate(array $args, ToolContext $context): void
    {
        if (!$context->sandbox instanceof ProcessRunner) {
            throw new PhycodeException("{$this->name()} runs on the host, and this run uses a container sandbox, so it cannot run here");
        }
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $input = $this->stdin ? ($args === [] ? '{}' : Json::encode($args)) . "\n" : null;
        try {
            $result = ArgvRunner::run($this->command, $context->workspace->root, $context->limits->bashTimeout, $context->limits->toolOutputBytes, $input, $context->cancel);
        } catch (PhycodeException $error) {
            return ToolResult::fail($error->getMessage());
        }

        return ProcessReport::toResult($result);
    }
}
